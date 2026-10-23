<?php

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/commercial-data', function (Request $request) {
    $data=$request->validate([
        'from'=>['required','date'],'to'=>['required','date','after_or_equal:from'],
        'client_id'=>['nullable','integer','min:1'],
    ]);
    // Clients is source of ownership for *both* temporary legacy project facts
    // and new client-member facts. An incomplete catalog must fail closed.
    $legacy=app(\App\Support\AssignmentsDirectory::class)->snapshot();
    $ownership=$legacy['project_clients'];
    $memberSnapshot=app(\App\Support\ClientMemberDirectory::class)->snapshot();
    $members=[];
    foreach($memberSnapshot['members'] as $row) {
        $id=(int)$row['client_member_id'];
        $members[$id]??=['client_id'=>(int)$row['client_id'],
            'partner_specialist_id'=>$row['partner_specialist_id'],
            'employee_id'=>$row['employee_id'],
            'specialist_name'=>$row['specialist_name']];
        if($members[$id]['client_id']!==(int)$row['client_id'])
            throw new \RuntimeException('Inconsistent ClientMember ownership');
    }
    $query=DB::table('timesheet_entries')->whereBetween('work_date',[$data['from'],$data['to']])
        ->orderBy('work_date')->orderBy('id');
    if(!empty($data['client_id'])) {
        $projectIds=array_keys(array_filter($ownership,fn($id)=>(int)$id===(int)$data['client_id']));
        $memberIds=array_keys(array_filter($members,fn($m)=>$m['client_id']===(int)$data['client_id']));
        $query->where(function($q)use($projectIds,$memberIds){
            $q->whereIn('project_id',$projectIds?:[0])
              ->orWhereIn('client_member_id',$memberIds?:[0]);
        });
    }
    $rows=$query->get()->map(function($entry)use($ownership,$members,$memberSnapshot){
        $m=$entry->client_member_id===null?null:($members[(int)$entry->client_member_id]??null);
        $entry->client_id=$entry->client_member_id===null
            ?($ownership[(int)$entry->project_id]??null):($m['client_id']??null);
        $entry->partner_specialist_id=$m['partner_specialist_id']??null;
        if($entry->client_member_id!==null) {
            $terms=array_values(array_filter($memberSnapshot['members'],fn($term)=>
                (int)$term['client_member_id']===(int)$entry->client_member_id &&
                $term['valid_from']!==null && $term['valid_from']<=$entry->work_date &&
                (empty($term['valid_to'])||$term['valid_to']>=$entry->work_date)));
            $entry->term_conflict=count($terms)!==1;
            $entry->member_term_id=count($terms)===1?$terms[0]['member_term_id']:null;
        } else {
            $entry->term_conflict=false;
            $entry->member_term_id=null;
        }
        return $entry;
    })->values();
    $employeeIds=$rows->pluck('employee_id')->filter(fn($x)=>$x!==null)->unique()->values()->all();
    $projectIds=$rows->pluck('project_id')->filter(fn($x)=>$x!==null)->unique()->values()->all();
    $memberIds=$rows->pluck('client_member_id')->filter(fn($x)=>$x!==null)->unique()->values()->all();
    $start=Carbon::parse($data['from'])->startOfMonth();
    $last=Carbon::parse($data['to'])->startOfMonth();$months=[];
    while($start->lte($last)){$months[]=$start->toDateString();$start->addMonth();}
    $approvals=DB::table('final_approvals')->whereIn('month',$months)
        ->where(function($q)use($employeeIds,$projectIds,$memberIds){
            $q->where(function($inner)use($employeeIds,$projectIds){
                $inner->whereIn('employee_id',$employeeIds?:[0])->whereIn('project_id',$projectIds?:[0]);
            })->orWhereIn('client_member_id',$memberIds?:[0]);
        })->get();
    $exceptions=DB::table('final_approval_exceptions')
        ->whereBetween('work_date',[$data['from'],$data['to']])
        ->where(function($q)use($memberIds,$employeeIds,$projectIds){
            $q->whereIn('client_member_id',$memberIds?:[0])
              ->orWhere(function($inner)use($employeeIds,$projectIds){
                  $inner->whereIn('employee_id',$employeeIds?:[0])
                      ->whereIn('project_id',$projectIds?:[0]);
              });
        })->get();
    return response()->json(['data'=>[
        'from'=>$data['from'],'to'=>$data['to'],'entries'=>$rows,
        'final_approvals'=>$approvals,'final_approval_exceptions'=>$exceptions,
        'client_member_catalog_complete'=>true
    ]]);
});
