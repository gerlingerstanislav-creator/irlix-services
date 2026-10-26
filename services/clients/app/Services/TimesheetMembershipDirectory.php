<?php
namespace App\Services;
use App\Models\ClientMember;
use App\Models\ClientAccountDelegation;
use App\Models\ReportingPeriod;
final class TimesheetMembershipDirectory {
    public function snapshot(): array {
    $rows=ClientMember::query()->from('client_members as cm')
        ->leftJoin('member_terms as mt','mt.client_member_id','=','cm.id')
        ->join('clients as c','c.id','=','cm.client_id')
        ->select(['cm.id as client_member_id','cm.client_id','cm.employee_id',
            'cm.partner_specialist_id','cm.specialist_name','c.name as client_name',
            'c.account_employee_id','mt.id as member_term_id','mt.valid_from','mt.valid_to'])
        ->orderBy('cm.id')->orderBy('mt.valid_from')->get()->map(fn($row)=> (static function(array $r) { $r = (object) $r; return [
            'client_member_id'=>(int)$r->client_member_id,'client_id'=>(int)$r->client_id,
            'employee_id'=>$r->employee_id===null?null:(int)$r->employee_id,
            'partner_specialist_id'=>$r->partner_specialist_id===null?null:(int)$r->partner_specialist_id,
            'specialist_name'=>$r->specialist_name,'client_name'=>$r->client_name,
            'account_employee_id'=>$r->account_employee_id===null?null:(int)$r->account_employee_id,
            'member_term_id'=>$r->member_term_id===null?null:(int)$r->member_term_id,
            'valid_from'=>$r->valid_from===null?null:substr((string)$r->valid_from,0,10),
            'valid_to'=>$r->valid_to===null?null:substr((string)$r->valid_to,0,10),
        ]; })($row->getRawOriginal()))->values();
    $delegations=ClientAccountDelegation::query()
        ->whereNull('revoked_at')->whereDate('starts_at','<=',today())
        ->whereDate('ends_at','>=',today())->get()->groupBy('client_id');
    $items=$rows->map(fn($r)=>$r+[
        'delegated_account_employee_ids'=>($delegations[$r['client_id']]??collect())
            ->pluck('to_employee_id')->map(fn($id)=>(int)$id)->values()->all()
    ])->values();
    $periods=ReportingPeriod::query()->where('status','<>','Новый')
        ->get(['id','client_id','period_start','period_end','status','timesheets_sent_at'])
        ->map(fn($entry)=> (static function (array $r) { $r=(object)$r; return ['id'=>(int)$r->id,'client_id'=>(int)$r->client_id,
            'period_start'=>substr((string)$r->period_start,0,10),
            'period_end'=>substr((string)$r->period_end,0,10),'status'=>$r->status,
            'timesheets_sent_at'=>$r->timesheets_sent_at]; })($entry->getRawOriginal()))->values();
    return [
        'schema_version'=>2,'revision'=>hash('sha256',json_encode($items,JSON_THROW_ON_ERROR)),
        'complete'=>true,'count'=>$items->count(),'data'=>$items,
        'locked_reporting_periods_complete'=>true,
        'locked_reporting_periods_count'=>$periods->count(),
        'locked_reporting_periods'=>$periods,
    ];
    }
}
