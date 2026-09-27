<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['data' => ['status' => 'ok', 'service' => 'recruitment']]);

$actor = static function (Request $request): string {
    $identity = $request->attributes->get('identity', []);
    return (string)($identity['preferred_username'] ?? $identity['email'] ?? 'unknown');
};

Route::get('/workspace', function () {
    $candidates = DB::table('candidates')->orderByDesc('updated_at')->get()->map(function ($candidate) {
        $candidate->stack = json_decode($candidate->stack_json ?: '[]', true) ?: [];
        unset($candidate->stack_json);
        $candidate->pools = DB::table('candidate_talent_pool as cp')->join('talent_pools as p', 'p.id', '=', 'cp.talent_pool_id')->where('cp.candidate_id', $candidate->id)->pluck('p.name')->values();
        $candidate->hiring_process = DB::table('hiring_processes')->where('candidate_id', $candidate->id)->orderByDesc('created_at')->first(['id','recruitment_request_id','stage_type','stage_label','stage_changed_at','recruiter_name']);
        return $candidate;
    });
    $requests = DB::table('recruitment_requests')->orderByDesc('created_at')->get()->map(function ($request) {
        $request->candidates_count = DB::table('hiring_processes')->where('recruitment_request_id', $request->id)->count();
        return $request;
    });
    $pools = DB::table('talent_pools')->where('active', true)->orderBy('name')->get()->map(function ($pool) {
        $pool->count = DB::table('candidate_talent_pool')->where('talent_pool_id', $pool->id)->count();
        return $pool;
    });
    $employment = DB::table('employment_requests as e')->leftJoin('candidates as c','c.id','=','e.candidate_id')->select('e.*','c.full_name as candidate_name')->orderBy('planned_start_date')->get();
    $onboarding = DB::table('onboarding_processes')->orderBy('planned_start_date')->get();
    $tasks = DB::table('tasks')->whereNull('completed_at')->orderByRaw('due_at nulls last')->get();
    $interviews = DB::table('interviews as i')->join('hiring_processes as h','h.id','=','i.hiring_process_id')->join('candidates as c','c.id','=','h.candidate_id')->select('i.*','c.full_name as candidate_name')->orderBy('i.scheduled_at')->get();
    $offers = DB::table('offers as o')->join('hiring_processes as h','h.id','=','o.hiring_process_id')->join('candidates as c','c.id','=','h.candidate_id')->select('o.*','c.full_name as candidate_name')->orderByDesc('o.updated_at')->get();
    $funnel = DB::table('hiring_processes')->selectRaw('stage_type, count(*) as count')->groupBy('stage_type')->pluck('count', 'stage_type');
    return ['data' => compact('candidates','requests','pools','employment','onboarding','tasks','interviews','offers','funnel')];
});

Route::get('/candidates/{id}', function (int $id) {
    $candidate = DB::table('candidates')->find($id);
    abort_if(!$candidate, 404, 'Candidate not found');
    $candidate->stack = json_decode($candidate->stack_json ?: '[]', true) ?: [];
    unset($candidate->stack_json);
    $activities = DB::table('activities')->where('candidate_id', $id)->orderByDesc('occurred_at')->get();
    $processes = DB::table('hiring_processes')->where('candidate_id', $id)->orderByDesc('created_at')->get();
    $pools = DB::table('candidate_talent_pool as cp')->join('talent_pools as p','p.id','=','cp.talent_pool_id')->where('cp.candidate_id',$id)->select('p.id','p.name')->get();
    return ['data' => compact('candidate','activities','processes','pools')];
});

Route::post('/candidates', function (Request $request) {
    $data = $request->validate([
        'full_name'=>['required','string','max:255'],'role'=>['nullable','string','max:255'],'grade'=>['nullable','string','max:64'],'city'=>['nullable','string','max:128'],
        'salary_expectation'=>['nullable','string','max:128'],'source'=>['nullable','string','max:128'],'recruiter_name'=>['nullable','string','max:255'],'email'=>['nullable','email','max:255'],
        'phone'=>['nullable','string','max:64'],'stack'=>['array'],'stack.*'=>['string','max:128'],
    ]);
    $id = DB::table('candidates')->insertGetId([
        'full_name'=>$data['full_name'],'role'=>$data['role']??null,'grade'=>$data['grade']??null,'city'=>$data['city']??null,'salary_expectation'=>$data['salary_expectation']??null,
        'source'=>$data['source']??null,'recruiter_name'=>$data['recruiter_name']??null,'email'=>$data['email']??null,'phone'=>$data['phone']??null,
        'stack_json'=>json_encode($data['stack']??[], JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now(),
    ]);
    return response()->json(['data'=>DB::table('candidates')->find($id)], 201);
});

Route::post('/requests', function (Request $request) {
    $data = $request->validate([
        'title'=>['required','string','max:255'],'department_name'=>['required','string','max:255'],'manager_name'=>['required','string','max:255'],'recruiter_name'=>['nullable','string','max:255'],
        'positions_count'=>['required','integer','min:1','max:100'],'priority'=>['required','in:low,medium,high'],'description'=>['nullable','string'],
    ]);
    $id = DB::table('recruitment_requests')->insertGetId([...$data,'status'=>'new','created_at'=>now(),'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('recruitment_requests')->find($id)], 201);
});

Route::post('/candidates/{id}/activities', function (Request $request, int $id) use ($actor) {
    abort_if(!DB::table('candidates')->where('id',$id)->exists(), 404, 'Candidate not found');
    $data = $request->validate(['type'=>['required','string','max:64'],'channel'=>['nullable','string','max:64'],'result'=>['nullable','string','max:128'],'comment'=>['nullable','string'],'next_action_at'=>['nullable','date']]);
    $activityId = DB::table('activities')->insertGetId([...$data,'candidate_id'=>$id,'actor'=>$actor($request),'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    DB::table('candidates')->where('id',$id)->update(['last_activity_at'=>now(),'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('activities')->find($activityId)], 201);
});

Route::post('/hiring-processes/{id}/stage', function (Request $request, int $id) use ($actor) {
    $data = $request->validate(['stage_type'=>['required','in:new,outreach,contact,hr_interview,manager_interview,decision,offer,accepted,rejected,hired'],'stage_label'=>['required','string','max:128']]);
    abort_if(!DB::table('hiring_processes')->where('id',$id)->exists(), 404, 'Hiring process not found');
    DB::transaction(function () use ($id,$data,$request,$actor) {
        DB::table('hiring_processes')->where('id',$id)->update([...$data,'stage_changed_at'=>now(),'closed_at'=>in_array($data['stage_type'],['rejected','hired'],true)?now():null,'updated_at'=>now()]);
        DB::table('stage_history')->insert(['hiring_process_id'=>$id,'stage_type'=>$data['stage_type'],'stage_label'=>$data['stage_label'],'actor'=>$actor($request),'created_at'=>now(),'updated_at'=>now()]);
    });
    return ['data'=>DB::table('hiring_processes')->find($id)];
});

Route::post('/interviews', function (Request $request) {
    $data = $request->validate(['hiring_process_id'=>['required','integer'],'type'=>['required','string','max:64'],'scheduled_at'=>['required','date'],'participants'=>['nullable','string','max:255']]);
    abort_if(!DB::table('hiring_processes')->where('id',$data['hiring_process_id'])->exists(), 422, 'Hiring process not found');
    $id = DB::table('interviews')->insertGetId([...$data,'status'=>'scheduled','created_at'=>now(),'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('interviews')->find($id)],201);
});

Route::post('/interviews/{id}/evaluations', function (Request $request, int $id) use ($actor) {
    abort_if(!DB::table('interviews')->where('id',$id)->exists(),404,'Interview not found');
    $data = $request->validate(['hard_skills'=>['nullable','integer','min:1','max:5'],'soft_skills'=>['nullable','integer','min:1','max:5'],'motivation'=>['nullable','integer','min:1','max:5'],'recommendation'=>['nullable','string','max:64'],'comment'=>['nullable','string']]);
    $evaluationId = DB::table('evaluations')->insertGetId([...$data,'interview_id'=>$id,'author'=>$actor($request),'created_at'=>now(),'updated_at'=>now()]);
    DB::table('interviews')->where('id',$id)->update(['status'=>'completed','updated_at'=>now()]);
    return response()->json(['data'=>DB::table('evaluations')->find($evaluationId)],201);
});

Route::post('/offers', function (Request $request) {
    $data = $request->validate(['hiring_process_id'=>['required','integer'],'role'=>['required','string','max:255'],'compensation'=>['nullable','string','max:255'],'planned_start_date'=>['nullable','date']]);
    abort_if(!DB::table('hiring_processes')->where('id',$data['hiring_process_id'])->exists(),422,'Hiring process not found');
    $id = DB::table('offers')->insertGetId([...$data,'status'=>'draft','created_at'=>now(),'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('offers')->find($id)],201);
});

Route::patch('/offers/{id}', function (Request $request, int $id) {
    $data = $request->validate(['status'=>['required','in:draft,approval,ready,sent,accepted,rejected,withdrawn'],'compensation'=>['sometimes','nullable','string','max:255'],'planned_start_date'=>['sometimes','nullable','date']]);
    abort_if(!DB::table('offers')->where('id',$id)->exists(),404,'Offer not found');
    $update = [...$data,'updated_at'=>now()];
    if (($data['status']??null)==='sent') $update['sent_at']=now();
    if (in_array(($data['status']??null),['accepted','rejected'],true)) $update['decision_at']=now();
    DB::table('offers')->where('id',$id)->update($update);
    return ['data'=>DB::table('offers')->find($id)];
});

Route::post('/talent-pools', function (Request $request) {
    $data = $request->validate(['name'=>['required','string','max:255','unique:talent_pools,name'],'description'=>['nullable','string']]);
    $id = DB::table('talent_pools')->insertGetId([...$data,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('talent_pools')->find($id)],201);
});

Route::post('/talent-pools/{id}/candidates', function (Request $request, int $id) {
    $data = $request->validate(['candidate_id'=>['required','integer']]);
    abort_if(!DB::table('talent_pools')->where('id',$id)->exists(),404,'Talent pool not found');
    abort_if(!DB::table('candidates')->where('id',$data['candidate_id'])->exists(),422,'Candidate not found');
    DB::table('candidate_talent_pool')->updateOrInsert(['candidate_id'=>$data['candidate_id'],'talent_pool_id'=>$id],['updated_at'=>now(),'created_at'=>now()]);
    return response()->json(['data'=>['candidate_id'=>$data['candidate_id'],'talent_pool_id'=>$id]],201);
});

Route::post('/employment-requests', function (Request $request) {
    $data = $request->validate(['candidate_id'=>['required','integer'],'hiring_process_id'=>['nullable','integer'],'role'=>['required','string','max:255'],'department_name'=>['required','string','max:255'],'manager_name'=>['required','string','max:255'],'planned_start_date'=>['required','date']]);
    abort_if(!DB::table('candidates')->where('id',$data['candidate_id'])->exists(),422,'Candidate not found');
    $id = DB::table('employment_requests')->insertGetId([...$data,'stage'=>'request','created_at'=>now(),'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('employment_requests')->find($id)],201);
});

Route::patch('/employment-requests/{id}', function (Request $request, int $id) {
    $data = $request->validate(['stage'=>['required','in:request,review,approval,documents,ready,employed'],'employee_id'=>['sometimes','nullable','integer']]);
    abort_if(!DB::table('employment_requests')->where('id',$id)->exists(),404,'Employment request not found');
    DB::table('employment_requests')->where('id',$id)->update([...$data,'updated_at'=>now()]);
    return ['data'=>DB::table('employment_requests')->find($id)];
});

Route::patch('/onboarding/{id}', function (Request $request, int $id) {
    $data = $request->validate(['completed_steps'=>['sometimes','integer','min:0'],'total_steps'=>['sometimes','integer','min:0'],'next_action'=>['sometimes','nullable','string','max:255'],'status'=>['sometimes','in:active,blocked,completed']]);
    abort_if(!DB::table('onboarding_processes')->where('id',$id)->exists(),404,'Onboarding process not found');
    DB::table('onboarding_processes')->where('id',$id)->update([...$data,'updated_at'=>now()]);
    return ['data'=>DB::table('onboarding_processes')->find($id)];
});

Route::post('/tasks', function (Request $request) {
    $data = $request->validate(['title'=>['required','string','max:255'],'assignee'=>['nullable','string','max:255'],'entity_type'=>['nullable','string','max:64'],'entity_id'=>['nullable','integer'],'due_at'=>['nullable','date']]);
    $id = DB::table('tasks')->insertGetId([...$data,'created_at'=>now(),'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('tasks')->find($id)],201);
});

Route::post('/tasks/{id}/complete', function (int $id) {
    abort_if(!DB::table('tasks')->where('id',$id)->exists(),404,'Task not found');
    DB::table('tasks')->where('id',$id)->update(['completed_at'=>now(),'updated_at'=>now()]);
    return ['data'=>DB::table('tasks')->find($id)];
});
