<?php

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

$employeeStatuses = ['Ожидает трудоустройства', 'Трудоустроен', 'Уволен'];
$workFormats = ['Офис', 'Удалённо'];
$cooperationTypes = ['Штат', 'ГПХ', 'ИП', 'Самозанятый'];
$genders = ['Мужчина', 'Женщина'];

$departmentWouldCycle = function (int $departmentId, ?int $parentId): bool {
    if ($parentId === null) return false;
    if ($departmentId === $parentId) return true;
    $visited = [];
    $currentId = $parentId;
    while ($currentId !== null) {
        if (isset($visited[$currentId]) || $currentId === $departmentId) return true;
        $visited[$currentId] = true;
        $currentId = DB::table('departments')->where('id', $currentId)->value('parent_id');
    }
    return false;
};

$departmentRules = [
    'name' => ['required', 'string', 'max:255'], 'alias' => ['nullable', 'string', 'max:255'],
    'parent_id' => ['nullable', 'integer', 'exists:departments,id'], 'manager_id' => ['nullable', 'integer', 'exists:employees,id'],
    'hr_id' => ['nullable', 'integer', 'exists:employees,id'], 'yandex_id' => ['nullable', 'integer'],
    'ldap_group' => ['nullable', 'string', 'max:255'], 'is_production' => ['boolean'],
];

$departmentPayload = fn(array $data): array => [
    'name' => $data['name'], 'alias' => $data['alias'] ?? null, 'parent_id' => $data['parent_id'] ?? null,
    'manager_id' => $data['manager_id'] ?? null, 'hr_id' => $data['hr_id'] ?? null, 'yandex_id' => $data['yandex_id'] ?? null,
    'ldap_group' => $data['ldap_group'] ?? null, 'is_production' => (bool) ($data['is_production'] ?? false),
];

$employeeRules = function (?int $employeeId = null) use ($employeeStatuses, $workFormats, $cooperationTypes, $genders): array {
    return [
        'full_name' => ['nullable', 'string', 'max:255'], 'first_name' => ['nullable', 'string', 'max:255'],
        'last_name' => ['nullable', 'string', 'max:255'], 'middle_name' => ['nullable', 'string', 'max:255'],
        'gender' => ['nullable', Rule::in($genders)], 'login' => ['nullable', 'string', 'max:64', Rule::unique('employees', 'login')->ignore($employeeId)],
        'work_email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'work_email')->ignore($employeeId)],
        'personal_email' => ['nullable', 'email', 'max:255'], 'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        'position' => ['nullable', 'string', 'max:255'], 'specialization' => ['nullable', 'string', 'max:255'],
        'employment_status' => ['nullable', Rule::in($employeeStatuses)], 'work_format' => ['nullable', Rule::in($workFormats)],
        'cooperation_type' => ['nullable', Rule::in($cooperationTypes)], 'hired_at' => ['nullable', 'date'], 'fired_at' => ['nullable', 'date'],
        'birth_date' => ['nullable', 'date'], 'city' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:64'],
        'telegram' => ['nullable', 'string', 'max:255'], 'skype' => ['nullable', 'string', 'max:255'], 'is_remote' => ['nullable', 'boolean'],
    ];
};

$employeePayload = function (array $data): array {
    $fullName = trim((string) ($data['full_name'] ?? ''));
    if ($fullName === '') $fullName = trim(implode(' ', array_filter([$data['last_name'] ?? null, $data['first_name'] ?? null, $data['middle_name'] ?? null])));
    return [
        'full_name' => $fullName, 'first_name' => $data['first_name'] ?? null, 'last_name' => $data['last_name'] ?? null,
        'middle_name' => $data['middle_name'] ?? null, 'gender' => $data['gender'] ?? null, 'login' => $data['login'] ?? null,
        'work_email' => $data['work_email'] ?? null, 'personal_email' => $data['personal_email'] ?? null,
        'department_id' => $data['department_id'] ?? null, 'position' => $data['position'] ?? null, 'specialization' => $data['specialization'] ?? null,
        'employment_status' => $data['employment_status'] ?? null, 'work_format' => $data['work_format'] ?? null,
        'cooperation_type' => $data['cooperation_type'] ?? null, 'hired_at' => $data['hired_at'] ?? null, 'fired_at' => $data['fired_at'] ?? null,
        'birth_date' => $data['birth_date'] ?? null, 'city' => $data['city'] ?? null, 'phone' => $data['phone'] ?? null,
        'telegram' => $data['telegram'] ?? null, 'skype' => $data['skype'] ?? null,
        'is_remote' => (bool) ($data['is_remote'] ?? (($data['work_format'] ?? null) === 'Удалённо')),
    ];
};

$employeeQuery = function () {
    return DB::table('employees')->leftJoin('departments', 'departments.id', '=', 'employees.department_id')->select([
        'employees.*', 'departments.name as department_name',
    ]);
};

$closeOpenStatus = function (int $employee, string $endDate): void {
    DB::table('employee_status_history')->where('employee_id', $employee)->whereNull('effective_to')->update(['effective_to' => $endDate, 'updated_at' => now()]);
};

$openStatus = function (int $employee, string $status, string $from, ?string $reason = null): void {
    DB::table('employee_status_history')->insert(['employee_id' => $employee, 'status' => $status, 'effective_from' => $from, 'effective_to' => null, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
};

$closeOpenAssignment = function (int $employee, string $endDate): void {
    DB::table('employment_assignment_history')->where('employee_id', $employee)->whereNull('effective_to')->update(['effective_to' => $endDate, 'updated_at' => now()]);
};

$openAssignment = function (int $employee, ?int $departmentId, ?string $position, string $from): void {
    DB::table('employment_assignment_history')->insert(['employee_id' => $employee, 'department_id' => $departmentId, 'position' => $position, 'effective_from' => $from, 'effective_to' => null, 'created_at' => now(), 'updated_at' => now()]);
};

Route::get('/health', fn() => response()->json(['service' => 'employees', 'status' => 'ok', 'database' => DB::select('select 1') ? 'ok' : 'error']));
Route::get('/reference-data', fn() => response()->json(['data' => [
    'employee_statuses' => $employeeStatuses, 'work_formats' => $workFormats, 'cooperation_types' => $cooperationTypes, 'genders' => $genders,
]]));

Route::get('/departments', function () {
    $departments = DB::table('departments as d')->leftJoin('departments as parent', 'parent.id', '=', 'd.parent_id')
        ->leftJoin('employees as manager', 'manager.id', '=', 'd.manager_id')->leftJoin('employees as hr', 'hr.id', '=', 'd.hr_id')
        ->select(['d.id','d.name','d.alias','d.parent_id','parent.name as parent_name','d.manager_id','manager.full_name as manager_name','d.hr_id','hr.full_name as hr_name','d.yandex_id','d.ldap_group','d.is_production','d.created_at','d.updated_at'])
        ->selectSub(fn($q) => $q->from('employees as department_employee')->selectRaw('COUNT(*)::int')->whereColumn('department_employee.department_id', 'd.id'), 'employee_count')
        ->orderBy('d.name')->get();
    return response()->json(['data' => $departments]);
});

Route::post('/departments', function (Request $request) use ($departmentRules, $departmentPayload) {
    $validator = Validator::make($request->all(), $departmentRules);
    if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
    $id = DB::table('departments')->insertGetId([...$departmentPayload($validator->validated()), 'created_at'=>now(),'updated_at'=>now()]);
    return response()->json(['data' => DB::table('departments')->where('id', $id)->first()], 201);
});

Route::put('/departments/{department}', function (Request $request, int $department) use ($departmentWouldCycle, $departmentRules, $departmentPayload) {
    $validator = Validator::make($request->all(), $departmentRules);
    if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
    if (!DB::table('departments')->where('id', $department)->exists()) return response()->json(['message'=>'Department not found'],404);
    $data = $validator->validated(); $parentId = isset($data['parent_id']) ? (int)$data['parent_id'] : null;
    if ($departmentWouldCycle($department, $parentId)) return response()->json(['errors'=>['parent_id'=>['Подразделение не может быть вложено в себя или в собственное дочернее подразделение.']]],422);
    DB::table('departments')->where('id',$department)->update([...$departmentPayload($data),'parent_id'=>$parentId,'updated_at'=>now()]);
    return response()->json(['data'=>DB::table('departments')->where('id',$department)->first()]);
});

Route::get('/employees', function (Request $request) use ($employeeQuery) {
    $query = $employeeQuery();
    if ($search = trim((string)$request->query('search',''))) $query->where(fn($q)=>$q->where('employees.full_name','ilike',"%{$search}%")->orWhere('employees.position','ilike',"%{$search}%")->orWhere('employees.login','ilike',"%{$search}%"));
    if ($departmentId = $request->query('department_id')) $query->where('employees.department_id',(int)$departmentId);
    if ($status = trim((string)$request->query('employment_status',''))) $query->where('employees.employment_status',$status);
    $employees = $query->orderBy('employees.full_name')->get();
    return response()->json(['data'=>$employees,'meta'=>['count'=>$employees->count()]]);
});

Route::get('/employees/{employee}', function (int $employee) use ($employeeQuery) {
    $row = $employeeQuery()->where('employees.id',$employee)->first();
    if (!$row) return response()->json(['message'=>'Employee not found'],404);
    $periods = DB::table('employment_periods')->leftJoin('departments','departments.id','=','employment_periods.department_id')
        ->select(['employment_periods.*','departments.name as department_name'])->where('employment_periods.employee_id',$employee)->orderByDesc('started_at')->get();
    $salaries = DB::table('salary_history')->where('employee_id',$employee)->orderByDesc('effective_from')->get();
    $statuses = DB::table('employee_status_history')->where('employee_id',$employee)->orderByDesc('effective_from')->get();
    $assignments = DB::table('employment_assignment_history as h')->leftJoin('departments as d','d.id','=','h.department_id')
        ->select(['h.*','d.name as department_name'])->where('h.employee_id',$employee)->orderByDesc('effective_from')->get();
    return response()->json(['data'=>['employee'=>$row,'employment_periods'=>$periods,'salary_history'=>$salaries,'status_history'=>$statuses,'assignment_history'=>$assignments]]);
});

Route::post('/employees', function (Request $request) use ($employeeRules, $employeePayload, $cooperationTypes, $openStatus, $openAssignment) {
    $rules = $employeeRules();
    $rules['first_name'] = ['required','string','max:255']; $rules['last_name'] = ['required','string','max:255'];
    $rules['gender'] = ['required', Rule::in(['Мужчина','Женщина'])]; $rules['login'] = ['required','string','max:64',Rule::unique('employees','login')];
    $rules['personal_email'] = ['required','email','max:255']; $rules['department_id'] = ['required','integer','exists:departments,id'];
    $rules['hired_at'] = ['required','date']; $rules['cooperation_type'] = ['required',Rule::in($cooperationTypes)];
    $validator = Validator::make($request->all(), $rules);
    if ($validator->fails()) return response()->json(['errors'=>$validator->errors()],422);
    $data = $validator->validated(); $data['employment_status'] = 'Трудоустроен'; $data['work_email'] = ($data['login'] ?? '').'@irlix.ru';
    $payload = $employeePayload($data);
    $id = DB::transaction(function () use ($payload, $data, $openStatus, $openAssignment) {
        $id = DB::table('employees')->insertGetId([...$payload,'onboarding_email_status'=>'pending_template','created_at'=>now(),'updated_at'=>now()]);
        DB::table('employment_periods')->insert(['employee_id'=>$id,'cooperation_type'=>$data['cooperation_type'],'started_at'=>$data['hired_at'],'ended_at'=>null,'department_id'=>$data['department_id'],'position'=>$data['position'] ?? null,'created_at'=>now(),'updated_at'=>now()]);
        $openStatus($id, 'Трудоустроен', $data['hired_at'], 'Первичное трудоустройство');
        $openAssignment($id, (int)$data['department_id'], $data['position'] ?? null, $data['hired_at']);
        return $id;
    });
    return response()->json(['data'=>DB::table('employees')->where('id',$id)->first(),'meta'=>['onboarding_email'=>'pending_template']],201);
});

Route::patch('/employees/{employee}', function (Request $request, int $employee) use ($employeeRules, $closeOpenAssignment, $openAssignment) {
    $current = DB::table('employees')->where('id',$employee)->first();
    if (!$current) return response()->json(['message'=>'Employee not found'],404);
    $validator = Validator::make($request->all(), $employeeRules($employee));
    if ($validator->fails()) return response()->json(['errors'=>$validator->errors()],422);
    $data = $validator->validated();
    $protected = ['employment_status','cooperation_type','hired_at','fired_at'];
    foreach ($protected as $field) unset($data[$field]);
    if (!$data) return response()->json(['message'=>'No editable attributes supplied'],422);

    DB::transaction(function () use ($employee, $current, $data, $closeOpenAssignment, $openAssignment) {
        $update = $data;
        if (array_key_exists('work_format',$update)) $update['is_remote'] = $update['work_format'] === 'Удалённо';
        if (array_key_exists('is_remote',$update) && !array_key_exists('work_format',$update)) $update['work_format'] = $update['is_remote'] ? 'Удалённо' : 'Офис';
        $nameChanged = array_key_exists('first_name',$update) || array_key_exists('last_name',$update) || array_key_exists('middle_name',$update);
        if ($nameChanged) {
            $update['full_name'] = trim(implode(' ', array_filter([
                $update['last_name'] ?? $current->last_name,
                $update['first_name'] ?? $current->first_name,
                $update['middle_name'] ?? $current->middle_name,
            ])));
        }
        $assignmentChanged = (array_key_exists('department_id',$update) && (string)($update['department_id'] ?? '') !== (string)($current->department_id ?? ''))
            || (array_key_exists('position',$update) && (string)($update['position'] ?? '') !== (string)($current->position ?? ''));
        if ($assignmentChanged && $current->employment_status === 'Трудоустроен') {
            $today = now()->toDateString();
            $previousEnd = Carbon::parse($today)->subDay()->toDateString();
            $closeOpenAssignment($employee, $previousEnd);
            $openAssignment($employee, isset($update['department_id']) ? ($update['department_id'] ? (int)$update['department_id'] : null) : $current->department_id, $update['position'] ?? $current->position, $today);
        }
        DB::table('employees')->where('id',$employee)->update([...$update,'updated_at'=>now()]);
    });
    return response()->json(['data'=>DB::table('employees')->where('id',$employee)->first()]);
});

Route::post('/employees/{employee}/dismiss', function (Request $request, int $employee) use ($closeOpenStatus, $openStatus, $closeOpenAssignment) {
    $validator = Validator::make($request->all(), ['date'=>['required','date']]);
    if ($validator->fails()) return response()->json(['errors'=>$validator->errors()],422);
    $row = DB::table('employees')->where('id',$employee)->first();
    if (!$row) return response()->json(['message'=>'Employee not found'],404);
    if ($row->employment_status !== 'Трудоустроен') return response()->json(['message'=>'Уволить можно только трудоустроенного сотрудника.'],422);
    $date = $validator->validated()['date'];
    $openPeriod = DB::table('employment_periods')->where('employee_id',$employee)->whereNull('ended_at')->first();
    if (!$openPeriod) return response()->json(['message'=>'У сотрудника нет открытой записи ТУ.'],422);
    if ($date < $openPeriod->started_at) return response()->json(['errors'=>['date'=>['Дата увольнения не может быть раньше даты начала текущего ТУ.']]],422);
    DB::transaction(function () use ($employee,$date,$closeOpenStatus,$openStatus,$closeOpenAssignment) {
        DB::table('employment_periods')->where('employee_id',$employee)->whereNull('ended_at')->update(['ended_at'=>$date,'updated_at'=>now()]);
        $previousEnd = Carbon::parse($date)->subDay()->toDateString();
        $closeOpenStatus($employee,$previousEnd); $openStatus($employee,'Уволен',$date,'Увольнение');
        $closeOpenAssignment($employee,$date);
        DB::table('employees')->where('id',$employee)->update(['employment_status'=>'Уволен','fired_at'=>$date,'updated_at'=>now()]);
    });
    return response()->json(['data'=>DB::table('employees')->where('id',$employee)->first()]);
});

Route::post('/employees/{employee}/rehire', function (Request $request, int $employee) use ($cooperationTypes,$closeOpenStatus,$openStatus,$openAssignment) {
    $validator = Validator::make($request->all(), ['started_at'=>['required','date'],'cooperation_type'=>['required',Rule::in($cooperationTypes)],'department_id'=>['required','integer','exists:departments,id'],'position'=>['nullable','string','max:255']]);
    if ($validator->fails()) return response()->json(['errors'=>$validator->errors()],422);
    $row = DB::table('employees')->where('id',$employee)->first();
    if (!$row) return response()->json(['message'=>'Employee not found'],404);
    if ($row->employment_status !== 'Уволен') return response()->json(['message'=>'Вернуть можно только уволенного сотрудника.'],422);
    if (DB::table('employment_periods')->where('employee_id',$employee)->whereNull('ended_at')->exists()) return response()->json(['message'=>'У сотрудника уже есть открытая запись ТУ.'],422);
    $data = $validator->validated();
    DB::transaction(function () use ($employee,$data,$closeOpenStatus,$openStatus,$openAssignment) {
        DB::table('employment_periods')->insert(['employee_id'=>$employee,'cooperation_type'=>$data['cooperation_type'],'started_at'=>$data['started_at'],'ended_at'=>null,'department_id'=>$data['department_id'],'position'=>$data['position'] ?? null,'created_at'=>now(),'updated_at'=>now()]);
        $previousEnd = Carbon::parse($data['started_at'])->subDay()->toDateString();
        $closeOpenStatus($employee,$previousEnd); $openStatus($employee,'Трудоустроен',$data['started_at'],'Повторное трудоустройство');
        $openAssignment($employee,(int)$data['department_id'],$data['position'] ?? null,$data['started_at']);
        DB::table('employees')->where('id',$employee)->update(['employment_status'=>'Трудоустроен','cooperation_type'=>$data['cooperation_type'],'department_id'=>$data['department_id'],'position'=>$data['position'] ?? null,'hired_at'=>$data['started_at'],'fired_at'=>null,'onboarding_email_status'=>'pending_template','updated_at'=>now()]);
    });
    return response()->json(['data'=>DB::table('employees')->where('id',$employee)->first()]);
});

Route::post('/employees/{employee}/change-cooperation', function (Request $request, int $employee) use ($cooperationTypes) {
    $validator = Validator::make($request->all(), ['effective_from'=>['required','date'],'cooperation_type'=>['required',Rule::in($cooperationTypes)]]);
    if ($validator->fails()) return response()->json(['errors'=>$validator->errors()],422);
    $row = DB::table('employees')->where('id',$employee)->first();
    if (!$row) return response()->json(['message'=>'Employee not found'],404);
    if ($row->employment_status !== 'Трудоустроен') return response()->json(['message'=>'Тип сотрудничества можно менять только у трудоустроенного сотрудника.'],422);
    $currentPeriod = DB::table('employment_periods')->where('employee_id',$employee)->whereNull('ended_at')->first();
    if (!$currentPeriod) return response()->json(['message'=>'У сотрудника нет открытой записи ТУ.'],422);
    $data = $validator->validated();
    if ($data['effective_from'] <= $currentPeriod->started_at) return response()->json(['errors'=>['effective_from'=>['Дата изменения должна быть позже начала текущего ТУ.']]],422);
    DB::transaction(function () use ($employee,$row,$currentPeriod,$data) {
        $end = Carbon::parse($data['effective_from'])->subDay()->toDateString();
        DB::table('employment_periods')->where('id',$currentPeriod->id)->update(['ended_at'=>$end,'updated_at'=>now()]);
        DB::table('employment_periods')->insert(['employee_id'=>$employee,'cooperation_type'=>$data['cooperation_type'],'started_at'=>$data['effective_from'],'ended_at'=>null,'department_id'=>$row->department_id,'position'=>$row->position,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('employees')->where('id',$employee)->update(['cooperation_type'=>$data['cooperation_type'],'updated_at'=>now()]);
    });
    return response()->json(['data'=>DB::table('employees')->where('id',$employee)->first()]);
});

Route::post('/employees/{employee}/salary-history', function (Request $request, int $employee) {
    if (!DB::table('employees')->where('id',$employee)->exists()) return response()->json(['message'=>'Employee not found'],404);
    $validator = Validator::make($request->all(), ['effective_from'=>['required','date'],'gross_salary'=>['required','numeric','min:0'],'bonus'=>['nullable','numeric','min:0'],'comment'=>['nullable','string','max:2000']]);
    if ($validator->fails()) return response()->json(['errors'=>$validator->errors()],422);
    $data = $validator->validated();
    $active = DB::table('salary_history')->where('employee_id',$employee)->whereNull('effective_to')->first();
    if ($active && $data['effective_from'] <= $active->effective_from) return response()->json(['errors'=>['effective_from'=>['Новая зарплата должна начинаться позже текущей действующей зарплаты.']]],422);
    $id = DB::transaction(function () use ($employee,$data,$active) {
        if ($active) {
            $end = Carbon::parse($data['effective_from'])->subDay()->toDateString();
            DB::table('salary_history')->where('id',$active->id)->update(['effective_to'=>$end,'status'=>'Завершена','updated_at'=>now()]);
        }
        return DB::table('salary_history')->insertGetId([...$data,'employee_id'=>$employee,'effective_to'=>null,'status'=>'Действует','created_at'=>now(),'updated_at'=>now()]);
    });
    return response()->json(['data'=>DB::table('salary_history')->where('id',$id)->first()],201);
});

Route::delete('/employees/{employee}/salary-history/{salary}', function (int $employee, int $salary) {
    $item = DB::table('salary_history')->where('id',$salary)->where('employee_id',$employee)->first();
    if (!$item) return response()->json(['message'=>'Salary history item not found'],404);
    DB::transaction(function () use ($employee,$salary,$item) {
        DB::table('salary_history')->where('id',$salary)->delete();
        if ($item->effective_to === null) {
            $previous = DB::table('salary_history')->where('employee_id',$employee)->orderByDesc('effective_from')->first();
            if ($previous) DB::table('salary_history')->where('id',$previous->id)->update(['effective_to'=>null,'status'=>'Действует','updated_at'=>now()]);
        }
    });
    return response()->noContent();
});
