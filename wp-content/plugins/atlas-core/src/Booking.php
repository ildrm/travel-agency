<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
/** Server-only result created after an adapter independently verifies a payment. */
final class VerifiedPayment {
    public readonly string $provider;
    public readonly string $reference;
    public readonly int $amount_irr;
    public readonly string $verified_at;
    public readonly ?string $paid_at;
    private function __construct(string $provider,string $reference,int $amount,?string $paidAt) {
        $this->provider=$provider; $this->reference=$reference; $this->amount_irr=$amount; $this->verified_at=DB::now(); $this->paid_at=$paidAt;
    }
    public static function from_adapter(string $provider, string $reference, int $amountIrr, ?string $trustedPaidAt=null): self|\WP_Error {
        if (!preg_match('/^[a-z0-9_-]{2,64}$/',$provider) || strlen($reference)<1 || strlen($reference)>190 || $amountIrr<0) { return new \WP_Error('atlas_invalid_proof','Invalid payment verification evidence.'); }
        $paid=null;
        if ($trustedPaidAt!==null) { try { if (!preg_match('/(?:Z|[+-]00:00)$/',$trustedPaidAt)) { throw new \RuntimeException(); } $paid=(new \DateTimeImmutable($trustedPaidAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); } catch (\Throwable $e) { return new \WP_Error('atlas_invalid_proof','Invalid provider payment time.'); } }
        return new self($provider,$reference,$amountIrr,$paid);
    }
}
final class Booking {
    private static function error(string $code, string $message, int $status=409): \WP_Error { return new \WP_Error($code,$message,['status'=>$status]); }
    private static function iso(string $value): string { return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
    public static function hold(int $offerId,int $offerVersion,int $qty,int $userId,string $key,array $eligibility=[]): array|\WP_Error {
        global $wpdb;
        if (!Auth::actor($userId)) { return self::error('atlas_forbidden','Sign in to reserve.',403); }
        if ($offerId<1 || $offerVersion<1 || $qty<1 || $qty>100 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key)) { return self::error('atlas_invalid_request','Invalid hold request.',422); }
        $hash=hash('sha256',wp_json_encode([$offerId,$offerVersion,$qty,$eligibility])); $scope='user:'.$userId;
        $existing=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,'hold',$key));
        if ($existing) { return hash_equals($existing->request_hash,$hash) ? json_decode($existing->response,true) : self::error('atlas_idempotency_conflict','Idempotency key was reused for another request.'); }
        $wpdb->query('START TRANSACTION');
        try {
            $inv=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',$offerId));
            $d=$inv ? json_decode($inv->offer_snapshot,true) : null; $now=DB::now();
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,'hold',$key));
            if ($prior) { $wpdb->query('COMMIT'); return hash_equals($prior->request_hash,$hash) ? json_decode($prior->response,true) : self::error('atlas_idempotency_conflict','Idempotency key was reused for another request.'); }
            if (!$inv || !is_array($d) || $d['status']!=='published' || $d['mode']!=='local') { $wpdb->query('ROLLBACK'); return self::error('atlas_unavailable','Offer unavailable.'); }
            if ($d['kind']==='cohort' && $qty!==1) { $wpdb->query('ROLLBACK'); return self::error('atlas_group_enrollment_unavailable','Group enrollment is not available yet.',422); }
            if ($d['kind']==='tour' && apply_filters('atlas_core_validate_tour_hold', false, $d, $offerId)!==true) { $wpdb->query('ROLLBACK'); return self::error('atlas_tour_review_required','An approved itinerary is required.'); }
            if ($d['kind']==='cohort' && apply_filters('atlas_core_validate_cohort_hold', false, $d, $offerId)!==true) { $wpdb->query('ROLLBACK'); return self::error('atlas_cohort_review_required','An approved course and cohort are required.'); }
            if ((int)$d['version']!==$offerVersion) { $wpdb->query('ROLLBACK'); return self::error('atlas_quote_changed','Offer changed; review the new terms.'); }
            $open=(new \DateTimeImmutable($d['registration_open_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); $close=(new \DateTimeImmutable($d['registration_close_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            if ($now<$open || $now >= $close) { $wpdb->query('ROLLBACK'); return self::error('registration_closed','Registration is closed.'); }
            $eligible=Eligibility::validate($d,$userId,$qty,$eligibility);
            if (is_wp_error($eligible)) { $wpdb->query('ROLLBACK'); return $eligible; }
            if ((int)$inv->capacity-(int)$inv->allocated < $qty) { $wpdb->query('ROLLBACK'); return self::error('atlas_sold_out','Not enough seats are available.'); }
            $unit=(int)$d['amount_irr']; if ($unit>intdiv(PHP_INT_MAX,$qty)) { $wpdb->query('ROLLBACK'); return self::error('atlas_invalid_amount','Amount out of range.',422); }
            $tenMinutes=(new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('+600 seconds')->format('Y-m-d H:i:s.u'); $deadline=min($tenMinutes,$close); $hold=wp_generate_uuid4(); $booking=wp_generate_uuid4();
            $quote=['amount'=>$unit*$qty,'currency'=>'IRR','unit'=>'rial','offer_version'=>$offerVersion];
            $response=['hold_id'=>$hold,'booking_id'=>$booking,'accepted_at'=>self::iso($now),'expires_at'=>self::iso($deadline),'payment_deadline'=>self::iso($deadline),'quote'=>$quote];
            if ($wpdb->update(DB::table('inventory'),['allocated'=>(int)$inv->allocated+$qty],['offer_id'=>$offerId])!==1) { throw new \RuntimeException('inventory'); }
            if (!$wpdb->insert(DB::table('holds'),['id'=>$hold,'booking_id'=>$booking,'offer_id'=>$offerId,'user_id'=>$userId,'quantity'=>$qty,'accepted_at'=>$now,'expires_at'=>$deadline,'state'=>'active'])) { throw new \RuntimeException('hold'); }
            if (!$wpdb->insert(DB::table('bookings'),['id'=>$booking,'hold_id'=>$hold,'offer_id'=>$offerId,'user_id'=>$userId,'status'=>'held','quantity'=>$qty,'amount_irr'=>$unit*$qty,'offer_version'=>$offerVersion,'snapshot'=>wp_json_encode($d),'participants'=>wp_json_encode($eligibility),'order_id'=>0,'created_at'=>$now,'updated_at'=>$now])) { throw new \RuntimeException('booking'); }
            if (!$wpdb->insert(DB::table('idempotency'),['scope'=>$scope,'operation'=>'hold','idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode($response),'created_at'=>$now])) { throw new \RuntimeException('idempotency'); }
            if (!DB::audit($userId,(int)$d['org_id'],'hold.create',$booking,'success') || !DB::emit('booking.held',$booking)) { throw new \RuntimeException('events'); }
            $wpdb->query('COMMIT'); return $response;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,'hold',$key));
            if ($prior) { return hash_equals($prior->request_hash,$hash) ? json_decode($prior->response,true) : self::error('atlas_idempotency_conflict','Idempotency key was reused for another request.'); }
            return self::error('atlas_storage_error','Could not create reservation.',503);
        }
    }
    public static function get(string $bookingId,int $userId): array|\WP_Error {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s',$bookingId),ARRAY_A);
        if (!$row || !Auth::actor($userId) || ((int)$row['user_id']!==$userId && !Auth::agency())) { return self::error('atlas_not_found','Booking not found.',404); }
        unset($row['snapshot'], $row['participants']);
        foreach (['offer_id','user_id','quantity','amount_irr','offer_version','order_id'] as $k) { $row[$k]=(int)$row[$k]; }
        // Monetary verification is its own persisted fact, independent of service
        // confirmation or a Woo order status. Do not expose provider references.
        $verified=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('payments').' WHERE booking_id=%s AND status=%s LIMIT 1',$bookingId,'verified'));
        $row['payment_status']=$verified ? 'verified' : 'unverified';
        return $row;
    }
    public static function expire(string $holdId): array|\WP_Error {
        global $wpdb;
        $reference=$wpdb->get_row($wpdb->prepare('SELECT offer_id FROM '.DB::table('holds').' WHERE id=%s',$holdId));
        if (!$reference) { return self::error('atlas_not_found','Hold not found.',404); }
        $wpdb->query('START TRANSACTION');
        try {
            $inv=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',$reference->offer_id));
            $hold=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('holds').' WHERE id=%s FOR UPDATE',$holdId));
            if (!$inv || !$hold) { throw new \RuntimeException('missing'); }
            if ($hold->state!=='active') { $wpdb->query('COMMIT'); return ['hold_id'=>$holdId,'state'=>$hold->state]; }
            if (DB::now() < $hold->expires_at) { $wpdb->query('ROLLBACK'); return self::error('atlas_not_expired','Hold deadline has not passed.'); }
            if ((int)$inv->allocated < (int)$hold->quantity) { throw new \RuntimeException('invariant'); }
            if ($wpdb->update(DB::table('inventory'),['allocated'=>(int)$inv->allocated-(int)$hold->quantity],['offer_id'=>$hold->offer_id])!==1 || $wpdb->update(DB::table('holds'),['state'=>'released'],['id'=>$holdId,'state'=>'active'])!==1) { throw new \RuntimeException('release'); }
            $booking=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s FOR UPDATE',$hold->booking_id));
            if ($booking && in_array($booking->status,['held','pending_payment'],true)) { $wpdb->update(DB::table('bookings'),['status'=>'expired','updated_at'=>DB::now()],['id'=>$booking->id]); }
            if (!DB::audit(0,0,'hold.expire',$holdId,'success') || !DB::emit('hold.released',$holdId)) { throw new \RuntimeException('event'); }
            $wpdb->query('COMMIT'); return ['hold_id'=>$holdId,'state'=>'released'];
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('atlas_storage_error','Could not release hold.',503); }
    }
    public static function verify(string $bookingId, VerifiedPayment $proof): array|\WP_Error {
        global $wpdb;
        $ref=$wpdb->get_row($wpdb->prepare('SELECT hold_id,offer_id FROM '.DB::table('bookings').' WHERE id=%s',$bookingId));
        if (!$ref) { return self::error('atlas_not_found','Booking not found.',404); }
        $wpdb->query('START TRANSACTION');
        try {
            $inv=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',$ref->offer_id));
            $hold=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('holds').' WHERE id=%s FOR UPDATE',$ref->hold_id));
            $booking=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s FOR UPDATE',$bookingId));
            if (!$inv || !$hold || !$booking || (int)$booking->amount_irr!==$proof->amount_irr) { throw new \RuntimeException('mismatch'); }
            $previous=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('payments').' WHERE provider=%s AND provider_ref=%s FOR UPDATE',$proof->provider,$proof->reference));
            if ($previous) { if ($previous->booking_id!==$bookingId || (int)$previous->amount_irr!==$proof->amount_irr) { throw new \RuntimeException('reference'); } $wpdb->query('COMMIT'); return ['booking_id'=>$bookingId,'status'=>$booking->status,'payment_status'=>'verified','payment_id'=>$previous->id,'payment_exception'=>$previous->exception_code]; }
            $alreadyPaid=(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('payments').' WHERE booking_id=%s AND status=%s LIMIT 1',$bookingId,'verified'));
            $protected=in_array($booking->status,['paid_pending_confirmation','confirmed','fulfilled','canceled'],true);
            $excess=$alreadyPaid || $protected;
            $timely=$proof->verified_at <= $hold->expires_at || ($proof->paid_at!==null && $proof->paid_at <= $hold->expires_at);
            $eligible=!$excess && $hold->state==='active' && $timely && in_array($booking->status,['held','pending_payment'],true);
            $exception=$excess ? 'excess_payment' : ($eligible ? '' : ($hold->state==='released' ? 'released_allocation' : 'payment_review_required'));
            $payment=['id'=>wp_generate_uuid4(),'booking_id'=>$bookingId,'provider'=>$proof->provider,'provider_ref'=>$proof->reference,'amount_irr'=>$proof->amount_irr,'currency'=>'IRR','status'=>'verified','exception_code'=>$exception,'verified_at'=>$proof->verified_at,'paid_at'=>$proof->paid_at];
            if (!$wpdb->insert(DB::table('payments'),$payment)) { throw new \RuntimeException('payment'); }
            if (!$wpdb->insert(DB::table('ledger'),['id'=>wp_generate_uuid4(),'payment_id'=>$payment['id'],'refund_id'=>'','event_type'=>'payment_verified','amount_irr'=>$proof->amount_irr,'currency'=>'IRR','created_at'=>DB::now()])) { throw new \RuntimeException('ledger'); }
            if ($eligible) {
                if ($wpdb->update(DB::table('holds'),['state'=>'consumed'],['id'=>$hold->id,'state'=>'active'])!==1) { throw new \RuntimeException('consume'); }
                $status='paid_pending_confirmation';
            } else { $status=$excess && $protected ? $booking->status : 'exception'; }
            if ($wpdb->update(DB::table('bookings'),['status'=>$status,'updated_at'=>DB::now()],['id'=>$bookingId])===false || !DB::audit(0,0,'payment.verify',$payment['id'],'success')) { throw new \RuntimeException('write'); }
            if ($exception!=='' && (!DB::audit(0,0,'payment.exception',$payment['id'],'review',$exception) || !DB::emit('payment.exception',$payment['id']))) { throw new \RuntimeException('exception'); }
            if ($status!==$booking->status && !DB::emit('booking.'.$status,$bookingId)) { throw new \RuntimeException('event'); }
            $wpdb->query('COMMIT'); return ['booking_id'=>$bookingId,'status'=>$status,'payment_status'=>'verified','payment_id'=>$payment['id'],'payment_exception'=>$exception];
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('atlas_verification_conflict','Payment could not be safely reconciled.',409); }
    }
    /** Revalidate initiation rights; this is not provider verification or capture. */
    public static function can_initiate_payment(string $bookingId,int $userId,int $orderId): true|\WP_Error {
        global $wpdb;
        if (!Auth::actor($userId)) { return self::error('atlas_forbidden','Sign in to pay.',403); }
        $row=$wpdb->get_row($wpdb->prepare('SELECT b.user_id,b.status,b.order_id,b.offer_version,h.state,h.expires_at,i.version,i.status AS offer_status FROM '.DB::table('bookings').' b INNER JOIN '.DB::table('holds').' h ON h.id=b.hold_id INNER JOIN '.DB::table('inventory').' i ON i.offer_id=b.offer_id WHERE b.id=%s',$bookingId));
        if (!$row || (int)$row->user_id!==$userId || (int)$row->order_id!==$orderId || $orderId<1) { return self::error('atlas_not_found','Payment reservation unavailable.',404); }
        if ($row->state!=='active' || DB::now()>=$row->expires_at || !in_array($row->status,['held','pending_payment'],true) || $row->offer_status!=='published' || (int)$row->offer_version!==(int)$row->version) { return self::error('atlas_payment_deadline','The reservation is no longer available for payment.'); }
        return true;
    }
    public static function queue(int $actorId,int $page=1,int $limit=25,string $status='paid_pending_confirmation'): array|\WP_Error {
        global $wpdb;
        if (!Auth::actor($actorId) || !Auth::agency() || !Auth::can('atlas_manage_bookings',null,null,'read')) { return self::error('atlas_forbidden','Agency authority required.',403); }
        if ($page<1 || $page>100000 || $limit<1 || $limit>50 || !in_array($status,['paid_pending_confirmation','exception','confirmed'],true)) { return self::error('atlas_invalid_request','Invalid booking queue filter.',422); }
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id AS booking_id,offer_id,status,quantity,amount_irr,order_id,created_at,updated_at FROM '.DB::table('bookings').' WHERE status=%s ORDER BY created_at,id LIMIT %d OFFSET %d',$status,$limit,($page-1)*$limit),ARRAY_A);
        if ($rows===null || !DB::audit($actorId,0,'booking.queue',$status,'read')) { return self::error('atlas_storage_error','Booking queue could not be safely read.',503); }
        foreach ($rows as &$row) { foreach (['offer_id','quantity','amount_irr','order_id'] as $key) { $row[$key]=(int)$row[$key]; } $row['currency']='IRR'; } unset($row);
        return ['items'=>$rows,'page'=>$page,'limit'=>$limit];
    }
    public static function confirm(string $bookingId,int $actorId,string $reason,string $key=''): array|\WP_Error {
        global $wpdb;
        if (!Auth::actor($actorId) || !Auth::agency() || !Auth::can('atlas_manage_bookings') || trim($reason)==='') { return self::error('atlas_forbidden','Agency authority and reason required.',403); }
        $reason=trim($reason); $key=$key ?: 'confirm-direct:'.$bookingId;
        if (strlen($reason)>190 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key)) { return self::error('atlas_invalid_request','A bounded reason and idempotency key are required.',422); }
        $scope='user:'.$actorId; $operation='booking.confirm'; $hash=hash('sha256',wp_json_encode([$bookingId,$reason]));
        $wpdb->query('START TRANSACTION');
        try {
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s FOR UPDATE',$bookingId));
            if (!$row) { $wpdb->query('ROLLBACK'); return self::error('atlas_not_found','Booking not found.',404); }
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$operation,$key));
            if ($prior) { $wpdb->query('COMMIT'); return hash_equals($prior->request_hash,$hash) ? json_decode($prior->response,true) : self::error('atlas_idempotency_conflict','Idempotency key was reused.'); }
            if (!in_array($row->status,['paid_pending_confirmation','confirmed'],true)) { $wpdb->query('ROLLBACK'); return self::error('atlas_invalid_transition','Booking cannot be confirmed.'); }
            $response=['booking_id'=>$bookingId,'status'=>'confirmed'];
            if ($row->status==='paid_pending_confirmation') {
                $snapshot=json_decode($row->snapshot,true) ?: []; $org=(int)($snapshot['org_id'] ?? 0);
                if ($wpdb->update(DB::table('bookings'),['status'=>'confirmed','updated_at'=>DB::now()],['id'=>$bookingId])!==1 || !DB::audit($actorId,$org,'booking.confirm',$bookingId,'success',$reason) || !DB::emit('booking.confirmed',$bookingId)) { throw new \RuntimeException('confirm'); }
            }
            if (!$wpdb->insert(DB::table('idempotency'),['scope'=>$scope,'operation'=>$operation,'idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode($response),'created_at'=>DB::now()])) { throw new \RuntimeException('idempotency'); }
            $wpdb->query('COMMIT'); return $response;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$operation,$key));
            if ($prior) { return hash_equals($prior->request_hash,$hash) ? json_decode($prior->response,true) : self::error('atlas_idempotency_conflict','Idempotency key was reused.'); }
            return self::error('atlas_storage_error','Could not confirm booking.',503);
        }
    }
}
