<?php

use App\Support\CurrentEmployee;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

$tokenOf = fn (Request $request) => $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
$apiGet = function (Request $request, string $baseEnv, string $default, string $path, array $query = []) use ($tokenOf) {
    $base = rtrim((string) env($baseEnv, $default), '/');
    $response = Http::withToken((string) $tokenOf($request))->acceptJson()->timeout(8)->get($base.$path, $query);
    abort_unless($response->successful(), 503, "Dependency request failed: {$path} ({$response->status()})");
    return $response->json('data') ?? [];
};

$monthBounds = function (?string $month): array {
    $date = $month ? Carbon::parse($month.'-01') : now();
    $start = $date->copy()->startOfMonth();
    return [$start, $start->copy()->endOfMonth()];
};

$audit = function (string $action, ?int $actorId, ?int $employeeId = null, ?int $projectId = null, ?string $workDate = null, mixed $before = null, mixed $after = null): void {
    DB::table('timesheet_audit')->insert([
        'action' => $action,
        'actor_employee_id' => $actorId,
        'employee_id' => $employeeId,
        'project_id' => $projectId,
        'work_date' => $workDate,
        'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
    ]);
};

$directory = function (Request $request) use ($apiGet): array {
    $employees = collect($apiGet($request, 'EMPLOYEES_URL', 'http://employees:8000/api', '/employees'));
    $departments = collect($apiGet($request, 'EMPLOYEES_URL', 'http://employees:8000/api', '/departments'));
    return [$employees, $departments];
};

$assignments = function (Request $request) use ($apiGet): array {
    $overview = $apiGet($request, 'CLIENTS_URL', 'http://clients:8000/api', '/overview');
    $rows = [];
    foreach (($overview['clients'] ?? []) as $client) {
        foreach (($client['projects'] ?? []) as $project) {
            foreach (($project['members'] ?? []) as $member) {
                foreach (($member['terms'] ?? []) as $term) {
                    $rows[] = [
                        'employee_id' => (int) ($member['specialist_id'] ?? 0),
                        'employee_name' => $member['specialist_name'] ?? '',
                        'client_id' => (int) ($client['id'] ?? 0),
                        'client_name' => $client['name'] ?? 'Клиент',
                        'project_id' => (int) ($project['id'] ?? 0),
                        'project_name' => $project['name'] ?: ($client['name'] ?? 'Проект'),
                        'account_employee_id' => isset($client['account_employee_id']) ? (int) $client['account_employee_id'] : null,
                        'valid_from' => $term['valid_from'] ?? null,
                        'valid_to' => $term['valid_to'] ?? null,
                    ];
                }
            }
        }
    }
    return $rows;
};

$activeAssignments = function (array $assignments, int $employeeId, string $date): array {
    return array_values(array_filter($assignments, fn ($a) => (int) $a['employee_id'] === $employeeId
        && !empty($a['valid_from']) && $a['valid_from'] <= $date
        && (empty($a['valid_to']) || $a['valid_to'] >= $date)));
};

$reconcile = function (array $assignments, ?int $employeeId = null) use ($audit): void {
    $query = DB::table('timesheet_entries')->orderBy('id');
    if ($employeeId) $query->where('employee_id', $employeeId);
    foreach ($query->get() as $entry) {
        $valid = collect($assignments)->contains(fn ($a) => (int) $a['employee_id'] === (int) $entry->employee_id
            && (int) $a['project_id'] === (int) $entry->project_id
            && !empty($a['valid_from']) && $a['valid_from'] <= $entry->work_date
            && (empty($a['valid_to']) || $a['valid_to'] >= $entry->work_date));
        if (!$valid) {
            $before = (array) $entry;
            DB::table('timesheet_entries')->where('id', $entry->id)->delete();
            DB::table('final_approvals')->where('employee_id', $entry->employee_id)->where('project_id', $entry->project_id)->where('month', Carbon::parse($entry->work_date)->startOfMonth()->toDateString())->delete();
            $audit('entry_deleted_outside_assignment', null, (int) $entry->employee_id, (int) $entry->project_id, $entry->work_date, $before, null);
        }
    }
};

$access = function (Request $request, array $employee, array $assignments, $departments): array {
    $roles = $request->attributes->get('identity')['realm_roles'] ?? [];
    $employeeId = (int) $employee['id'];
    $platformAdmin = in_array('platform-admin', $roles, true);
    $managedDepartmentIds = $departments->filter(fn ($d) => (int) ($d['manager_id'] ?? 0) === $employeeId)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    $isAccountManager = collect($assignments)->contains(fn ($a) => (int) ($a['account_employee_id'] ?? 0) === $employeeId);
    $position = mb_strtolower((string) ($employee['position'] ?? ''));
    $canLock = $platformAdmin || str_contains($position, 'руководитель направления аккаунтинга') || str_contains($position, 'руководитель клиентской службы');
    return compact('roles', 'platformAdmin', 'managedDepartmentIds', 'isAccountManager', 'canLock');
};

$absenceData = function (Request $request, string $from, string $to, array $employeeIds) use ($apiGet) {
    return $apiGet($request, 'VACATIONS_URL', 'http://vacations:8000/api', '/calendar-absences', [
        'from' => $from,
        'to' => $to,
        'employee_ids' => array_values(array_unique($employeeIds)),
    ]);
};

Route::get('/health', fn () => response()->json([
    'service' => 'timesheets',
    'status' => 'ok',
    'database' => DB::select('select 1') ? 'ok' : 'error',
]));

Route::get('/workspace', function (Request $request, CurrentEmployee $currentEmployee) use ($monthBounds, $directory, $assignments, $activeAssignments, $reconcile, $access, $absenceData) {
    $employee = $currentEmployee->resolve($request);
    [$start, $end] = $monthBounds($request->query('month'));
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $reconcile($allAssignments, (int) $employee['id']);
    $accessInfo = $access($request, $employee, $allAssignments, $departments);
    $entries = DB::table('timesheet_entries')->where('employee_id', $employee['id'])->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->orderBy('work_date')->get();
    $confirmations = DB::table('employee_confirmations')->where('employee_id', $employee['id'])->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->pluck('confirmed_at', 'work_date');
    $approvals = DB::table('final_approvals')->where('employee_id', $employee['id'])->where('month', $start->toDateString())->get();
    $assignmentRows = collect($allAssignments)->filter(fn ($a) => (int) $a['employee_id'] === (int) $employee['id'])->values();
    $absences = $absenceData($request, $start->toDateString(), $end->toDateString(), [(int) $employee['id']]);
    $lock = DB::table('period_locks')->where('month', $start->toDateString())->first();

    return response()->json(['data' => [
        'employee' => $employee,
        'month' => $start->format('Y-m'),
        'assignments' => $assignmentRows,
        'entries' => $entries,
        'confirmations' => $confirmations,
        'final_approvals' => $approvals,
        'absences' => $absences,
        'period_locked' => (bool) $lock,
        'access' => $accessInfo,
    ]]);
});

Route::put('/entries', function (Request $request, CurrentEmployee $currentEmployee) use ($assignments, $activeAssignments, $reconcile, $audit) {
    $employee = $currentEmployee->resolve($request);
    $data = $request->validate([
        'work_date' => ['required', 'date'],
        'project_id' => ['required', 'integer', 'min:1'],
        'hours' => ['required', 'numeric', 'min:0', 'max:24'],
        'description' => ['nullable', 'string', 'max:4000'],
    ]);
    $hours = (float) $data['hours'];
    abort_if(abs($hours * 4 - round($hours * 4)) > 0.00001, 422, 'Hours must be a multiple of 0.25');
    $allAssignments = $assignments($request);
    $reconcile($allAssignments, (int) $employee['id']);
    $active = collect($activeAssignments($allAssignments, (int) $employee['id'], $data['work_date']))->firstWhere('project_id', (int) $data['project_id']);
    abort_unless($active, 422, 'Project assignment is not active for the selected date');
    $month = Carbon::parse($data['work_date'])->startOfMonth()->toDateString();
    abort_if(DB::table('period_locks')->where('month', $month)->exists(), 423, 'Timesheet period is locked');
    abort_if(DB::table('final_approvals')->where('employee_id', $employee['id'])->where('project_id', $data['project_id'])->where('month', $month)->exists(), 423, 'This project timesheet is finally approved');

    $existing = DB::table('timesheet_entries')->where('employee_id', $employee['id'])->where('project_id', $data['project_id'])->where('work_date', $data['work_date'])->first();
    $otherHours = (float) DB::table('timesheet_entries')->where('employee_id', $employee['id'])->where('work_date', $data['work_date'])->where('project_id', '!=', $data['project_id'])->sum('hours');
    abort_if($otherHours + $hours > 24.00001, 422, 'Total hours for the day cannot exceed 24');

    if ($hours <= 0) {
        if ($existing) {
            DB::table('timesheet_entries')->where('id', $existing->id)->delete();
            $audit('entry_deleted', (int) $employee['id'], (int) $employee['id'], (int) $data['project_id'], $data['work_date'], (array) $existing, null);
        }
    } else {
        $payload = [
            'employee_id' => (int) $employee['id'], 'client_id' => $active['client_id'], 'project_id' => $active['project_id'],
            'account_employee_id' => $active['account_employee_id'], 'work_date' => $data['work_date'], 'hours' => $hours,
            'description' => trim((string) ($data['description'] ?? '')) ?: null, 'updated_at' => now(),
        ];
        if ($existing) DB::table('timesheet_entries')->where('id', $existing->id)->update($payload);
        else DB::table('timesheet_entries')->insert([...$payload, 'created_at' => now()]);
        $audit($existing ? 'entry_updated' : 'entry_created', (int) $employee['id'], (int) $employee['id'], (int) $data['project_id'], $data['work_date'], $existing ? (array) $existing : null, $payload);
    }
    DB::table('employee_confirmations')->where('employee_id', $employee['id'])->where('work_date', $data['work_date'])->delete();
    return response()->json(['data' => ['ok' => true]]);
});

Route::post('/confirm', function (Request $request, CurrentEmployee $currentEmployee) use ($audit) {
    $employee = $currentEmployee->resolve($request);
    $data = $request->validate(['dates' => ['required', 'array', 'min:1', 'max:62'], 'dates.*' => ['date']]);
    foreach (array_values(array_unique($data['dates'])) as $date) {
        $month = Carbon::parse($date)->startOfMonth()->toDateString();
        abort_if(DB::table('period_locks')->where('month', $month)->exists(), 423, 'Timesheet period is locked');
        DB::table('employee_confirmations')->updateOrInsert(
            ['employee_id' => $employee['id'], 'work_date' => $date],
            ['confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]
        );
        $audit('employee_confirmed', (int) $employee['id'], (int) $employee['id'], null, $date, null, ['confirmed' => true]);
    }
    return response()->json(['data' => ['confirmed' => count($data['dates'])]]);
});

Route::post('/unconfirm', function (Request $request, CurrentEmployee $currentEmployee) use ($audit) {
    $employee = $currentEmployee->resolve($request);
    $data = $request->validate(['dates' => ['required', 'array', 'min:1', 'max:62'], 'dates.*' => ['date']]);
    foreach (array_values(array_unique($data['dates'])) as $date) {
        $month = Carbon::parse($date)->startOfMonth()->toDateString();
        $projectIds = DB::table('timesheet_entries')->where('employee_id', $employee['id'])->where('work_date', $date)->pluck('project_id');
        abort_if(DB::table('final_approvals')->where('employee_id', $employee['id'])->where('month', $month)->whereIn('project_id', $projectIds)->exists(), 423, 'Finally approved timesheet cannot be unconfirmed by employee');
        DB::table('employee_confirmations')->where('employee_id', $employee['id'])->where('work_date', $date)->delete();
        $audit('employee_unconfirmed', (int) $employee['id'], (int) $employee['id'], null, $date, ['confirmed' => true], ['confirmed' => false]);
    }
    return response()->json(['data' => ['ok' => true]]);
});

Route::get('/management', function (Request $request, CurrentEmployee $currentEmployee) use ($monthBounds, $directory, $assignments, $reconcile, $access, $absenceData) {
    $current = $currentEmployee->resolve($request);
    [$start, $end] = $monthBounds($request->query('month'));
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $reconcile($allAssignments);
    $accessInfo = $access($request, $current, $allAssignments, $departments);
    $currentId = (int) $current['id'];

    $employeeMap = $employees->keyBy(fn ($e) => (int) $e['id']);
    $visibleAssignments = collect($allAssignments)->filter(function ($a) use ($accessInfo, $employeeMap, $currentId) {
        if ($accessInfo['platformAdmin']) return true;
        if ((int) ($a['account_employee_id'] ?? 0) === $currentId) return true;
        $employee = $employeeMap->get((int) $a['employee_id']);
        return $employee && in_array((int) ($employee['department_id'] ?? 0), $accessInfo['managedDepartmentIds'], true);
    })->values();
    $visibleEmployeeIds = $visibleAssignments->pluck('employee_id')->unique()->values()->all();
    $visibleProjectIds = $visibleAssignments->pluck('project_id')->unique()->values()->all();
    $entries = DB::table('timesheet_entries')->whereIn('employee_id', $visibleEmployeeIds ?: [0])->whereIn('project_id', $visibleProjectIds ?: [0])->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->get();
    $confirmations = DB::table('employee_confirmations')->whereIn('employee_id', $visibleEmployeeIds ?: [0])->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->get();
    $approvals = DB::table('final_approvals')->whereIn('employee_id', $visibleEmployeeIds ?: [0])->where('month', $start->toDateString())->get();
    $absences = $absenceData($request, $start->toDateString(), $end->toDateString(), $visibleEmployeeIds ?: [0]);

    return response()->json(['data' => [
        'month' => $start->format('Y-m'), 'current_employee' => $current, 'access' => $accessInfo,
        'employees' => $employees->whereIn('id', $visibleEmployeeIds)->values(),
        'departments' => $departments, 'assignments' => $visibleAssignments, 'entries' => $entries,
        'confirmations' => $confirmations, 'final_approvals' => $approvals, 'absences' => $absences,
        'period_locked' => DB::table('period_locks')->where('month', $start->toDateString())->exists(),
    ]]);
});

Route::put('/management/entries', function (Request $request, CurrentEmployee $currentEmployee) use ($directory, $assignments, $access, $activeAssignments, $audit) {
    $current = $currentEmployee->resolve($request);
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $accessInfo = $access($request, $current, $allAssignments, $departments);
    $data = $request->validate(['employee_id' => ['required','integer','min:1'], 'project_id' => ['required','integer','min:1'], 'work_date' => ['required','date'], 'hours' => ['required','numeric','min:0','max:24'], 'description' => ['nullable','string','max:4000']]);
    $hours = (float) $data['hours'];
    abort_if(abs($hours * 4 - round($hours * 4)) > 0.00001, 422, 'Hours must be a multiple of 0.25');
    $targetEmployee = $employees->firstWhere('id', (int) $data['employee_id']);
    $assignment = collect($activeAssignments($allAssignments, (int) $data['employee_id'], $data['work_date']))->firstWhere('project_id', (int) $data['project_id']);
    abort_unless($assignment, 422, 'Project assignment is not active for the selected date');
    $allowed = $accessInfo['platformAdmin'] || (int) ($assignment['account_employee_id'] ?? 0) === (int) $current['id'] || ($targetEmployee && in_array((int) ($targetEmployee['department_id'] ?? 0), $accessInfo['managedDepartmentIds'], true));
    abort_unless($allowed, 403, 'Timesheet is outside your scope');
    $month = Carbon::parse($data['work_date'])->startOfMonth()->toDateString();
    abort_if(DB::table('period_locks')->where('month', $month)->exists(), 423, 'Timesheet period is locked');
    $existing = DB::table('timesheet_entries')->where('employee_id', $data['employee_id'])->where('project_id', $data['project_id'])->where('work_date', $data['work_date'])->first();
    $other = (float) DB::table('timesheet_entries')->where('employee_id', $data['employee_id'])->where('work_date', $data['work_date'])->where('project_id', '!=', $data['project_id'])->sum('hours');
    abort_if($other + $hours > 24.00001, 422, 'Total hours for the day cannot exceed 24');
    $payload = ['employee_id'=>(int)$data['employee_id'],'client_id'=>$assignment['client_id'],'project_id'=>$assignment['project_id'],'account_employee_id'=>$assignment['account_employee_id'],'work_date'=>$data['work_date'],'hours'=>$hours,'description'=>trim((string)($data['description']??''))?:null,'updated_at'=>now()];
    if ($hours <= 0) { if ($existing) DB::table('timesheet_entries')->where('id',$existing->id)->delete(); }
    elseif ($existing) DB::table('timesheet_entries')->where('id',$existing->id)->update($payload);
    else DB::table('timesheet_entries')->insert([...$payload,'created_at'=>now()]);
    DB::table('employee_confirmations')->where('employee_id',$data['employee_id'])->where('work_date',$data['work_date'])->delete();
    DB::table('final_approvals')->where('employee_id',$data['employee_id'])->where('project_id',$data['project_id'])->where('month',$month)->delete();
    $audit('manager_entry_changed',(int)$current['id'],(int)$data['employee_id'],(int)$data['project_id'],$data['work_date'],$existing?(array)$existing:null,$hours<=0?null:$payload);
    return response()->json(['data'=>['ok'=>true]]);
});

Route::post('/management/final-approval', function (Request $request, CurrentEmployee $currentEmployee) use ($monthBounds, $directory, $assignments, $access, $audit) {
    $current = $currentEmployee->resolve($request);
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $accessInfo = $access($request, $current, $allAssignments, $departments);
    $data = $request->validate(['employee_id'=>['required','integer','min:1'],'month'=>['required','date_format:Y-m'],'approved'=>['required','boolean']]);
    [$start,$end] = $monthBounds($data['month']);
    abort_if(DB::table('period_locks')->where('month',$start->toDateString())->exists(),423,'Timesheet period is locked');
    $target = $employees->firstWhere('id',(int)$data['employee_id']);
    abort_unless($target,404,'Employee not found');
    $projectIds = collect($allAssignments)->filter(function($a) use($data,$start,$end,$accessInfo,$current,$target){
        if((int)$a['employee_id'] !== (int)$data['employee_id']) return false;
        if(($a['valid_from']??'9999-12-31') > $end->toDateString() || (!empty($a['valid_to']) && $a['valid_to'] < $start->toDateString())) return false;
        return $accessInfo['platformAdmin'] || (int)($a['account_employee_id']??0)===(int)$current['id'] || in_array((int)($target['department_id']??0),$accessInfo['managedDepartmentIds'],true);
    })->pluck('project_id')->unique()->values()->all();
    abort_if(!$projectIds,403,'No projects in your scope');
    foreach($projectIds as $projectId){
        if($data['approved']) DB::table('final_approvals')->updateOrInsert(['employee_id'=>$data['employee_id'],'project_id'=>$projectId,'month'=>$start->toDateString()],['approved_by'=>$current['id'],'approved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        else DB::table('final_approvals')->where('employee_id',$data['employee_id'])->where('project_id',$projectId)->where('month',$start->toDateString())->delete();
    }
    if($data['approved']){
        foreach(CarbonPeriod::create($start,$end) as $day) DB::table('employee_confirmations')->updateOrInsert(['employee_id'=>$data['employee_id'],'work_date'=>$day->toDateString()],['confirmed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }
    $audit($data['approved']?'final_approved':'final_unapproved',(int)$current['id'],(int)$data['employee_id'],null,null,null,['project_ids'=>$projectIds,'month'=>$start->toDateString()]);
    return response()->json(['data'=>['ok'=>true,'project_ids'=>$projectIds]]);
});

Route::post('/period-lock', function(Request $request, CurrentEmployee $currentEmployee) use($directory,$assignments,$access,$audit){
    $current=$currentEmployee->resolve($request); [$employees,$departments]=$directory($request); $allAssignments=$assignments($request); $accessInfo=$access($request,$current,$allAssignments,$departments);
    abort_unless($accessInfo['canLock'],403,'You cannot lock timesheet periods');
    $data=$request->validate(['month'=>['required','date_format:Y-m'],'locked'=>['required','boolean']]); $month=Carbon::parse($data['month'].'-01')->startOfMonth()->toDateString();
    if($data['locked']) DB::table('period_locks')->updateOrInsert(['month'=>$month],['locked_by'=>$current['id'],'locked_at'=>now(),'created_at'=>now(),'updated_at'=>now()]); else DB::table('period_locks')->where('month',$month)->delete();
    $audit($data['locked']?'period_locked':'period_unlocked',(int)$current['id'],null,null,null,null,['month'=>$month]);
    return response()->json(['data'=>['locked'=>(bool)$data['locked']]]);
});

Route::get('/analytics', function(Request $request, CurrentEmployee $currentEmployee) use($monthBounds,$directory,$assignments,$reconcile,$access,$absenceData){
    $current=$currentEmployee->resolve($request); [$start,$end]=$monthBounds($request->query('month')); [$employees,$departments]=$directory($request); $allAssignments=$assignments($request); $reconcile($allAssignments); $accessInfo=$access($request,$current,$allAssignments,$departments); $currentId=(int)$current['id'];
    $employeeMap=$employees->keyBy(fn($e)=>(int)$e['id']);
    $visibleAssignments=collect($allAssignments)->filter(function($a) use($accessInfo,$employeeMap,$currentId){ if($accessInfo['platformAdmin']) return true; if((int)($a['account_employee_id']??0)===$currentId) return true; $e=$employeeMap->get((int)$a['employee_id']); return $e && in_array((int)($e['department_id']??0),$accessInfo['managedDepartmentIds'],true); })->values();
    $employeeIds=$visibleAssignments->pluck('employee_id')->unique()->values()->all();
    $approved=DB::table('final_approvals')->whereIn('employee_id',$employeeIds?:[0])->where('month',$start->toDateString())->get();
    $approvedKeys=$approved->mapWithKeys(fn($a)=>[$a->employee_id.':'.$a->project_id=>true]);
    $entries=DB::table('timesheet_entries')->whereIn('employee_id',$employeeIds?:[0])->whereBetween('work_date',[$start->toDateString(),$end->toDateString()])->get()->filter(fn($e)=>$approvedKeys->has($e->employee_id.':'.$e->project_id));
    $absences=collect($absenceData($request,$start->toDateString(),$end->toDateString(),$employeeIds?:[0]));
    $workdays=collect(CarbonPeriod::create($start,$end))->filter(fn($d)=>$d->isWeekday())->count(); $norm=$workdays*8;
    $typeLabels=['paid_vacation'=>'Оплачиваемый отпуск','unpaid_vacation'=>'Неоплачиваемый отпуск','sick_leave'=>'Больничный','maternity_leave'=>'Декрет','day_off'=>'Отгул'];
    $rows=[];
    foreach($employeeIds as $employeeId){
        $employee=$employeeMap->get((int)$employeeId); if(!$employee) continue; $commercial=(float)$entries->where('employee_id',$employeeId)->sum('hours'); $absenceHours=[];
        foreach($typeLabels as $type=>$label){ $hours=0; foreach($absences->where('employee_id',$employeeId)->where('type',$type)->where('status','confirmed') as $a){ $aStart=Carbon::parse(max($a['starts_on'],$start->toDateString())); $aEnd=Carbon::parse(min($a['ends_on'],$end->toDateString())); foreach(CarbonPeriod::create($aStart,$aEnd) as $day) if($day->isWeekday()) $hours+=8; } $absenceHours[$type]=$hours; }
        $absenceTotal=array_sum($absenceHours); $idle=max(0,$norm-$commercial-$absenceTotal); $rows[]=['employee_id'=>$employeeId,'employee_name'=>$employee['full_name']??'Сотрудник','department_id'=>$employee['department_id']??null,'department_name'=>$employee['department_name']??'Без подразделения','norm_hours'=>$norm,'commercial_hours'=>$commercial,'commercial_percent'=>$norm>0?round($commercial/$norm*100,2):0,'idle_hours'=>$idle,'absences'=>$absenceHours];
    }
    $totals=['employees'=>count($rows),'norm_hours'=>array_sum(array_column($rows,'norm_hours')),'commercial_hours'=>array_sum(array_column($rows,'commercial_hours')),'idle_hours'=>array_sum(array_column($rows,'idle_hours')),'absences'=>[]]; foreach($typeLabels as $type=>$label)$totals['absences'][$type]=array_sum(array_map(fn($r)=>$r['absences'][$type]??0,$rows)); $totals['commercial_percent']=$totals['norm_hours']>0?round($totals['commercial_hours']/$totals['norm_hours']*100,2):0;
    $byDepartment=collect($rows)->groupBy('department_id')->map(function($group){$norm=$group->sum('norm_hours');return['department_id'=>$group->first()['department_id'],'department_name'=>$group->first()['department_name'],'employees'=>$group->count(),'norm_hours'=>$norm,'commercial_hours'=>$group->sum('commercial_hours'),'commercial_percent'=>$norm>0?round($group->sum('commercial_hours')/$norm*100,2):0,'idle_hours'=>$group->sum('idle_hours'),'absences'=>collect($group->pluck('absences'))->reduce(function($carry,$item){foreach($item as $k=>$v)$carry[$k]=($carry[$k]??0)+$v;return $carry;},[])];})->values();
    return response()->json(['data'=>['month'=>$start->format('Y-m'),'type_labels'=>$typeLabels,'totals'=>$totals,'employees'=>$rows,'departments'=>$byDepartment,'access'=>$accessInfo]]);
});

Route::get('/audit', function(Request $request, CurrentEmployee $currentEmployee) use($directory,$assignments,$access){
    $current=$currentEmployee->resolve($request); [$employees,$departments]=$directory($request); $allAssignments=$assignments($request); $accessInfo=$access($request,$current,$allAssignments,$departments); $query=DB::table('timesheet_audit')->orderByDesc('created_at')->limit(500);
    if(!$accessInfo['platformAdmin']){
        $employeeMap=$employees->keyBy(fn($e)=>(int)$e['id']); $visible=collect($allAssignments)->filter(function($a)use($accessInfo,$employeeMap,$current){if((int)($a['account_employee_id']??0)===(int)$current['id'])return true;$e=$employeeMap->get((int)$a['employee_id']);return $e&&in_array((int)($e['department_id']??0),$accessInfo['managedDepartmentIds'],true);})->pluck('employee_id')->unique()->values()->all(); $query->where(function($q)use($visible,$current){$q->whereIn('employee_id',$visible?:[0])->orWhere('employee_id',$current['id']);});
    }
    return response()->json(['data'=>$query->get()]);
});
