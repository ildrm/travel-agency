<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;

/** In-app routing only. Templates are independently reviewed by the notice service. */
final class Atlas_Partner_Notices {
    private const PARTNER=array('partner.reviewed','partner.invitation_created','partner.member_accepted','partner.contract_create','partner.contract_revise','partner.contract_review','partner.contract_accept','partner.contract_close');
    private const PROCUREMENT=array('rfq','quote','award','artwork','art_review','sample','authorize','milestone','shipment','inspection','return_request','return_resolve','payable','settlement_intent','settlement_approve','settlement_evidence','close');
    private const DISPATCH=array('assign','supplier_accept','supplier_reject','policy_accept','confirm','en_route','arrived','waiting','contact_attempt','onboard','completed','no_show','cancel','schedule_conflict');
    private const MANAGERS=array('manager','partner_admin','owner');
    private const FINANCE=array('payable','settlement_intent','settlement_approve','settlement_evidence','close');
    public static function register() {
        add_filter('atlas_notification_events',array(__CLASS__,'events'));
        add_filter('atlas_notification_recipients',array(__CLASS__,'recipients'),10,2);
    }
    public static function events($previous) {
        return array_values(array_unique(array_merge(is_array($previous)?$previous:array(),self::PARTNER,array_map(static function($op){return 'procurement.'.$op;},self::PROCUREMENT),array_map(static function($op){return 'dispatch.'.$op;},self::DISPATCH))));
    }
    private static function agency($finance=false) {
        // This application has a shared agency queue, not per-object staff assignments.
        $users=get_users(array('capability'=>'manage_options','number'=>101,'fields'=>'ID'));
        if (count($users)>100) throw new OverflowException('agency audience');
        return array_values(array_filter(array_map('intval',$users),static function($user)use($finance){return user_can($user,'manage_options') && user_can($user,'atlas_manage_records') && (!$finance || user_can($user,'atlas_manage_finance'));}));
    }
    private static function members($org,$managers=false) {
        global $wpdb;
        $users=$wpdb->get_col($wpdb->prepare('SELECT m.user_id FROM '.DB::table('memberships').' m INNER JOIN '.DB::table('organizations')." o ON o.id=m.org_id WHERE m.org_id=%d AND m.status='active' AND o.status IN ('active','approved') ORDER BY m.user_id LIMIT 101",$org));
        if (count($users)>100) throw new OverflowException('organization audience');
        return array_values(array_filter(array_map('intval',$users),static function($user)use($org,$managers){$role=Auth::member_role((int)$org,$user); return $role!==null && user_can($user,'atlas_manage_records') && (!$managers || in_array($role,self::MANAGERS,true));}));
    }
    private static function manager($org,$user) {
        return $user>0 && user_can($user,'atlas_manage_records') && (user_can($user,'manage_options') || in_array(Auth::member_role((int)$org,$user),self::MANAGERS,true));
    }
    /** Resolve current persisted associations, never caller/outbox payload recipient hints. */
    public static function recipients($previous,$event) {
        global $wpdb;
        $type=is_array($event)?($event['event_type']??null):null; $id=is_array($event)?($event['aggregate_id']??null):null;
        if (!is_string($type) || !in_array($type,self::events(array()),true)) return $previous;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id)) return false;
        try {
            $users=array();
            if ($type==='partner.reviewed') {
                $row=$wpdb->get_row($wpdb->prepare('SELECT owner_id FROM '.DB::table('records').' WHERE id=%s AND type=%s',$id,'partner_application'));
                if (!$row) return false; $users=array_merge(self::agency(),array((int)$row->owner_id));
            } elseif (in_array($type,array('partner.invitation_created','partner.member_accepted'),true)) {
                if (!class_exists('Atlas_Partner_Invitations')) return false;
                $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Atlas_Partner_Invitations::table().' WHERE id=%s',$id));
                if (!$row) return false;
                if (self::manager((int)$row->org_id,(int)$row->created_by)) $users[]=(int)$row->created_by;
                if ($type==='partner.invitation_created' && $row->state==='pending' && $row->expires_at>DB::now() && self::manager((int)$row->org_id,(int)$row->created_by) && Atlas_Partner_Security::active(Atlas_Partner_Security::active_org((int)$row->org_id))) {
                    $recipient=get_user_by('email',$row->email); if ($recipient && strcasecmp($recipient->user_email,$row->email)===0) $users[]=(int)$recipient->ID;
                } elseif ($type==='partner.member_accepted' && $row->state==='accepted' && Auth::member((int)$row->org_id,(int)$row->accepted_by)) $users[]=(int)$row->accepted_by;
            } elseif (str_starts_with($type,'partner.contract_')) {
                if (!class_exists('Atlas_Partner_Contracts')) return false;
                $row=$wpdb->get_row($wpdb->prepare('SELECT org_id FROM '.Atlas_Partner_Contracts::table().' WHERE id=%s',$id));
                if (!$row) return false; $users=array_merge(self::agency(),self::members((int)$row->org_id,true));
            } elseif (str_starts_with($type,'procurement.')) {
                if (!class_exists('Atlas_Procurement') || !Atlas_Procurement::ready()) return false;
                $row=$wpdb->get_row($wpdb->prepare('SELECT type,org_id,payload FROM '.Atlas_Procurement::table().' WHERE id=%s',$id));
                if (!$row) return false; $op=substr($type,12);
                if (($op==='rfq' && $row->type!=='rfq') || ($op==='quote' && $row->type!=='quote') || (!in_array($op,array('rfq','quote'),true) && $row->type!=='order')) return false;
                $users=self::agency(in_array($op,self::FINANCE,true));
                if ($row->type==='rfq') {
                    $payload=json_decode($row->payload,true); $orgs=$payload['recipients']??null;
                    if (!is_array($orgs) || count($orgs)>100) return false;
                    foreach ($orgs as $org) { if (!is_int($org) || $org<1) return false; $users=array_merge($users,self::members($org)); if (count($users)>1000) return false; }
                } else $users=array_merge($users,self::members((int)$row->org_id));
            } elseif (str_starts_with($type,'dispatch.')) {
                if (!class_exists('Atlas_Dispatch') || !Atlas_Dispatch::ready()) return false;
                $row=$wpdb->get_row($wpdb->prepare('SELECT customer_id,org_id,driver_id FROM '.Atlas_Dispatch::table().' WHERE id=%s',$id));
                if (!$row) return false;
                $users=array_merge(self::agency(),self::members((int)$row->org_id,true));
                $driver=(int)$row->driver_id;
                if ($driver>0 && Auth::member((int)$row->org_id,$driver) && user_can($driver,'atlas_manage_records')) $users[]=$driver;
                // Operational contact evidence remains internal; customers receive stage references only.
                if ($type!=='dispatch.contact_attempt') $users[]=(int)$row->customer_id;
            }
            $result=array();
            foreach (array_unique($users) as $user) if ($user>0 && get_user_by('id',$user)) $result[]=array('user'=>(int)$user,'reference'=>$id);
            if (count($result)>1000) return false;
            // Valid but obsolete/unassigned events have no disclosure audience.
            return $result?:array(array('user'=>0,'reference'=>$id));
        } catch (OverflowException $e) { return false; }
    }
}
add_action('atlas_operations_ready',array('Atlas_Partner_Notices','register'));
