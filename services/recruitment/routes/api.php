<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['data' => ['status' => 'ok', 'service' => 'recruitment']]);

Route::get('/workspace', function () {
    $candidates = DB::table('candidates')->orderByDesc('updated_at')->get()->map(function ($candidate) {
        $candidate->stack = json_decode($candidate->stack_json ?: '[]', true) ?: [];
        unset($candidate->stack_json);
        $candidate->pools = DB::table('candidate_talent_pool as cp')
            ->join('talent_pools as p', 'p.id', '=', 'cp.talent_pool_id')
            ->where('cp.candidate_id', $candidate->id)
            ->pluck('p.name')->values();
        $process = DB::table('hiring_processes')
            ->where('candidate_id', $candidate->id)
            ->orderByDesc('created_at')
            ->first(['id', 'recruitment_request_id', 'stage_type', 'stage_label', 'stage_changed_at']);
        $candidate->hiring_process = $process;
        return $candidate;
    });
    $requests = DB::table('recruitment_requests')->orderByDesc('created_at')->get()->map(function ($request) {
        $request->candidates_count = DB::table('hiring_processes')->where('recruitment_request_id', $request->id)->count();
        return $request;
    });
    $pools = DB::table('talent_pools')->orderBy('name')->get()->map(function ($pool) {
        $pool->count = DB::table('candidate_talent_pool')->where('talent_pool_id', $pool->id)->count();
        return $pool;
    });
    $employment = DB::table('employment_requests')->orderBy('planned_start_date')->get();
    $onboarding = DB::table('onboarding_processes')->orderBy('planned_start_date')->get();
    $tasks = DB::table('tasks')->whereNull('completed_at')->orderBy('due_at')->get();
    $funnel = DB::table('hiring_processes')->selectRaw('stage_type, count(*) as count')->groupBy('stage_type')->pluck('count', 'stage_type');
    return ['data' => compact('candidates', 'requests', 'pools', 'employment', 'onboarding', 'tasks', 'funnel')];
});

Route::get('/candidates/{id}', function (int $id) {
    $candidate = DB::table('candidates')->find($id);
    abort_if(!$candidate, 404, 'Candidate not found');
    $candidate->stack = json_decode($candidate->stack_json ?: '[]', true) ?: [];
    unset($candidate->stack_json);
    $activities = DB::table('activities')->where('candidate_id', $id)->orderByDesc('occurred_at')->get();
    $processes = DB::table('hiring_processes')->where('candidate_id', $id)->orderByDesc('created_at')->get();
    $pools = DB::table('candidate_talent_pool as cp')->join('talent_pools as p', 'p.id', '=', 'cp.talent_pool_id')->where('cp.candidate_id', $id)->select('p.id', 'p.name')->get();
    return ['data' => compact('candidate', 'activities', 'processes', 'pools')];
});

Route::post('/candidates', function (Request $request) {
    $data = $request->validate([
        'full_name' => ['required','string','max:255'], 'role' => ['nullable','string','max:255'], 'grade' => ['nullable','string','max:64'],
        'city' => ['nullable','string','max:128'], 'salary_expectation' => ['nullable','string','max:128'], 'source' => ['nullable','string','max:128'],
        'recruiter_name' => ['nullable','string','max:255'], 'email' => ['nullable','email','max:255'], 'phone' => ['nullable','string','max:64'], 'stack' => ['array'],
    ]);
    $id = DB::table('candidates')->insertGetId([
        'full_name'=>$data['full_name'], 'role'=>$data['role'] ?? null, 'grade'=>$data['grade'] ?? null, 'city'=>$data['city'] ?? null,
        'salary_expectation'=>$data['salary_expectation'] ?? null, 'source'=>$data['source'] ?? null, 'recruiter_name'=>$data['recruiter_name'] ?? null,
        'email'=>$data['email'] ?? null, 'phone'=>$data['phone'] ?? null, 'stack_json'=>json_encode($data['stack'] ?? [], JSON_UNESCAPED_UNICODE),
        'created_at'=>now(), 'updated_at'=>now(),
    ]);
    return response()->json(['data' => DB::table('candidates')->find($id)], 201);
});

Route::post('/requests', function (Request $request) {
    $data = $request->validate([
        'title'=>['required','string','max:255'], 'department_name'=>['required','string','max:255'], 'manager_name'=>['required','string','max:255'],
        'recruiter_name'=>['nullable','string','max:255'], 'positions_count'=>['required','integer','min:1','max:100'], 'priority'=>['required','in:low,medium,high'],
        'description'=>['nullable','string'],
    ]);
    $id = DB::table('recruitment_requests')->insertGetId([...$data, 'status'=>'new', 'created_at'=>now(), 'updated_at'=>now()]);
    return response()->json(['data' => DB::table('recruitment_requests')->find($id)], 201);
});

Route::post('/candidates/{id}/activities', function (Request $request, int $id) {
    abort_if(!DB::table('candidates')->where('id', $id)->exists(), 404, 'Candidate not found');
    $data = $request->validate(['type'=>['required','string','max:64'], 'channel'=>['nullable','string','max:64'], 'result'=>['nullable','string','max:128'], 'comment'=>['nullable','string'], 'next_action_at'=>['nullable','date']]);
    $identity = $request->attributes->get('identity', []);
    $activityId = DB::table('activities')->insertGetId([...$data, 'candidate_id'=>$id, 'actor'=>(string)($identity['preferred_username'] ?? $identity['email'] ?? 'unknown'), 'occurred_at'=>now(), 'created_at'=>now(), 'updated_at'=>now()]);
    DB::table('candidates')->where('id', $id)->update(['last_activity_at'=>now(), 'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('activities')->find($activityId)], 201);
});

Route::post('/hiring-processes/{id}/stage', function (Request $request, int $id) {
    $data = $request->validate(['stage_type'=>['required','in:new,outreach,contact,hr_interview,manager_interview,decision,offer,accepted,rejected,hired'], 'stage_label'=>['required','string','max:128']]);
    abort_if(!DB::table('hiring_processes')->where('id', $id)->exists(), 404, 'Hiring process not found');
    DB::transaction(function () use ($id, $data, $request) {
        DB::table('hiring_processes')->where('id', $id)->update([...$data, 'stage_changed_at'=>now(), 'updated_at'=>now()]);
        $identity = $request->attributes->get('identity', []);
        DB::table('stage_history')->insert(['hiring_process_id'=>$id, 'stage_type'=>$data['stage_type'], 'stage_label'=>$data['stage_label'], 'actor'=>(string)($identity['preferred_username'] ?? $identity['email'] ?? 'unknown'), 'created_at'=>now(), 'updated_at'=>now()]);
    });
    return ['data'=>DB::table('hiring_processes')->find($id)];
});

Route::post('/employment-requests', function (Request $request) {
    $data = $request->validate(['candidate_id'=>['required','integer'], 'hiring_process_id'=>['nullable','integer'], 'role'=>['required','string','max:255'], 'department_name'=>['required','string','max:255'], 'manager_name'=>['required','string','max:255'], 'planned_start_date'=>['required','date']]);
    abort_if(!DB::table('candidates')->where('id', $data['candidate_id'])->exists(), 422, 'Candidate not found');
    $id = DB::table('employment_requests')->insertGetId([...$data, 'stage'=>'request', 'created_at'=>now(), 'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('employment_requests')->find($id)], 201);
});
