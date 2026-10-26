<?php
namespace App\Support;

use App\Models\ClientMember;

/** Reject partial or malformed service responses before calculating or freezing money. */
final class CommercialSnapshot
{
    public static function validate(mixed $data,string $from,string $to,?int $clientId=null): array
    {
        $valid=is_array($data) && ($data['complete']??false)===true && is_int($data['count']??null);
        foreach(['entries','final_approvals','final_approval_exceptions']as $key) {
            $valid=$valid && is_array($data[$key]??null) && array_is_list($data[$key]);
        }
        abort_unless($valid && $data['count']===count($data['entries']),503,'Timesheets returned an incomplete commercial snapshot');
        $id=static fn($v):bool=>(is_int($v)&&$v>0)||(is_string($v)&&preg_match('/^[1-9][0-9]*$/D',$v)===1);
        $date=static function($v):bool {
            if(!is_string($v)||preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$v,$parts)!==1)return false;
            return checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]);
        };
        $rows=array_merge($data['entries'],$data['final_approvals'],$data['final_approval_exceptions']);
        foreach($rows as $row) abort_unless(is_array($row)&&$id($row['client_member_id']??null),503,'Timesheets returned an invalid membership identity');
        $members=ClientMember::query()->whereIn('id',array_column($rows,'client_member_id'))->get()->keyBy('id');
        $seen=[];
        foreach($data['entries']as $row) {
            $member=$members->get($row['client_member_id']);
            $employee=$row['employee_id']??null;
            $hours=$row['hours']??null;
            $valid=$member && ($clientId===null||(int)$member->client_id===$clientId)
                && array_key_exists('employee_id',$row) && ($employee===null||$id($employee))
                && ($member->employee_id===null?$employee===null:($employee!==null&&(int)$employee===(int)$member->employee_id))
                && $date($row['work_date']??null) && $row['work_date']>=$from && $row['work_date']<=$to
                && (is_int($hours)||is_float($hours)||is_string($hours)) && is_numeric($hours)
                && is_finite((float)$hours) && (float)$hours>=0 && (float)$hours<=24
                && preg_match('/^\d+(?:\.\d{1,2})?$/D',(string)$hours)===1
                && array_key_exists('member_term_id',$row) && ($row['member_term_id']===null||$id($row['member_term_id']))
                && (!isset($row['term_conflict'])||is_bool($row['term_conflict']))
                && (!isset($row['description'])||is_string($row['description']));
            if(isset($row['client_id']))$valid=$valid&&$id($row['client_id'])&&(int)$row['client_id']===(int)($member->client_id??0);
            if(isset($row['partner_specialist_id']))$valid=$valid&&$id($row['partner_specialist_id'])&&(int)$row['partner_specialist_id']===(int)($member->partner_specialist_id??0);
            $key=$row['client_member_id'].':'.($row['work_date']??'');
            abort_unless($valid&&!isset($seen[$key]),503,'Timesheets returned an invalid commercial fact');
            $seen[$key]=true;
        }
        foreach(['final_approvals'=>'month','final_approval_exceptions'=>'work_date']as $key=>$dateField) {
            $seen=[];
            foreach($data[$key]as $row) {
                $member=$members->get($row['client_member_id']);
                $value=$row[$dateField]??null;
                $inRange=$date($value) && ($dateField==='month'
                    ? substr($value,8,2)==='01'&&substr($value,0,7)>=substr($from,0,7)&&substr($value,0,7)<=substr($to,0,7)
                    : $value>=$from&&$value<=$to);
                $identity=$row['client_member_id'].':'.($value??'');
                abort_unless($member&&($clientId===null||(int)$member->client_id===$clientId)&&$inRange&&!isset($seen[$identity]),503,'Timesheets returned an invalid commercial approval');
                $seen[$identity]=true;
            }
        }
        return $data;
    }
}
