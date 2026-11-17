<?php
if (!defined('ABSPATH')) { exit; }
/** Domain-specific allowlists. Fields are persisted by Atlas Core, not public post meta. */
function atlas_operation_schemas() {
    $text = function ($max = 180, $required = false) { return array('type' => 'string', 'max' => $max, 'required' => $required); };
    $integer = function ($required = false) { return array('type' => 'int', 'required' => $required); };
    $arr = function () { return array('type' => 'array'); };
    $date = function ($required = false) { return array('type' => 'datetime', 'required' => $required); };
    $zone = function ($required = false) { return array('type' => 'timezone', 'required' => $required); };
    $state = array('type' => 'string', 'enum' => array('draft','submitted','approved','rejected','closed'));
    $review = array('draft' => array('submitted'), 'submitted' => array('approved','rejected'), 'rejected' => array('draft'), 'approved' => array('closed'));
    $agency = array('submitted' => array('approved','rejected'), 'approved' => array('closed'));
    $base = function ($fields, $cap = 'atlas_manage_records') use ($state, $review, $agency) {
        return array('fields' => array_merge(array('state' => $state), $fields), 'capability' => $cap,
            'transitions' => array('state' => $review), 'agency_transitions' => array('state' => $agency));
    };
    $schemas=array(
        'tour_version' => $base(array('title_en'=>$text(180,true),'title_fa'=>$text(180),'description'=>$text(4000),'stops'=>$arr(),'legs'=>$arr(),'days'=>$arr(),'meals'=>$arr(),'lodging'=>$arr(),'equipment'=>$arr(),'private_costs'=>$arr(),'age_min'=>$integer(),'age_max'=>$integer(),'offer_id'=>$integer())),
        'event_detail' => $base(array('offer_id'=>$integer(true),'venue'=>$text(500),'agenda'=>$arr(),'tiers'=>$arr(),'waiver'=>$text(2000),'gear'=>$arr(),'meals'=>$arr(),'transport'=>$text(500))),
        'event_checkin' => $base(array('booking_id'=>$text(64,true),'occurrence_id'=>$integer(true),'attendee_ref'=>$text(100,true),'checked_at'=>$date())),
        'course' => $base(array('title_en'=>$text(180,true),'title_fa'=>$text(180),'syllabus'=>$text(5000),'prerequisites'=>$text(1500),'materials'=>$arr())),
        'cohort' => $base(array('course_id'=>$text(64,true),'offer_id'=>$integer(true),'venue'=>$text(500),'timezone'=>$zone(),'sessions'=>$arr(),'instructors'=>$arr(),'prerequisites'=>$text(1500))),
        'enrollment' => $base(array('cohort_id'=>$text(64,true),'learner_id'=>$integer(true),'booking_id'=>$text(64,true),'hold_id'=>$text(64,true)), 'read'),
        'attendance' => $base(array('cohort_id'=>$text(64,true),'learner_id'=>$integer(true),'booking_id'=>$text(64,true),'session'=>$text(180,true),'attended'=>array('type'=>'bool'),'note'=>$text(1000))),
        'assessment' => $base(array('cohort_id'=>$text(64,true),'learner_id'=>$integer(true),'booking_id'=>$text(64,true),'rubric'=>$text(1000),'result'=>$text(100),'reviewer_note'=>$text(1000))),
        'certificate' => $base(array('cohort_id'=>$text(64,true),'learner_id'=>$integer(true),'attendance_id'=>$text(64,true),'assessment_id'=>$text(64,true),'reference'=>$text(80,true))),
        'property' => $base(array('name_en'=>$text(180,true),'name_fa'=>$text(180),'address'=>$text(500),'timezone'=>$zone(),'features'=>$arr(),'policy'=>$text(2000))),
        'unit_type' => $base(array('property_id'=>$text(64,true),'name'=>$text(180,true),'capacity'=>$integer(true),'units'=>$integer(true),'whole_property'=>array('type'=>'bool'))),
        'stay_rate' => $base(array('unit_type_id'=>$text(64,true),'name'=>$text(180,true),'amount_irr'=>$integer(true),'start'=>$text(10,true),'end'=>$text(10,true),'terms'=>$text(2000))),
        'vehicle' => $base(array('category'=>$text(30,true),'label'=>$text(180,true),'capacity'=>$integer(true),'license_class'=>$text(100),'blocked_intervals'=>$arr())),
        'stay_request' => $base(array('property_id'=>$text(64,true),'check_in'=>$text(10,true),'check_out'=>$text(10,true),'guests'=>$integer(true),'contact'=>$text(180,true)), 'read'),
        'ticket_request' => $base(array('origin'=>$text(180,true),'destination'=>$text(180,true),'travel_date'=>$text(10,true),'passengers'=>$integer(true),'contact'=>$text(180,true)), 'read'),
        'transfer_request' => $base(array('pickup'=>$text(300,true),'dropoff'=>$text(300,true),'pickup_at'=>$date(true),'pickup_zone'=>$zone(true),'dropoff_at'=>$date(true),'dropoff_zone'=>$zone(true),'passengers'=>$integer(true),'luggage'=>$integer(),'vehicle_class'=>$text(60,true),'contact'=>$text(180,true),'accessibility'=>$text(500),'flight_reference'=>$text(100)), 'read'),
        'rental_request' => $base(array('category'=>$text(30,true),'pickup'=>$text(300,true),'dropoff'=>$text(300,true),'start'=>$date(true),'end'=>$date(true),'timezone'=>$zone(true),'contact'=>$text(180,true)), 'read'),
        'partner_proposal' => $base(array('kind'=>$text(60,true),'title'=>$text(180,true),'description'=>$text(4000))),
        'partner_application' => $base(array('organization_name'=>$text(180,true),'contact'=>$text(180,true),'partner_types'=>array('type'=>'array','required'=>true),'markets'=>$arr(),'organization_id'=>$integer(),'review_reason'=>$text(1000),'reviewed_by'=>$integer(),'review_hash'=>$text(64)), 'read'),
        'rfq' => $base(array('title'=>$text(180,true),'requirements'=>$text(4000,true),'quantity'=>$integer(true),'deadline'=>$date(true),'recipients'=>$arr())),
        'supplier_quote' => $base(array('rfq_id'=>$text(64,true),'amount_irr'=>$integer(true),'expiry'=>$date(true),'terms'=>$text(2000))),
        'production' => $base(array('rfq_id'=>$text(64,true),'quote_id'=>$text(64,true),'art_version'=>$text(100),'sample_approved'=>array('type'=>'bool'),'milestones'=>$arr(),'shipment_ref'=>$text(180),'inspection'=>$text(2000),'invoice_ref'=>$text(180),'payout_evidence'=>$text(180))),
        'finance_case' => $base(array('kind'=>$text(60,true),'amount_irr'=>$integer(true),'reference'=>$text(120,true),'reason'=>$text(2000))),
        'support_case' => array('fields'=>array('state'=>array('type'=>'string','enum'=>array('new','triaged','waiting_customer','waiting_supplier','in_progress','resolved','closed','draft','submitted','approved','rejected')),'category'=>$text(80,true),'subject'=>$text(180,true),'message'=>$text(4000,true),'booking_id'=>$text(64),'priority'=>array('type'=>'string','enum'=>array('low','normal','urgent')),'next_action'=>$text(1000),'assigned_user'=>$integer(),'resolution_code'=>$text(80)), 'capability'=>'read','initial_states'=>array('new'),'transitions'=>array('state'=>array())),
    );
    foreach (array('finance_case','certificate','attendance','assessment','event_checkin','production','rfq') as $agency_type) $schemas[$agency_type]['organization_roles']=array('read'=>array(),'write'=>array());
    return $schemas;
}
