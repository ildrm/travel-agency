<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Eligibility {
    public static function register(): void {
        Records::register_type('traveler',['fields'=>[
            'name'=>['type'=>'string','required'=>true,'max'=>180],
            'birth_date'=>['type'=>'string','required'=>true,'max'=>10],
            'guardian_name'=>['type'=>'string','max'=>180],
            'guardian_attested'=>['type'=>'bool']
        ],'capability'=>'read']);
    }
    public static function date(string $value): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m)) { return false; }
        return checkdate((int)$m[2],(int)$m[3],(int)$m[1]) && (int)$m[1] >= 1900;
    }
    public static function policy(array $policy): true|\WP_Error {
        if (!$policy) { return true; }
        foreach ($policy as $key=>$value) {
            if (!in_array($key,['min_age','max_age','age_on','guardian_below'],true)) { return new \WP_Error('atlas_invalid_eligibility','Unsupported eligibility rule.', ['status'=>422]); }
            if ($key!=='age_on' && (!is_int($value) || $value<0 || $value>125)) { return new \WP_Error('atlas_invalid_eligibility','Age thresholds must be integers from 0 to 125.', ['status'=>422]); }
        }
        if (!isset($policy['age_on']) || !is_string($policy['age_on']) || !self::date($policy['age_on'])) { return new \WP_Error('atlas_invalid_eligibility','A valid age-on date is required.', ['status'=>422]); }
        if (isset($policy['guardian_below']) && $policy['guardian_below']<18) { return new \WP_Error('atlas_invalid_eligibility','Guardian threshold cannot be below 18.', ['status'=>422]); }
        if (isset($policy['min_age'],$policy['max_age']) && $policy['min_age']>$policy['max_age']) { return new \WP_Error('atlas_invalid_eligibility','Minimum age cannot exceed maximum age.', ['status'=>422]); }
        return true;
    }
    /** Participant data is self-declared, never a claim of identity verification. */
    public static function evaluate(array $policy,array $fields): true|\WP_Error {
        $valid=self::policy($policy); if (is_wp_error($valid)) return $valid;
        $dob=$fields['birth_date']??'';
        if (!isset($fields['name']) || !is_string($fields['name']) || trim($fields['name'])==='') return new \WP_Error('atlas_invalid_participant','Traveler name is required.', ['status'=>422]);
        if (!is_string($dob) || !self::date($dob)) return new \WP_Error('atlas_invalid_participant','Traveler birth date is invalid.', ['status'=>422]);
        if (!$policy) return true;
        if ($dob>$policy['age_on']) return new \WP_Error('atlas_ineligible','Birth date is after the eligibility date.', ['status'=>422]);
        $age=(new \DateTimeImmutable($dob,new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($policy['age_on'],new \DateTimeZone('UTC')))->y;
        if ((isset($policy['min_age']) && $age<$policy['min_age']) || (isset($policy['max_age']) && $age>$policy['max_age'])) return new \WP_Error('atlas_ineligible','Traveler does not meet the age policy.', ['status'=>422]);
        if ($age<($policy['guardian_below']??18) && (empty(trim((string)($fields['guardian_name']??''))) || ($fields['guardian_attested']??false)!==true)) return new \WP_Error('atlas_guardian_required','A guardian name and self-attestation are required.', ['status'=>422]);
        return true;
    }
    /** Ownership remains here; evaluate() only evaluates already-authorized field data. */
    public static function validate(array $offer,int $userId,int $qty,array $refs): true|\WP_Error {
        $policy=$offer['eligibility'] ?? []; if (!is_array($policy)) { return new \WP_Error('atlas_ineligible','Eligibility policy invalid.', ['status'=>422]); }
        $valid=self::policy($policy); if (is_wp_error($valid)) { return $valid; }
        if (!$policy && !$refs) { return true; }
        if (count($refs)!==$qty) { return new \WP_Error('atlas_participants_required','One distinct traveler is required per seat.', ['status'=>422]); }
        foreach ($refs as $id) { if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/',$id)) { return new \WP_Error('atlas_invalid_participant','Invalid traveler reference.', ['status'=>422]); } }
        if (count(array_unique($refs,SORT_STRING))!==$qty) { return new \WP_Error('atlas_participants_required','One distinct traveler is required per seat.', ['status'=>422]); }
        foreach ($refs as $id) {
            $person=Records::get('traveler',$id,$userId);
            if (is_wp_error($person) || $person['org_id']!==0 || $person['owner_id']!==$userId) { return new \WP_Error('atlas_invalid_participant','Traveler unavailable.', ['status'=>404]); }
            $eligible=self::evaluate($policy,$person['fields']); if (is_wp_error($eligible)) return $eligible;
        }
        return true;
    }
}
