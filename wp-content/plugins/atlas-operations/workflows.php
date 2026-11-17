<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\DB;
use Atlas\Core\Records;
use Atlas\Core\Booking;
use Atlas\Core\Offers;
use Atlas\Core\Auth;

final class Atlas_Operation_Workflows {
    private static function error($message) { return new WP_Error('atlas_workflow_invalid', $message, array('status'=>422)); }
    private static function utc($value) { return is_string($value) && preg_match('/(?:Z|[+]00:00)$/',$value) && strtotime($value)!==false; }
    private static function zone($value) { return is_string($value) && in_array($value,timezone_identifiers_list(),true); }
    public static function validate_tour($fields) {
        $stops=$fields['stops']??array(); $days=$fields['days']??array(); $legs=$fields['legs']??array();
        if (!is_array($stops) || count($stops)<1 || count($stops)>100 || !is_array($days) || count($days)<1 || count($days)>100 || !is_array($legs)) return self::error(__('A route needs structured stops and days.','atlas-operations'));
        $last=null;
        foreach ($stops as $stop) {
            if (!is_array($stop) || empty($stop['city']) || empty($stop['country']) || !self::zone($stop['timezone']??null) || !self::utc($stop['arrival']??null) || !self::utc($stop['departure']??null)) return self::error(__('Each stop needs a city, country, IANA zone and UTC arrival/departure.','atlas-operations'));
            $arrival=strtotime($stop['arrival']); $departure=strtotime($stop['departure']);
            if ($arrival>$departure || ($last!==null && $arrival<$last)) return self::error(__('The route times overlap or are out of order.','atlas-operations'));
            $last=$departure;
        }
        foreach ($legs as $leg) {
            if (!is_array($leg) || empty($leg['origin']) || empty($leg['destination']) || empty($leg['mode']) || !self::zone($leg['origin_timezone']??null) || !self::zone($leg['destination_timezone']??null) || !self::utc($leg['depart_at']??null) || !self::utc($leg['arrive_at']??null) || strtotime($leg['arrive_at'])<=strtotime($leg['depart_at'])) return self::error(__('Each leg needs valid locations, mode, zones and ordered UTC times.','atlas-operations'));
        }
        foreach ($days as $day) {
            if (!is_array($day) || empty($day['date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$day['date']) || empty($day['items']) || !is_array($day['items'])) return self::error(__('Each itinerary day needs a date and structured items.','atlas-operations'));
            foreach ($day['items'] as $item) if (!is_array($item) || empty($item['type']) || empty($item['description']) || !self::utc($item['start']??null) || !self::utc($item['end']??null) || strtotime($item['end'])<=strtotime($item['start'])) return self::error(__('Each itinerary item needs a type, description and ordered UTC times.','atlas-operations'));
        }
        return true;
    }
    public static function before_transition($type, $record, $target) {
        $fields=$record['fields'];
        if ($target==='approved' && (int)$record['owner_id']===get_current_user_id()) return new WP_Error('atlas_self_approval',__('A different agency reviewer must approve.','atlas-operations'),array('status'=>403));
        if ($type==='tour_version' && in_array($target,array('submitted','approved'),true)) {
            $valid=self::validate_tour($fields); if (is_wp_error($valid)) return $valid;
            if (empty($fields['offer_id'])) return self::error(__('The tour must be linked to an offer.','atlas-operations'));
            $offer=Offers::get((int)$fields['offer_id'],false);
            if (is_wp_error($offer) || $offer['kind']!=='tour' || (int)$offer['org_id']!==(int)$record['org_id']) return self::error(__('The tour offer and organization do not match.','atlas-operations'));
        }
        if (in_array($type,array('attendance','assessment'),true) && in_array($target,array('submitted','approved'),true)) {
            $cohort=Records::get('cohort',(string)($fields['cohort_id']??''),get_current_user_id());
            $booking=Booking::get((string)($fields['booking_id']??''),get_current_user_id());
            if (is_wp_error($cohort) || is_wp_error($booking) || $booking['status']!=='confirmed' || (int)$booking['user_id']!==(int)($fields['learner_id']??0) || (int)$booking['offer_id']!==(int)($cohort['fields']['offer_id']??0)) return self::error(__('A confirmed enrollment for this learner and cohort is required.','atlas-operations'));
        }
        if ($type==='certificate' && in_array($target,array('submitted','approved'),true)) {
            $attendance=Records::get('attendance',(string)($fields['attendance_id']??''),get_current_user_id());
            $assessment=Records::get('assessment',(string)($fields['assessment_id']??''),get_current_user_id());
            if (is_wp_error($attendance) || is_wp_error($assessment) || $attendance['state']!=='approved' || $assessment['state']!=='approved' || empty($attendance['fields']['attended']) || ($assessment['fields']['result']??'')!=='pass') return self::error(__('Approved attendance and a passing assessment are required.','atlas-operations'));
            foreach (array($attendance,$assessment) as $dependency) if (($dependency['fields']['cohort_id']??'')!==($fields['cohort_id']??'') || (int)($dependency['fields']['learner_id']??0)!==(int)($fields['learner_id']??0)) return self::error(__('Completion evidence does not match the learner and cohort.','atlas-operations'));
        }
        if ($type==='production' && $target==='closed' && (empty($fields['shipment_ref']) || empty($fields['inspection']) || empty($fields['invoice_ref']) || empty($fields['payout_evidence']))) return self::error(__('Shipment, inspection, invoice and manual settlement evidence are required before closing.','atlas-operations'));
        if ($type==='production' && $target==='approved' && empty($fields['sample_approved'])) return self::error(__('The production sample must be approved first.','atlas-operations'));
        return true;
    }
    /** Resolve only an unambiguous reviewed cohort linked to the same organization and approved course. */
    public static function cohort_for_offer($offer_id,$org_id,$lock=false) {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,payload,org_id,state FROM '.DB::table('records').' WHERE type=%s AND org_id=%d AND state IN (%s,%s) ORDER BY id LIMIT 1001'.($lock?' FOR UPDATE':''),'cohort',(int)$org_id,'approved','submitted'));
        if (count($rows)>1000) return null;
        $result=null;
        foreach ($rows as $row) {
            $fields=json_decode($row->payload,true);
            if (!is_array($fields) || (int)($fields['offer_id']??0)!==(int)$offer_id || (int)$row->org_id!==(int)$org_id) continue;
            if ($row->state!=='approved' || $result!==null || !empty($fields['prerequisites'])) return null;
            $course=$wpdb->get_row($wpdb->prepare('SELECT payload,org_id,state FROM '.DB::table('records').' WHERE id=%s AND type=%s'.($lock?' FOR UPDATE':''),(string)($fields['course_id']??''),'course'));
            $course_fields=$course ? json_decode($course->payload,true) : null;
            if (!$course || $course->state!=='approved' || (int)$course->org_id!==(int)$org_id || !is_array($course_fields) || !empty($course_fields['prerequisites'])) return null;
            $result=(string)$row->id;
        }
        return $result;
    }
    public static function validate_cohort_publication($valid,$offer,$offer_id) {
        return Auth::agency() && ($offer['kind']??'')==='cohort' && self::cohort_for_offer((int)$offer_id,(int)($offer['org_id']??0))!==null;
    }
    public static function validate_cohort_hold($valid,$offer,$offer_id) {
        return ($offer['kind']??'')==='cohort' && self::cohort_for_offer((int)$offer_id,(int)($offer['org_id']??0),true)!==null;
    }
    private static function tour_approved($offer,$offer_id,$lock=false) {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,payload,org_id,state FROM '.DB::table('records').' WHERE type=%s AND org_id=%d AND state IN (%s,%s) ORDER BY id LIMIT 1001'.($lock?' FOR UPDATE':''),'tour_version',(int)($offer['org_id']??0),'approved','submitted'));
        if (count($rows)>1000) return false;
        $found=false;
        foreach ($rows as $r) {
            $fields=json_decode($r->payload,true);
            if (!is_array($fields) || (int)($fields['offer_id']??0)!==(int)$offer_id || (int)$r->org_id!==(int)($offer['org_id']??0)) continue;
            if ($r->state==='submitted') return false;
            if ($r->state==='approved' && self::validate_tour($fields)===true) {
                $policy=$offer['eligibility']??array();
                if ((!empty($fields['age_min']) && (int)($policy['min_age']??-1)!==(int)$fields['age_min']) || (!empty($fields['age_max']) && (int)($policy['max_age']??-1)!==(int)$fields['age_max'])) return false;
                if ((!empty($fields['age_min']) || !empty($fields['age_max'])) && (empty($policy['age_on']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$policy['age_on']))) return false;
                $found=true;
            }
        }
        return $found;
    }
    public static function validate_publication($valid,$offer,$offer_id) {
        return Auth::agency() && ($offer['kind']??'')==='tour' && self::tour_approved($offer,$offer_id,false);
    }
    public static function validate_tour_hold($valid,$offer,$offer_id) {
        return ($offer['kind']??'')==='tour' && self::tour_approved($offer,$offer_id,true);
    }
}
