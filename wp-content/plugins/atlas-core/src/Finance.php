<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
/** Created only by a trusted server adapter after independently verified refund outcome. */
final class VerifiedRefund {
    public readonly string $provider; public readonly string $reference; public readonly int $amount_irr;
    private function __construct(string $provider,string $reference,int $amount) { $this->provider=$provider; $this->reference=$reference; $this->amount_irr=$amount; }
    public static function from_adapter(string $provider,string $reference,int $amountIrr): self|\WP_Error {
        if (!preg_match('/^[a-z0-9_-]{2,64}$/',$provider) || $reference==='' || strlen($reference)>190 || $amountIrr<=0) { return new \WP_Error('atlas_invalid_refund_proof','Invalid refund evidence.',['status'=>422]); }
        return new self($provider,$reference,$amountIrr);
    }
}
final class Finance {
    private static function error(string $code,string $message,int $status=409): \WP_Error { return new \WP_Error($code,$message,['status'=>$status]); }
    private static function allowed(int $actor): bool { return Auth::actor($actor) && Auth::can('atlas_manage_finance'); }
    public static function request_refund(string $paymentId,int $amount,int $actor,string $key,string $reason): array|\WP_Error {
        global $wpdb;
        if (!self::allowed($actor)) { return self::error('atlas_forbidden','Finance authorization required.',403); }
        if ($amount<=0 || trim($reason)==='' || strlen($reason)>190 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key)) { return self::error('atlas_invalid_refund','Invalid refund request.',422); }
        $scope='user:'.$actor; $op='refund'; $hash=hash('sha256',wp_json_encode([$paymentId,$amount,$reason]));
        $wpdb->query('START TRANSACTION');
        try {
            $payment=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('payments').' WHERE id=%s FOR UPDATE',$paymentId));
            if (!$payment || $payment->status!=='verified') { $wpdb->query('ROLLBACK'); return self::error('atlas_payment_unavailable','Verified payment required.'); }
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$op,$key));
            if ($prior) { $wpdb->query('COMMIT'); return hash_equals($prior->request_hash,$hash) ? json_decode($prior->response,true) : self::error('atlas_idempotency_conflict','Idempotency key was reused.'); }
            $reserved=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount_irr),0) FROM '.DB::table('refunds')." WHERE payment_id=%s AND status IN ('requested','approved','submitted','succeeded')",$paymentId));
            if ($amount>(int)$payment->amount_irr-$reserved) { $wpdb->query('ROLLBACK'); return self::error('atlas_refund_exceeds_payment','Refund exceeds the unreserved payment amount.'); }
            $id=wp_generate_uuid4(); $now=DB::now(); $response=['refund_id'=>$id,'payment_id'=>$paymentId,'amount_irr'=>$amount,'currency'=>'IRR','status'=>'requested'];
            if (!$wpdb->insert(DB::table('refunds'),['id'=>$id,'payment_id'=>$paymentId,'booking_id'=>$payment->booking_id,'amount_irr'=>$amount,'status'=>'requested','requested_by'=>$actor,'reason'=>sanitize_text_field($reason),'provider'=>$payment->provider,'provider_ref'=>null,'created_at'=>$now,'updated_at'=>$now]) || !$wpdb->insert(DB::table('idempotency'),['scope'=>$scope,'operation'=>$op,'idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode($response),'created_at'=>$now]) || !DB::audit($actor,0,'refund.request',$id,'success') || !DB::emit('refund.requested',$id)) { throw new \RuntimeException('write'); }
            $wpdb->query('COMMIT'); return $response;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$op,$key));
            if ($prior) { return hash_equals($prior->request_hash,$hash) ? json_decode($prior->response,true) : self::error('atlas_idempotency_conflict','Idempotency key was reused.'); }
            return self::error('atlas_storage_error','Refund could not be safely recorded.',503);
        }
    }
    public static function approve(string $refundId,int $actor,string $reason): array|\WP_Error {
        global $wpdb;
        if (!self::allowed($actor) || trim($reason)==='') { return self::error('atlas_forbidden','Finance authorization and a reason are required.',403); }
        $wpdb->query('START TRANSACTION');
        try {
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('refunds').' WHERE id=%s FOR UPDATE',$refundId));
            if (!$row || $row->status!=='requested') { $wpdb->query('ROLLBACK'); return self::error('atlas_invalid_transition','Refund cannot be approved.'); }
            if ($wpdb->update(DB::table('refunds'),['status'=>'approved','updated_at'=>DB::now()],['id'=>$refundId,'status'=>'requested'])!==1 || !DB::audit($actor,0,'refund.approve',$refundId,'success',sanitize_text_field($reason)) || !DB::emit('refund.approved',$refundId)) { throw new \RuntimeException('write'); }
            $wpdb->query('COMMIT'); return ['refund_id'=>$refundId,'status'=>'approved'];
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('atlas_storage_error','Refund approval could not be saved.',503); }
    }
    public static function verify_success(string $refundId,VerifiedRefund $proof): array|\WP_Error {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('refunds').' WHERE id=%s FOR UPDATE',$refundId));
            if (!$row || $row->provider!==$proof->provider || (int)$row->amount_irr!==$proof->amount_irr) { throw new \RuntimeException('mismatch'); }
            if ($row->status==='succeeded' && $row->provider_ref===$proof->reference) { $wpdb->query('COMMIT'); return ['refund_id'=>$refundId,'status'=>'succeeded']; }
            if (!in_array($row->status,['approved','submitted'],true)) { throw new \RuntimeException('state'); }
            if ($wpdb->update(DB::table('refunds'),['status'=>'succeeded','provider_ref'=>$proof->reference,'updated_at'=>DB::now()],['id'=>$refundId])!==1 || !$wpdb->insert(DB::table('ledger'),['id'=>wp_generate_uuid4(),'payment_id'=>$row->payment_id,'refund_id'=>$refundId,'event_type'=>'refund_verified','amount_irr'=>-$proof->amount_irr,'currency'=>'IRR','created_at'=>DB::now()]) || !DB::audit(0,0,'refund.verify',$refundId,'success') || !DB::emit('refund.succeeded',$refundId)) { throw new \RuntimeException('write'); }
            $wpdb->query('COMMIT'); return ['refund_id'=>$refundId,'status'=>'succeeded'];
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('atlas_refund_verification_conflict','Refund could not be safely reconciled.'); }
    }
    public static function ledger(string $paymentId,int $actor): array|\WP_Error {
        global $wpdb;
        if (!self::allowed($actor)) { return self::error('atlas_forbidden','Finance authorization required.',403); }
        return $wpdb->get_results($wpdb->prepare('SELECT id,payment_id,refund_id,event_type,amount_irr,currency,created_at FROM '.DB::table('ledger').' WHERE payment_id=%s ORDER BY created_at,id',$paymentId),ARRAY_A);
    }
}
