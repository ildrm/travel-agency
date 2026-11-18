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
    public static function hold(int $offerId,int $offerVersion,int $qty,int $userId,string $key,array $eligibility=[],array $options=[]): array|\WP_Error {
        global $wpdb;
        if (!Auth::actor($userId)) { return self::error('atlas_forbidden','Sign in to reserve.',403); }
        if ($offerId<1 || $offerVersion<1 || $qty<1 || $qty>100 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key)) { return self::error('atlas_invalid_request','Invalid hold request.',422); }
        if (strlen(wp_json_encode($options))>20000) { return self::error('atlas_invalid_request','Booking options are too large.',422); }
        $hashData=[$offerId,$offerVersion,$qty,$eligibility]; if ($options) $hashData[]=$options;
        $hash=hash('sha256',wp_json_encode($hashData)); $scope='user:'.$userId;
        $existing=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,'hold',$key));
        if ($existing) { return hash_equals($existing->request_hash,$hash) ? json_decode($existing->response,true) : self::error('atlas_idempotency_conflict','Idempotency key was reused for another request.'); }
        $wpdb->query('START TRANSACTION');
        try {
            $inv=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',$offerId));
            $d=$inv ? json_decode($inv->offer_snapshot,true) : null; $now=DB::now();
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,'hold',$key));
            if ($prior) { $wpdb->query('COMMIT'); return hash_equals($prior->request_hash,$hash) ? json_decode($prior->response,true) : self::error('atlas_idempotency_conflict','Idempotency key was reused for another request.'); }
            if (!$inv || !is_array($d) || $d['status']!=='published' || $d['mode']!=='local') { $wpdb->query('ROLLBACK'); return self::error('atlas_unavailable','Offer unavailable.'); }
            $d['id']=$offerId;
            if ($d['kind']==='tour' && apply_filters('atlas_core_validate_tour_hold', false, $d, $offerId)!==true) { $wpdb->query('ROLLBACK'); return self::error('atlas_tour_review_required','An approved itinerary is required.'); }
            if ($d['kind']==='cohort' && apply_filters('atlas_core_validate_cohort_hold', false, $d, $offerId)!==true) { $wpdb->query('ROLLBACK'); return self::error('atlas_cohort_review_required','An approved course and cohort are required.'); }
            if ((int)$d['version']!==$offerVersion) { $wpdb->query('ROLLBACK'); return self::error('atlas_quote_changed','Offer changed; review the new terms.'); }
            $open=(new \DateTimeImmutable($d['registration_open_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); $close=(new \DateTimeImmutable($d['registration_close_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $admission=null;
            if ($now<$open || $now >= $close) {
                // Only an explicitly authorized agency actor may use a reviewed
                // post-close exception; ordinary or waitlist requests never can.
                if ($now >= $close && Auth::agency() && Auth::can('atlas_manage_bookings')) $admission=apply_filters('atlas_core_admission_exception',false,$d,$offerId,$userId,$options,$now);
                if (is_wp_error($admission)) { $wpdb->query('ROLLBACK'); return $admission; }
                $bound=(new \DateTimeImmutable($now,new \DateTimeZone('UTC')))->modify('+600 seconds')->format('Y-m-d H:i:s.u');
                $deadlineValue=is_array($admission)?($admission['deadline']??null):null;
                $validDate=is_string($deadlineValue)?\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$deadlineValue,new \DateTimeZone('UTC')):false;
                if (!is_array($admission) || !$validDate || $validDate->format('Y-m-d H:i:s.u')!==$deadlineValue || $deadlineValue<=$now || $deadlineValue>$bound
                    || !is_string($admission['reference']??null) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$admission['reference']) || !is_string($admission['reason']??null) || trim($admission['reason'])==='' || strlen($admission['reason'])>190) {
                    $wpdb->query('ROLLBACK'); return self::error('registration_closed','Registration is closed.');
                }
                $d['admission_exception']=['reference'=>$admission['reference'],'reason'=>$admission['reason']];
            }
            $cohortEligibility=null;
            if ($d['kind']==='cohort') {
                $cohortEligibility=apply_filters('atlas_core_validate_cohort_participants',null,$d,$userId,$qty,$eligibility,$options);
                if (is_wp_error($cohortEligibility)) { $wpdb->query('ROLLBACK'); return $cohortEligibility; }
                if ($qty!==1 && $cohortEligibility!==true) { $wpdb->query('ROLLBACK'); return self::error('atlas_group_enrollment_unavailable','A valid authorization is required for each learner.',422); }
                if ($cohortEligibility!==null && $cohortEligibility!==true) { $wpdb->query('ROLLBACK'); return self::error('atlas_invalid_participant','Learner eligibility could not be established.',422); }
            }
            $eligible=$cohortEligibility===true?true:Eligibility::validate($d,$userId,$qty,$eligibility);
            if (is_wp_error($eligible)) { $wpdb->query('ROLLBACK'); return $eligible; }
            // Extensions run within this transaction and may lock additional
            // resource rows. They must never start or commit a nested transaction.
            $prepared=apply_filters('atlas_core_prepare_hold',['unit_amount_irr'=>(int)$d['amount_irr'],'snapshot'=>[],'handled_options'=>false],$d,$qty,$userId,$options,$now,$eligibility);
            if (is_wp_error($prepared)) { $wpdb->query('ROLLBACK'); return $prepared; }
            if (!is_array($prepared) || !is_int($prepared['unit_amount_irr'] ?? null) || $prepared['unit_amount_irr']<0 || !is_array($prepared['snapshot'] ?? null)
                || ($options && empty($prepared['handled_options']))) { $wpdb->query('ROLLBACK'); return self::error('atlas_invalid_options','Invalid or unsupported booking options.',422); }
            $primary=$prepared['primary_quantity'] ?? $qty;
            if (!is_int($primary) || ($primary!==$qty && !($primary===0 && in_array($d['kind'],['stay','rental'],true)))) { $wpdb->query('ROLLBACK'); return self::error('atlas_invalid_options','Invalid capacity allocation.',422); }
            if (in_array($d['kind'],['stay','rental'],true) && apply_filters('atlas_core_validate_resource_hold',false,$d,$qty,$userId,$options,$now)!==true) { $wpdb->query('ROLLBACK'); return self::error('atlas_resource_unavailable','Dated resource booking is unavailable.'); }
            if ((int)$inv->capacity-(int)$inv->allocated < $primary) { $wpdb->query('ROLLBACK'); return self::error('atlas_sold_out','Not enough places are available.'); }
            $unit=$prepared['unit_amount_irr']; if ($unit>intdiv(PHP_INT_MAX,$qty)) { $wpdb->query('ROLLBACK'); return self::error('atlas_invalid_amount','Amount out of range.',422); }
            if ($prepared['snapshot']) $d['booking_options']=$prepared['snapshot'];
            $tenMinutes=(new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('+600 seconds')->format('Y-m-d H:i:s.u'); $deadline=$admission ? $admission['deadline'] : min($tenMinutes,$close); $hold=wp_generate_uuid4(); $booking=wp_generate_uuid4();
            $quote=['amount'=>$unit*$qty,'currency'=>'IRR','unit'=>'rial','offer_version'=>$offerVersion];
            $response=['hold_id'=>$hold,'booking_id'=>$booking,'accepted_at'=>self::iso($now),'expires_at'=>self::iso($deadline),'payment_deadline'=>self::iso($deadline),'quote'=>$quote];
            if ($primary>0 && $wpdb->update(DB::table('inventory'),['allocated'=>(int)$inv->allocated+$primary],['offer_id'=>$offerId])!==1) { throw new \RuntimeException('inventory'); }
            if (!$wpdb->insert(DB::table('holds'),['id'=>$hold,'booking_id'=>$booking,'offer_id'=>$offerId,'user_id'=>$userId,'quantity'=>$qty,'primary_quantity'=>$primary,'accepted_at'=>$now,'expires_at'=>$deadline,'state'=>'active'])) { throw new \RuntimeException('hold'); }
            if (!$wpdb->insert(DB::table('bookings'),['id'=>$booking,'hold_id'=>$hold,'offer_id'=>$offerId,'user_id'=>$userId,'status'=>'held','quantity'=>$qty,'amount_irr'=>$unit*$qty,'offer_version'=>$offerVersion,'snapshot'=>wp_json_encode($d),'participants'=>wp_json_encode($eligibility),'order_id'=>0,'created_at'=>$now,'updated_at'=>$now])) { throw new \RuntimeException('booking'); }
            $extra=apply_filters('atlas_core_hold_created',true,$booking,$hold,$d,$qty,$userId,$prepared,$deadline);
            if ($extra!==true) { $wpdb->query('ROLLBACK'); return is_wp_error($extra)?$extra:self::error('atlas_capacity_conflict','Additional capacity could not be reserved.'); }
            if (!$wpdb->insert(DB::table('idempotency'),['scope'=>$scope,'operation'=>'hold','idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode($response),'created_at'=>$now])) { throw new \RuntimeException('idempotency'); }
            if (!DB::audit($userId,(int)$d['org_id'],'hold.create',$booking,'success') || !DB::emit('booking.held',$booking)) { throw new \RuntimeException('events'); }
            if ($admission && !DB::audit($userId,(int)$d['org_id'],'hold.admission_exception',$booking,'success',$admission['reference'].': '.$admission['reason'])) throw new \RuntimeException('exception audit');
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
            $primary=$hold->primary_quantity===null?(int)$hold->quantity:(int)$hold->primary_quantity;
            if ((int)$inv->allocated < $primary) { throw new \RuntimeException('invariant'); }
            if (($primary>0 && $wpdb->update(DB::table('inventory'),['allocated'=>(int)$inv->allocated-$primary],['offer_id'=>$hold->offer_id])!==1) || $wpdb->update(DB::table('holds'),['state'=>'released'],['id'=>$holdId,'state'=>'active'])!==1) { throw new \RuntimeException('release'); }
            $extra=apply_filters('atlas_core_hold_released',true,$hold->booking_id,$holdId);
            if ($extra!==true) { throw new \RuntimeException('additional release failed'); }
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
                if (apply_filters('atlas_core_hold_consumed',true,$bookingId,$hold->id)!==true) { throw new \RuntimeException('additional consumption failed'); }
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
        $check=apply_filters('atlas_core_before_payment_initiation',true,$bookingId,$userId,$orderId);
        if ($check!==true) return is_wp_error($check)?$check:self::error('atlas_payment_precondition','Booking requirements must be completed before payment.');
        return true;
    }
    /** Trusted workflow seam: caller owns the transaction and validates its specific supplier assignment. */
    public static function review_in_transaction(string $bookingId,int $actorId,string $reason,string $evidence): array|\WP_Error {
        global $wpdb;
        if (!Auth::actor($actorId) || trim($reason)==='' || strlen($reason)>190 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$evidence)) return self::error('atlas_invalid_review','Authenticated reason and evidence reference required.',422);
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s FOR UPDATE',$bookingId));
        if (!$row) return self::error('atlas_not_found','Booking unavailable.',404);
        $snapshot=json_decode($row->snapshot,true)?:[]; $org=(int)($snapshot['org_id']??0);
        if (!Auth::agency() && (!$org || !Auth::can('atlas_manage_records',$org,null,'write'))) return self::error('atlas_forbidden','Service organization authority required.',403);
        if ($row->status==='exception') return ['booking_id'=>$bookingId,'status'=>'exception'];
        // A supplier review does not silently revoke a confirmed entitlement,
        // reverse money, release capacity, or assert a successful refund.
        $next=in_array($row->status,['held','pending_payment','paid_pending_confirmation'],true)?'exception':$row->status;
        if ($next!==$row->status && $wpdb->update(DB::table('bookings'),['status'=>$next,'updated_at'=>DB::now()],['id'=>$bookingId])!==1) return self::error('atlas_review_failed','Booking review could not be recorded.',503);
        if (!DB::audit($actorId,$org,'booking.review',$bookingId,'review',$evidence.': '.$reason) || !DB::emit('booking.'.($next==='exception'?'exception':'review_required'),$bookingId)) return self::error('atlas_review_failed','Booking review could not be audited.',503);
        return ['booking_id'=>$bookingId,'status'=>$next];
    }
    /** Caller owns the inventory→hold→booking locks and the matching resource release. */
    public static function cancel_resource_in_transaction(string $bookingId,int $actorId,string $reason,string $evidenceRef): array|\WP_Error {
        global $wpdb;
        if (!Auth::actor($actorId) || !Auth::agency() || !Auth::can('atlas_manage_bookings')) return self::error('atlas_forbidden','Agency booking authority required.',403);
        if (trim($reason)==='' || strlen($reason)>190 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$evidenceRef)) return self::error('atlas_invalid_review','Cancellation reason and evidence reference required.',422);
        $bookings=DB::table('bookings'); $holds=DB::table('holds');
        $row=$wpdb->get_row($wpdb->prepare("SELECT b.status,b.snapshot,b.hold_id,h.primary_quantity,h.state AS hold_state FROM $bookings b INNER JOIN $holds h ON h.id=b.hold_id AND h.booking_id=b.id WHERE b.id=%s FOR UPDATE",$bookingId));
        $snapshot=$row?json_decode($row->snapshot,true):null;
        if (!$row || !in_array($snapshot['kind']??'',array('stay','rental'),true) || $row->primary_quantity===null || (int)$row->primary_quantity!==0 || !in_array($row->hold_state,array('active','consumed'),true) || !in_array($row->status,array('held','pending_payment','paid_pending_confirmation','confirmed','exception'),true)) return self::error('atlas_resource_cancel_conflict','Resource booking is not eligible for cancellation.');
        $now=DB::now();
        $verified=(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('payments').' WHERE booking_id=%s AND status=%s LIMIT 1',$bookingId,'verified'));
        if ($wpdb->update($holds,array('state'=>'released'),array('id'=>$row->hold_id,'state'=>$row->hold_state))!==1 || $wpdb->update($bookings,array('status'=>'canceled','updated_at'=>$now),array('id'=>$bookingId,'status'=>$row->status))!==1 || !DB::audit($actorId,(int)($snapshot['org_id']??0),'booking.resource_cancel',$bookingId,'success',$evidenceRef.': '.$reason) || !DB::emit('booking.canceled',$bookingId)) return self::error('atlas_resource_cancel_failed','Cancellation could not be saved or audited.',503);
        if ($verified && !DB::emit('booking.refund_review_required',$bookingId)) return self::error('atlas_resource_cancel_failed','Refund review could not be queued.',503);
        return array('booking_id'=>$bookingId,'status'=>'canceled','refund_review_required'=>$verified);
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
            if ($prior) {
                $wpdb->query('COMMIT');
                return self::confirmation_replay($prior,$hash,$row->status);
            }
            if (!in_array($row->status,['paid_pending_confirmation','confirmed'],true)) { $wpdb->query('ROLLBACK'); return self::error('atlas_invalid_transition','Booking cannot be confirmed.'); }
            if ($row->status==='paid_pending_confirmation') {
                $check=apply_filters('atlas_core_before_confirmation',true,$bookingId,$row,$actorId);
                if ($check!==true) { $wpdb->query('ROLLBACK'); return is_wp_error($check)?$check:self::error('atlas_confirmation_blocked','Service acceptance is required before confirmation.'); }
            }
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
            if ($prior) {
                $current=$wpdb->get_var($wpdb->prepare('SELECT status FROM '.DB::table('bookings').' WHERE id=%s',$bookingId));
                return self::confirmation_replay($prior,$hash,(string)$current);
            }
            return self::error('atlas_storage_error','Could not confirm booking.',503);
        }
    }
    private static function confirmation_replay(object $prior,string $hash,string $current): array|\WP_Error {
        if (!hash_equals($prior->request_hash,$hash)) return self::error('atlas_idempotency_conflict','Idempotency key was reused.');
        if (!in_array($current,array('confirmed','fulfilled'),true)) return self::error('atlas_invalid_transition','Booking cannot be confirmed.');
        return json_decode($prior->response,true);
    }
}
