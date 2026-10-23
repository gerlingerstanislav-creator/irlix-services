<?php
/**
 * Project → ClientMember fact rekey.
 * Default: read-only dry run. Apply only during coordinated maintenance and
 * after Clients + Timesheets expand migrations. Never runs on boot/deploy.
 *
 * php bin/convert-project-hours.php
 * TIMESHEETS_MAINTENANCE_LOCK=1 php bin/convert-project-hours.php --apply
 */
use App\Support\AssignmentsDirectory;
use App\Support\ClientMemberDirectory;
use App\Support\TimeHours;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply=in_array('--apply',$argv,true);
if($apply && getenv('TIMESHEETS_MAINTENANCE_LOCK')!=='1') {
    throw new RuntimeException('Refusing writes: explicit maintenance lock is required');
}
$legacy=app(AssignmentsDirectory::class)->snapshot();
$members=app(ClientMemberDirectory::class)->snapshot();
$clientByProject=$legacy['project_clients'];
$clientMembers=[];
$terms=[];
foreach($members['members'] as $row) {
    if($row['employee_id']===null) continue;
    $key=$row['employee_id'].':'.$row['client_id'];
    if(isset($clientMembers[$key])&&$clientMembers[$key]!==$row['client_member_id'])
        throw new RuntimeException('Multiple ClientMembers for employee/client '.$key);
    $clientMembers[$key]=$row['client_member_id'];
    $terms[$row['client_member_id']][]=$row;
}
$source=DB::table('timesheet_entries')->whereNotNull('project_id')
    ->orderBy('employee_id')->orderBy('work_date')->orderBy('id')->get();
$groups=[];$conflicts=[];
$fail=function(string $code,array $data)use(&$conflicts):void {
    $conflicts[]=['code'=>$code]+$data;
};
foreach($source as $row) {
    $clientId=$clientByProject[(int)$row->project_id]??null;
    $memberId=$clientId===null?null:($clientMembers[$row->employee_id.':'.$clientId]??null);
    if(!$memberId) {
        $fail('IDENTITY_UNRESOLVED',['entry_id'=>$row->id,'employee_id'=>$row->employee_id,'project_id'=>$row->project_id]);
        continue;
    }
    $date=substr((string)$row->work_date,0,10);
    $valid=array_values(array_filter($terms[$memberId]??[],static fn($term)=>
        $term['valid_from']<=$date && (empty($term['valid_to'])||$term['valid_to']>=$date)));
    if(count($valid)!==1) {
        $fail('TERMS_UNRESOLVED',['entry_id'=>$row->id,'client_member_id'=>$memberId,'date'=>$date]);
        continue;
    }
    $key=$memberId.':'.$date;
    $groups[$key]??=['client_member_id'=>(int)$memberId,'employee_id'=>(int)$row->employee_id,
        'client_id'=>(int)$clientId,'work_date'=>$date,'cents'=>0,'source'=>[]];
    $groups[$key]['cents']+=TimeHours::cents((string)$row->hours);
    $groups[$key]['source'][]=$row;
}
$new=DB::table('timesheet_entries')->whereNotNull('client_member_id')->get();
$newKeys=[];
$dayTotals=[];
foreach($new as $row) {
    $newKeys[$row->client_member_id.':'.substr((string)$row->work_date,0,10)]=true;
    if($row->employee_id!==null) {
        $day=$row->employee_id.':'.substr((string)$row->work_date,0,10);
        $dayTotals[$day]=($dayTotals[$day]??0)+TimeHours::cents((string)$row->hours);
    }
}
$monthExpected=[];
foreach($groups as $key=>$group) {
    $day=$group['employee_id'].':'.$group['work_date'];
    $dayTotals[$day]=($dayTotals[$day]??0)+$group['cents'];
    if($group['cents']>2400) $fail('CLIENT_DAY_OVER_24',['key'=>$key,'cents'=>$group['cents']]);
    if(isset($newKeys[$key])) $fail('TARGET_ALREADY_EXISTS',['key'=>$key]);
    $month=substr($group['work_date'],0,7).'-01';
    foreach($group['source'] as $row) $monthExpected[$group['employee_id'].':'.$group['client_member_id'].':'.$month][(int)$row->project_id]=true;
}
foreach($dayTotals as $day=>$cents)
    if($cents>2400) $fail('EMPLOYEE_DAY_OVER_24',['key'=>$day,'cents'=>$cents]);
// Sending a client period freezes its original financial history. An operator
// needs a separately audited correction procedure, not this bulk rekey.
foreach($source as $row) {
    $cid=$clientByProject[(int)$row->project_id]??null;
    if($cid===null)continue;
    foreach($members['locked_periods'] as $period){
        $d=substr((string)$row->work_date,0,10);
        if((int)$period['client_id']===(int)$cid && $period['period_start']<=$d && $period['period_end']>=$d)
            $fail('REPORTING_PERIOD_LOCKED',['entry_id'=>$row->id,'period_id'=>$period['id']]);
    }
}
$approvedSource=DB::table('final_approvals')->whereNotNull('project_id')->get();
$exceptions=DB::table('final_approval_exceptions')->whereNotNull('project_id')->get();
$sourceApprovals=[];
foreach($approvedSource as $approval){
    $sourceApprovals[$approval->employee_id.':'.$approval->project_id.':'.substr((string)$approval->month,0,10)]=$approval;
}
$sourceExceptions=[];
foreach($exceptions as $exception){
    $sourceExceptions[$exception->employee_id.':'.$exception->project_id.':'.substr((string)$exception->work_date,0,10)]=true;
}
$convertApprovals=[];
foreach($monthExpected as $key=>$projects){
    [$employeeId,$memberId,$month]=explode(':',$key);
    $ok=true;$provenance=[];
    foreach(array_keys($projects) as $projectId) {
        $original=$sourceApprovals[$employeeId.':'.$projectId.':'.$month]??null;
        if(!$original) {$ok=false;continue;}
        $provenance[]=[$original->approved_by,$original->approved_at];
    }
    // A single actor/timestamp is evidence of one historical approval.
    // Mixed approvals are not silently invented as a new single actor.
    if(count(array_unique(array_map('json_encode',$provenance)))!==1)$ok=false;
    foreach($groups as $group) {
        if($group['employee_id']!==(int)$employeeId||$group['client_member_id']!==(int)$memberId ||
            !str_starts_with($group['work_date'],substr($month,0,7)))continue;
        foreach($group['source'] as $row)
            if(isset($sourceExceptions[$employeeId.':'.$row->project_id.':'.$group['work_date']]))$ok=false;
    }
    if($ok)$convertApprovals[]=['employee_id'=>(int)$employeeId,'client_member_id'=>(int)$memberId,
        'month'=>$month,'approved_by'=>$provenance[0][0],'approved_at'=>$provenance[0][1]];
}
$summary=['mode'=>$apply?'apply':'dry-run','source_rows'=>$source->count(),'target_groups'=>count($groups),
    'merged_source_rows'=>$source->count()-count($groups),'proposed_month_approvals'=>count($convertApprovals),
    'conflict_count'=>count($conflicts),'conflicts'=>array_slice($conflicts,0,100)];
if($conflicts) {
    fwrite(STDERR,json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
    exit(2);
}
if(!$apply) {echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit(0);}
$written=0;
foreach($groups as $group) {
    DB::transaction(function()use($group,&$written):void {
        TimeHours::lockDay($group['employee_id'],$group['work_date']);
        $originalIds=array_map(fn($r)=>(int)$r->id,$group['source']);
        $locked=DB::table('timesheet_entries')->whereIn('id',$originalIds)->orderBy('id')->lockForUpdate()->get();
        if($locked->count()!==count($originalIds)) throw new RuntimeException('Source group changed during rekey');
        foreach($group['source'] as $row){
            $current=$locked->firstWhere('id',$row->id);
            if(!$current || (string)$current->hours!==(string)$row->hours ||
                (int)$current->project_id!==(int)$row->project_id ||
                $current->description!==$row->description)
                throw new RuntimeException('Fact was changed during conversion: '.$row->id);
        }
        if(DB::table('timesheet_entries')->where('client_member_id',$group['client_member_id'])
            ->where('work_date',$group['work_date'])->exists())
            throw new RuntimeException('A target group appeared concurrently');
        // Preserve every comment verbatim and source ID; even zero-hour source
        // lines and duplicate identical descriptions remain distinguishable.
        $notes=[];
        foreach($group['source'] as $row) {
            $notes[]='[source timesheet #'.$row->id.', project #'.$row->project_id.']'.
                ($row->description!==null?"\n".$row->description:'');
        }
        $target=DB::table('timesheet_entries')->insertGetId([
            'employee_id'=>$group['employee_id'],'project_id'=>null,
            'client_member_id'=>$group['client_member_id'],
            'work_date'=>$group['work_date'],'hours'=>TimeHours::format($group['cents']),
            'description'=>implode("\n\n",$notes),'term_conflict'=>false,
            'created_at'=>now(),'updated_at'=>now()
        ]);
        foreach($group['source'] as $row){
            DB::table('timesheet_legacy_sources')->insert([
                'legacy_entry_id'=>$row->id,'target_entry_id'=>$target,
                'employee_id'=>$row->employee_id,'project_id'=>$row->project_id,
                'work_date'=>$group['work_date'],'hours'=>$row->hours,
                'description'=>$row->description,'created_at'=>now(),'updated_at'=>now()
            ]);
        }
        DB::table('timesheet_entries')->whereIn('id',$originalIds)->delete();
        DB::table('timesheet_audit')->insert([
            'action'=>'client_member_rekey','actor_employee_id'=>null,
            'employee_id'=>$group['employee_id'],'project_id'=>null,
            'client_member_id'=>$group['client_member_id'],'work_date'=>$group['work_date'],
            'before'=>json_encode(['source_ids'=>$originalIds],JSON_UNESCAPED_UNICODE),
            'after'=>json_encode(['target_entry_id'=>$target,'hours'=>TimeHours::format($group['cents'])]),
            'created_at'=>now()
        ]);
        $written++;
    });
}
foreach($convertApprovals as $a){
    DB::table('final_approvals')->updateOrInsert([
        'client_member_id'=>$a['client_member_id'],'month'=>$a['month']
    ],['employee_id'=>$a['employee_id'],'project_id'=>null,
       'approved_by'=>$a['approved_by'],'approved_at'=>$a['approved_at'],
       'created_at'=>now(),'updated_at'=>now()]);
}
$summary['written_groups']=$written;
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
