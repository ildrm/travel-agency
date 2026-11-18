<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;
use Atlas\Core\Finance;

/** Restricted operational review; these controls never submit or verify a transfer. */
final class Atlas_Finance_UI {
    private static function allowed() { return Auth::agency() && current_user_can('atlas_manage_finance'); }
    private static function error($code,$message,$status=422) { return new WP_Error($code,__($message,'atlas-operations'),array('status'=>$status)); }
    private static function id($value) { return is_string($value) && (bool)preg_match('/^[a-f0-9-]{36}$/',$value); }
    private static function amount($value) {
        if (!is_int($value) && !is_string($value)) return null;
        $text=(string)$value; $max=(string)PHP_INT_MAX;
        if (!preg_match('/^[1-9][0-9]{0,18}$/',$text) || strlen($text)>strlen($max) || (strlen($text)===strlen($max) && strcmp($text,$max)>0)) return null;
        return (int)$text;
    }
    private static function t($en,$fa) { return isset($_GET['lang']) && $_GET['lang']==='fa' ? $fa : $en; }
    private static function format_amount($value) { return preg_replace('/\B(?=(\d{3})+(?!\d))/',',',(string)$value).' IRR'; }
    public static function register() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_post_atlas_refund_request',array(__CLASS__,'form_request'));
        add_action('admin_post_atlas_refund_approve',array(__CLASS__,'form_approve'));
    }
    public static function permission($request) {
        if (!self::allowed()) return self::error('atlas_forbidden','Agency finance access required.',403);
        if (!wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest')) return self::error('atlas_nonce_invalid','A valid security token is required.',403);
        return true;
    }
    public static function routes() {
        register_rest_route('agency/v1','/finance/payment-exceptions',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,'exceptions')));
        register_rest_route('agency/v1','/finance/payments/(?P<id>[a-f0-9-]{36})/ledger',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,'ledger')));
        register_rest_route('agency/v1','/finance/refunds',array('methods'=>'POST','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,'request_refund')));
        register_rest_route('agency/v1','/finance/refunds/(?P<id>[a-f0-9-]{36})/approve',array('methods'=>'POST','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,'approve')));
    }
    private static function response($data,$status=200) { $response=new WP_REST_Response($data,$status); $response->header('Cache-Control','private, no-store'); return $response; }
    public static function exceptions($request) {
        if (!self::allowed()) return self::error('atlas_forbidden','Agency finance access required.',403);
        $page=max(1,(int)($request->get_param('page')?:1)); $limit=(int)($request->get_param('limit')?:25);
        $rows=Finance::payment_exceptions(get_current_user_id(),$page,$limit); if (is_wp_error($rows)) return $rows;
        foreach ($rows as &$row) $row['amount_irr']=(string)$row['amount_irr']; unset($row);
        return self::response(array('items'=>$rows,'page'=>$page,'limit'=>$limit));
    }
    public static function ledger($request) {
        if (!self::allowed()) return self::error('atlas_forbidden','Agency finance access required.',403);
        $id=(string)$request['id']; if (!self::id($id)) return self::error('atlas_invalid_payment','A payment reference is required.');
        $page=max(1,(int)($request->get_param('page')?:1)); if ($page>100000) return self::error('atlas_invalid_request','Invalid page.');
        $rows=Finance::ledger($id,get_current_user_id()); if (is_wp_error($rows)) return $rows;
        return self::response(array('payment_id'=>$id,'items'=>array_slice($rows,($page-1)*25,25),'page'=>$page,'limit'=>25));
    }
    public static function request_refund($request) {
        if (!self::allowed()) return self::error('atlas_forbidden','Agency finance access required.',403);
        $body=$request->get_json_params();
        if (!is_array($body) || array_diff(array_keys($body),array('payment_id','amount_irr','reason')) || !self::id($body['payment_id']??null) || self::amount($body['amount_irr']??null)===null || !isset($body['reason']) || !is_string($body['reason']) || trim($body['reason'])==='' || strlen($body['reason'])>190) return self::error('atlas_invalid_refund','A payment, exact positive IRR amount and reason are required.');
        $result=Finance::request_refund($body['payment_id'],self::amount($body['amount_irr']),get_current_user_id(),(string)$request->get_header('Idempotency-Key'),sanitize_text_field($body['reason']));
        if (is_wp_error($result)) return $result;
        $result['amount_irr']=(string)$result['amount_irr'];
        // An idempotent replay returns the original request result, never a second refund.
        return self::response($result,200);
    }
    public static function approve($request) {
        global $wpdb;
        if (!self::allowed()) return self::error('atlas_forbidden','Agency finance access required.',403);
        $id=(string)$request['id'];$body=$request->get_json_params();$key=(string)$request->get_header('Idempotency-Key');
        if (!self::id($id) || !is_array($body) || array_diff(array_keys($body),array('reason')) || !isset($body['reason']) || !is_string($body['reason']) || trim($body['reason'])==='' || strlen($body['reason'])>190 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key)) return self::error('atlas_invalid_refund','A refund reference, reason and request key are required.');
        $actor=get_current_user_id();$reason=sanitize_text_field($body['reason']);$hash=hash('sha256',wp_json_encode(array($id,$reason)));$scope='user:'.$actor;
        // Serialize the request key across refund objects as well as each refund.
        // Otherwise a reused key could approve two objects before its mapping exists.
        $key_lock='atlas_refund_key_'.md5($wpdb->prefix.$scope.$key);
        $lock='atlas_refund_ui_'.md5($wpdb->prefix.$id);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key_lock))!=='1') return self::error('atlas_refund_busy','Refund review is in progress. Retry the same request.',409);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1') { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key_lock)); return self::error('atlas_refund_busy','Refund review is in progress. Retry the same request.',409); }
        try {
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,'refund.ui_approve',$key));
            if ($prior) return hash_equals($prior->request_hash,$hash)?self::response(json_decode($prior->response,true)):self::error('atlas_idempotency_conflict','The request key was reused.',409);
            $row=$wpdb->get_row($wpdb->prepare('SELECT status FROM '.DB::table('refunds').' WHERE id=%s',$id));
            if (!$row) return self::error('atlas_refund_unavailable','Refund unavailable.',404);
            if ($row->status==='approved') {
                // Recover the narrow crash window after Core committed approval but before
                // the UI request mapping was saved; do not invoke approval a second time.
                $evidence=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('audit').' WHERE action=%s AND object_id=%s AND actor_id=%d AND result=%s AND reason=%s LIMIT 1','refund.approve',$id,$actor,'success',$reason));
                if (!$evidence) return self::error('atlas_invalid_transition','Refund is already approved under a different review.',409);
                $result=array('refund_id'=>$id,'status'=>'approved');
            } else {
                $result=Finance::approve($id,$actor,$reason); if (is_wp_error($result)) return $result;
            }
            if (!$wpdb->insert(DB::table('idempotency'),array('scope'=>$scope,'operation'=>'refund.ui_approve','idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode($result),'created_at'=>DB::now()))) return self::error('atlas_review_record_pending','Approval was recorded; refresh its state before retrying.',503);
            return self::response($result);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key_lock)); }
    }
    private static function url($args=array()) { return add_query_arg(array_merge(array('atlas_area'=>'portal','lang'=>isset($_GET['lang'])&&$_GET['lang']==='fa'?'fa':'en'),$args),home_url('/')); }
    private static function form_url() { return add_query_arg('lang',isset($_GET['lang'])&&$_GET['lang']==='fa'?'fa':'en',admin_url('admin-post.php')); }
    public static function panel() {
        global $wpdb;if (!self::allowed()) return '';
        $page=min(100000,max(1,absint($_GET['atlas_fin_page']??1)));$rows=Finance::payment_exceptions(get_current_user_id(),$page,25);
        $html='<details data-atlas-finance><summary>'.esc_html(self::t('Payment exceptions and refund review','موارد نیازمند بررسی پرداخت و بازپرداخت')).'</summary><p>'.esc_html(self::t('A request or approval records a decision; it does not send funds or prove a completed refund. Provider processing and verification are separate.','درخواست یا تأیید فقط ثبت تصمیم است؛ انتقال وجه یا تکمیل بازپرداخت را ثابت نمی‌کند. پردازش و بررسی نتیجه ارائه‌دهنده جداست.')).'</p>';
        if (is_wp_error($rows)) return $html.'<p role="status">'.esc_html($rows->get_error_message()).'</p></details>';
        $html.='<table><thead><tr><th>'.esc_html(self::t('Payment reference','شناسه پرداخت')).'</th><th>'.esc_html(self::t('Amount','مبلغ')).'</th><th>'.esc_html(self::t('Payment state / exception','وضعیت پرداخت / مورد بررسی')).'</th><th>'.esc_html(self::t('Review','بررسی')).'</th></tr></thead><tbody>';
        foreach ($rows as $row) $html.='<tr data-atlas-payment-id="'.esc_attr($row['payment_id']).'"><td><bdi dir="ltr">'.esc_html($row['payment_id']).'</bdi></td><td><bdi dir="ltr">'.esc_html(self::format_amount($row['amount_irr'])).'</bdi></td><td>'.esc_html($row['status'].' / '.$row['exception_code']).'</td><td><a href="'.esc_url(self::url(array('atlas_fin_page'=>$page,'atlas_payment'=>$row['payment_id']))).'">'.esc_html(self::t('Review ledger and requests','بررسی دفتر و درخواست‌ها')).'</a></td></tr>';
        $html.='</tbody></table><nav aria-label="'.esc_attr(self::t('Payment exception pages','صفحات بررسی پرداخت')).'">';
        if ($page>1) $html.='<a data-finance-prev href="'.esc_url(self::url(array('atlas_fin_page'=>$page-1))).'">'.esc_html(self::t('Previous','قبل')).'</a> ';
        $html.='<span>'.esc_html(self::t('Page ','صفحه ').$page).'</span>';
        if (count($rows)===25) $html.=' <a data-finance-next href="'.esc_url(self::url(array('atlas_fin_page'=>$page+1))).'">'.esc_html(self::t('Next','بعد')).'</a>';
        $html.='</nav>';
        $payment=isset($_GET['atlas_payment'])&&is_string($_GET['atlas_payment'])?sanitize_text_field(wp_unslash($_GET['atlas_payment'])):'';
        if (self::id($payment)) {
            $ledger=Finance::ledger($payment,get_current_user_id());
            $html.='<h3>'.esc_html(self::t('Operational ledger — not a transfer instruction','دفتر عملیاتی — بدون دستور انتقال')).'</h3><p><bdi dir="ltr">'.esc_html($payment).'</bdi></p>';
            if (is_wp_error($ledger)) $html.='<p>'.esc_html($ledger->get_error_message()).'</p>';
            else {
                $lp=min(100000,max(1,absint($_GET['atlas_ledger_page']??1)));$display=array_slice($ledger,($lp-1)*25,25);$html.='<ul>';
                foreach ($display as $entry) $html.='<li>'.esc_html($entry['event_type']).' — <bdi dir="ltr">'.esc_html(self::format_amount($entry['amount_irr'])).'</bdi> — '.esc_html($entry['created_at']).'</li>';
                $html.='</ul><nav aria-label="'.esc_attr(self::t('Ledger pages','صفحات دفتر')).'">';
                if ($lp>1) $html.='<a href="'.esc_url(self::url(array('atlas_payment'=>$payment,'atlas_ledger_page'=>$lp-1))).'">'.esc_html(self::t('Previous','قبل')).'</a> ';
                if (count($ledger)>$lp*25) $html.='<a href="'.esc_url(self::url(array('atlas_payment'=>$payment,'atlas_ledger_page'=>$lp+1))).'">'.esc_html(self::t('Next','بعد')).'</a>';
                $html.='</nav>';
            }
            $rp=min(100000,max(1,absint($_GET['atlas_refund_page']??1)));
            $refunds=$wpdb->get_results($wpdb->prepare('SELECT id,amount_irr,status FROM '.DB::table('refunds').' WHERE payment_id=%s ORDER BY created_at,id LIMIT 25 OFFSET %d',$payment,($rp-1)*25));
            if (!DB::audit(get_current_user_id(),0,'refund.queue_read',$payment,'read')) return $html.'<p>'.esc_html(self::t('Refund records could not be safely read.','خواندن امن درخواست‌ها ممکن نشد.')).'</p></details>';
            $html.='<h3>'.esc_html(self::t('Recorded refund requests','درخواست‌های بازپرداخت ثبت‌شده')).'</h3><ul>';
            foreach ($refunds as $refund) {
                $html.='<li><bdi dir="ltr">'.esc_html($refund->id).'</bdi> — <bdi dir="ltr">'.esc_html(self::format_amount($refund->amount_irr)).'</bdi> — '.esc_html($refund->status);
                if ($refund->status==='requested') $html.='<form method="post" action="'.esc_url(self::form_url()).'">'.wp_nonce_field('atlas_refund_approve','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_refund_approve"><input type="hidden" name="refund_id" value="'.esc_attr($refund->id).'"><input type="hidden" name="payment_id" value="'.esc_attr($payment).'"><input type="hidden" name="idempotency_key" value="'.esc_attr(wp_generate_uuid4()).'"><label>'.esc_html(self::t('Approval reason','دلیل تأیید')).'<input name="reason" maxlength="190" required></label><button>'.esc_html(self::t('Approve for processing — no transfer','تأیید برای پردازش — بدون انتقال وجه')).'</button></form>';
                $html.='</li>';
            }
            $html.='</ul><nav aria-label="'.esc_attr(self::t('Refund request pages','صفحات درخواست بازپرداخت')).'">';
            if ($rp>1) $html.='<a href="'.esc_url(self::url(array('atlas_payment'=>$payment,'atlas_refund_page'=>$rp-1))).'">'.esc_html(self::t('Previous','قبل')).'</a> ';
            if (count($refunds)===25) $html.='<a href="'.esc_url(self::url(array('atlas_payment'=>$payment,'atlas_refund_page'=>$rp+1))).'">'.esc_html(self::t('Next','بعد')).'</a>';
            $html.='</nav>';
        }
        $html.='<form method="post" action="'.esc_url(self::form_url()).'">'.wp_nonce_field('atlas_refund_request','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_refund_request"><input type="hidden" name="idempotency_key" value="'.esc_attr(wp_generate_uuid4()).'"><label>'.esc_html(self::t('Verified payment reference','شناسه پرداخت تأییدشده')).'<input name="payment_id" value="'.esc_attr($payment).'" required pattern="[a-f0-9-]{36}"></label><label>'.esc_html(self::t('Exact integer IRR amount','مبلغ دقیق صحیح به ریال')).'<input name="amount_irr" inputmode="numeric" required pattern="[1-9][0-9]{0,18}"></label><label>'.esc_html(self::t('Request reason','دلیل درخواست')).'<input name="reason" maxlength="190" required></label><button>'.esc_html(self::t('Record refund request — no transfer','ثبت درخواست بازپرداخت — بدون انتقال وجه')).'</button></form>';
        return $html.'</details>';
    }
    private static function form_request_object($action) {
        $r=new WP_REST_Request('POST','/');$r->set_header('Content-Type','application/json');$r->set_header('Idempotency-Key',sanitize_text_field(wp_unslash($_POST['idempotency_key']??'')));
        if ($action==='approve') { $r->set_url_params(array('id'=>sanitize_text_field(wp_unslash($_POST['refund_id']??''))));$r->set_body(wp_json_encode(array('reason'=>sanitize_text_field(wp_unslash($_POST['reason']??''))))); }
        else $r->set_body(wp_json_encode(array('payment_id'=>sanitize_text_field(wp_unslash($_POST['payment_id']??'')),'amount_irr'=>wp_unslash($_POST['amount_irr']??''),'reason'=>sanitize_text_field(wp_unslash($_POST['reason']??'')))));
        return $r;
    }
    public static function form_request() {
        if (!self::allowed()) wp_die(esc_html__('Agency finance access required.','atlas-operations'),'',array('response'=>403));check_admin_referer('atlas_refund_request');$result=self::request_refund(self::form_request_object('request'));
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422));$payment=$result->get_data()['payment_id'];wp_safe_redirect(self::url(array('atlas_payment'=>$payment)));exit;
    }
    public static function form_approve() {
        if (!self::allowed()) wp_die(esc_html__('Agency finance access required.','atlas-operations'),'',array('response'=>403));check_admin_referer('atlas_refund_approve');$result=self::approve(self::form_request_object('approve'));
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422));$payment=sanitize_text_field(wp_unslash($_POST['payment_id']??''));wp_safe_redirect(self::url(self::id($payment)?array('atlas_payment'=>$payment):array()));exit;
    }
}
