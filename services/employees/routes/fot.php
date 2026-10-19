<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

$fotAccess = static function (Request $request, string $permission): array {
    $access = (array) $request->attributes->get('employees_access', []);
    abort_unless($access['permissions'][$permission] ?? false, 403, 'Недостаточно прав');
    return $access;
};

$fotScope = static function (array $access, int $employeeId): bool {
    if (($access['scope'] ?? 'none') === 'all') return true;
    $department = DB::table('employees')->where('id', $employeeId)->value('department_id');
    return $department !== null && in_array((int) $department, array_map('intval', $access['department_ids'] ?? []), true);
};

$fotReview = static function (int $id) {
    return DB::table('salary_reviews')->where('id', $id)->first();
};

$fotConflicts = static function (int $employee, string $month, ?int $except = null): bool {
    return DB::table('salary_reviews')->where('employee_id', $employee)
        ->whereDate('effective_month', $month)->where('status', '<>', 'cancelled')
        ->when($except !== null, fn ($query) => $query->where('id', '<>', $except))->exists();
};

$fotChain = static function (int $employeeId, int $creator): array {
    $employee = DB::table('employees')->where('id', $employeeId)->first();
    $departments = DB::table('departments')->get()->keyBy('id');
    $cursor = (int) ($employee?->department_id ?? 0);
    $chain = [];
    $seen = [];
    $root = null;
    while ($cursor && isset($departments[$cursor]) && !isset($seen[$cursor])) {
        $seen[$cursor] = true;
        $department = $departments[$cursor];
        $manager = (int) ($department->manager_id ?? 0);
        if ($manager && $manager !== $creator && !in_array($manager, $chain, true)) $chain[] = $manager;
        $root = $department;
        $cursor = (int) ($department->parent_id ?? 0);
    }
    // A complete chain must terminate in Irlix's root, with its configured manager.
    if ($cursor || !$root || $root->parent_id !== null || (int) ($root->manager_id ?? 0) === 0
        || (int) $root->manager_id === $creator || end($chain) !== (int) $root->manager_id) {
        abort(409, 'Не задана полная цепочка до руководителя корневого подразделения');
    }
    return $chain;
};

Route::get('/fot/overview', function (Request $request) use ($fotAccess) {
    $access = $fotAccess($request, 'employees.fot.view');
    $validated = Validator::make($request->query(), [
        'year' => ['required', 'integer', 'between:2000,2100'],
        'department_id' => ['nullable', 'integer', 'exists:departments,id'],
    ])->validate();
    $year = (int) $validated['year'];
    $permitted = array_map('intval', $access['department_ids'] ?? []);
    $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
    if ($departmentId !== null && !in_array($departmentId, $permitted, true)) {
        return response()->json(['message' => 'Подразделение недоступно'], 403);
    }
    // Employees consumes the calendar via the core's API, never by reading another schema.
    try {
        $response = Http::timeout(10)->get(rtrim((string) env('PLATFORM_CORE_URL', 'http://platform-core:8000/api'), '/').'/production-calendar', ['year' => $year]);
        if (!$response->successful()) throw new RuntimeException('Calendar HTTP '.$response->status());
        $calendar = $response->json('data');
    } catch (Throwable $e) {
        report($e);
        return response()->json(['message' => 'Не удалось получить производственный календарь'], 503);
    }
    if (!($calendar['available'] ?? false)) {
        return response()->json(['message' => 'Производственный календарь на этот год пока недоступен'], 503);
    }
    $working = [];
    $monthWorkDays = array_fill(1, 12, 0);
    foreach (($calendar['days'] ?? []) as $day => $item) {
        if (!($item['is_working'] ?? false)) continue;
        $working[$day] = true;
        $monthWorkDays[(int) substr($day, 5, 2)]++;
    }
    $departments = DB::table('departments')->get(['id', 'name', 'parent_id']);
    $eligibleDepartments = $departmentId === null ? $permitted : [$departmentId];
    if ($departmentId !== null) {
        $children = [];
        foreach ($departments as $d) $children[(int) ($d->parent_id ?? 0)][] = (int) $d->id;
        $queue = [$departmentId];
        while ($queue) {
            $id = array_shift($queue);
            foreach ($children[$id] ?? [] as $child) {
                if (!in_array($child, $eligibleDepartments, true) && in_array($child, $permitted, true)) {
                    $eligibleDepartments[] = $child;
                    $queue[] = $child;
                }
            }
        }
    }
    $eligible = array_flip($eligibleDepartments);
    $employees = DB::table('employees')->get(['id','full_name','department_id','hired_at','fired_at','employment_status']);
    $salary = DB::table('salary_history')->orderBy('effective_from')->get()->groupBy('employee_id');
    $assignments = DB::table('employment_assignment_history')->orderBy('effective_from')->get()->groupBy('employee_id');
    $reviews = DB::table('salary_reviews')->where('status','<>','cancelled')->orderBy('effective_month')->get()->groupBy('employee_id');

    $summary = [];
    for ($month = 1; $month <= 12; $month++) $summary[$month] = ['current' => 0, 'planned' => 0];
    $rows = [];
    foreach ($employees as $employee) {
        $history = $assignments->get($employee->id, collect());
        $salaryHistory = $salary->get($employee->id, collect());
        $plans = $reviews->get($employee->id, collect());
        $monthly = [];
        $hasVisibleDay = false;
        for ($m = 1; $m <= 12; $m++) {
            $current = 0.0;
            $planned = 0.0;
            $worked = 0;
            $start = CarbonImmutable::create($year, $m, 1);
            for ($date = $start; $date->month === $m; $date = $date->addDay()) {
                $day = $date->toDateString();
                if (!isset($working[$day])) continue;
                $assignment = null;
                foreach ($history as $h) {
                    if ($h->effective_from <= $day && (!$h->effective_to || $h->effective_to >= $day)) $assignment = $h;
                }
                // Legacy employees without assignment history are not projected into unrelated scopes.
                if (!$assignment || !isset($eligible[(int) ($assignment->department_id ?? 0)])) continue;
                $actual = null;
                foreach ($salaryHistory as $record) {
                    if ($record->effective_from <= $day && (!$record->effective_to || $record->effective_to >= $day)) $actual = $record;
                }
                if (!$actual) continue;
                $currentAmount = (float) $actual->gross_salary;
                $plannedAmount = $currentAmount;
                $effective = $actual->effective_from;
                foreach ($plans as $plan) {
                    if ($plan->effective_month <= $day && $plan->effective_month >= $effective) {
                        $plannedAmount = (float) $plan->gross_salary;
                        $effective = $plan->effective_month;
                    }
                }
                $divisor = $monthWorkDays[$m] ?: 1;
                $current += $currentAmount / $divisor;
                $planned += $plannedAmount / $divisor;
                $worked++;
                $hasVisibleDay = true;
            }
            $monthly[$m] = ['current' => round($current, 2), 'planned' => round($planned, 2), 'working_days' => $worked];
            $summary[$m]['current'] += $current;
            $summary[$m]['planned'] += $planned;
        }
        if ($hasVisibleDay) {
            $rows[] = ['id' => $employee->id, 'name' => $employee->full_name, 'months' => $monthly,
                'reviews' => $plans->map(fn ($p) => ['id'=>$p->id,'month'=>$p->effective_month,'status'=>$p->status,'gross_salary'=>$p->gross_salary])->values()->all()];
        }
    }
    foreach ($summary as &$month) {
        $month['current'] = round($month['current'], 2);
        $month['planned'] = round($month['planned'], 2);
        $month['difference'] = round($month['planned'] - $month['current'], 2);
    }
    unset($month);
    return response()->json(['data' => [
        'year' => $year, 'summary' => $summary, 'employees' => $rows,
        'departments' => $departments->whereIn('id', $permitted)->values(),
        'calendar_source' => $calendar['source'] ?? null,
    ]]);
});

Route::get('/fot/reviews', function (Request $request) use ($fotAccess, $fotScope) {
    $access = $fotAccess($request, 'employees.fot.view');
    $records = DB::table('salary_reviews as r')->join('employees as e','e.id','=','r.employee_id')
        ->select('r.*','e.full_name as employee_name')->orderByDesc('r.updated_at')->limit(500)->get();
    $visible = $records->filter(fn ($r) => $fotScope($access, (int) $r->employee_id))->values()->all();
    $ids = array_column($visible, 'id');
    $approvals = $ids ? DB::table('salary_review_approvals as a')
        ->join('employees as e','e.id','=','a.approver_id')
        ->whereIn('a.review_id', $ids)
        ->select('a.*','e.full_name as approver_name')->orderBy('a.cycle')->orderBy('a.step')->get()->groupBy('review_id') : collect();
    $versions = $ids ? DB::table('salary_review_versions')->whereIn('review_id', $ids)->orderByDesc('created_at')->get()->groupBy('review_id') : collect();
    return response()->json(['data' => array_map(static fn ($r) => [...(array) $r, 'approvals' => ($approvals->get($r->id) ?? collect())->values()->all(), 'versions' => ($versions->get($r->id) ?? collect())->values()->all()], $visible)]);
});

Route::get('/fot/inbox', function (Request $request) use ($fotAccess) {
    $access = $fotAccess($request, 'employees.fot.approve');
    $id = (int) ($access['employee_id'] ?? 0);
    $rows = DB::table('salary_review_approvals as a')
        ->join('salary_reviews as r','r.id','=','a.review_id')
        ->join('employees as e','e.id','=','r.employee_id')
        ->where('a.approver_id',$id)->where('a.decision','waiting')
        ->where('r.status','pending')->whereColumn('a.cycle','r.cycle')
        ->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('salary_review_approvals as prior')
                ->whereColumn('prior.review_id','a.review_id')->whereColumn('prior.cycle','a.cycle')
                ->whereColumn('prior.step','<','a.step')->where('prior.decision','<>','approved');
        })->select('r.*','e.full_name as employee_name','a.id as approval_id')->get();
    return response()->json(['data'=>$rows]);
});

Route::post('/fot/reviews', function (Request $request) use ($fotAccess,$fotScope,$fotConflicts) {
    $access = $fotAccess($request,'employees.fot.plan');
    $data = Validator::make($request->all(), [
        'employee_id'=>['required','integer','exists:employees,id'],
        'effective_month'=>['required','date','date_format:Y-m-d'],
        'gross_salary'=>['required','numeric','min:0'],
        'comment'=>['nullable','string','max:3000'],
    ])->validate();
    $date = CarbonImmutable::parse($data['effective_month']);
    if ($date->day !== 1) return response()->json(['message'=>'Пересмотр может начинаться только с первого числа'],422);
    if (!$fotScope($access,(int) $data['employee_id'])) return response()->json(['message'=>'Сотрудник вне вашего направления'],403);
    if ($fotConflicts((int)$data['employee_id'],$data['effective_month'])) return response()->json(['message'=>'Пересмотр на этот месяц уже существует'],409);
    $id = DB::table('salary_reviews')->insertGetId([
        ...$data,'created_by'=>(int)$access['employee_id'],'status'=>'draft','cycle'=>0,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    return response()->json(['data'=>DB::table('salary_reviews')->find($id)],201);
});

Route::patch('/fot/reviews/{review}', function (Request $request, int $review) use ($fotAccess,$fotReview,$fotScope,$fotConflicts) {
    $access = $fotAccess($request,'employees.fot.plan');
    $item = $fotReview($review);
    if (!$item) return response()->json(['message'=>'Пересмотр не найден'],404);
    if ((int)$item->created_by !== (int)$access['employee_id'] || !$fotScope($access,(int)$item->employee_id)) return response()->json(['message'=>'Недоступно'],403);
    if (!in_array($item->status,['draft','returned','approved'],true)) return response()->json(['message'=>'Нельзя редактировать текущий статус'],409);
    $data = Validator::make($request->all(), [
        'effective_month'=>['sometimes','required','date','date_format:Y-m-d'],
        'gross_salary'=>['sometimes','required','numeric','min:0'],
        'comment'=>['nullable','string','max:3000'],
    ])->validate();
    $month = $data['effective_month'] ?? $item->effective_month;
    if (CarbonImmutable::parse($month)->day !== 1) return response()->json(['message'=>'Нужен первый день месяца'],422);
    if ($fotConflicts((int)$item->employee_id,$month,$review)) return response()->json(['message'=>'Пересмотр на этот месяц уже существует'],409);
    DB::transaction(function () use ($review, $item, $data, $access) {
        DB::table('salary_review_versions')->insert([
            'review_id'=>$review,'effective_month'=>$item->effective_month,
            'gross_salary'=>$item->gross_salary,'comment'=>$item->comment,
            'status'=>$item->status,'changed_by'=>$access['employee_id'],
            'created_at'=>now(),
        ]);
        DB::table('salary_reviews')->where('id',$review)->update([...$data,'status'=>'draft','updated_at'=>now()]);
    });
    return response()->json(['data'=>DB::table('salary_reviews')->find($review)]);
});

Route::post('/fot/reviews/{review}/submit', function (Request $request, int $review) use ($fotAccess,$fotReview,$fotScope,$fotChain) {
    $access = $fotAccess($request,'employees.fot.plan');
    $item = $fotReview($review);
    if (!$item) return response()->json(['message'=>'Не найдено'],404);
    if ((int)$item->created_by !== (int)$access['employee_id'] || !$fotScope($access,(int)$item->employee_id)) return response()->json(['message'=>'Недоступно'],403);
    if (!in_array($item->status,['draft','returned'],true)) return response()->json(['message'=>'Требуется черновик или возврат'],409);
    $chain = $fotChain((int)$item->employee_id,(int)$access['employee_id']);
    DB::transaction(function () use ($item,$chain) {
        $cycle=(int)$item->cycle+1;
        DB::table('salary_reviews')->where('id',$item->id)->update(['status'=>'pending','cycle'=>$cycle,'updated_at'=>now()]);
        foreach($chain as $step=>$manager) DB::table('salary_review_approvals')->insert([
            'review_id'=>$item->id,'cycle'=>$cycle,'step'=>$step+1,'approver_id'=>$manager,
            'decision'=>'waiting','created_at'=>now(),'updated_at'=>now(),
        ]);
    });
    return response()->json(['data'=>DB::table('salary_reviews')->find($item->id)]);
});

Route::post('/fot/reviews/{review}/decision', function (Request $request, int $review) use ($fotAccess,$fotReview) {
    $access=$fotAccess($request,'employees.fot.approve');
    $data=Validator::make($request->all(),[
        'decision'=>['required',Rule::in(['approved','returned'])],
        'comment'=>['required_if:decision,returned','nullable','string','max:3000'],
    ])->validate();
    return DB::transaction(function () use ($access,$data,$review) {
        $item=DB::table('salary_reviews')->lockForUpdate()->find($review);
        if (!$item || $item->status!=='pending') return response()->json(['message'=>'Нет активного согласования'],409);
        $steps=DB::table('salary_review_approvals')->where('review_id',$review)->where('cycle',$item->cycle)->orderBy('step')->get();
        $current=$steps->first(fn ($step)=>$step->decision==='waiting');
        if (!$current || (int)$current->approver_id !== (int)$access['employee_id']) return response()->json(['message'=>'Сейчас не ваша очередь'],403);
        if (($data['decision']==='returned') && trim((string)($data['comment']??''))==='') return response()->json(['message'=>'Комментарий обязателен'],422);
        DB::table('salary_review_approvals')->where('id',$current->id)->update([
            'decision'=>$data['decision'],'comment'=>$data['comment']??null,'acted_at'=>now(),'updated_at'=>now(),
        ]);
        if ($data['decision']==='returned') {
            DB::table('salary_reviews')->where('id',$review)->update(['status'=>'returned','updated_at'=>now()]);
        } elseif ($steps->filter(fn ($step)=>$step->decision==='waiting')->count()===1) {
            DB::table('salary_reviews')->where('id',$review)->update(['status'=>'approved','updated_at'=>now()]);
        }
        return response()->json(['data'=>DB::table('salary_reviews')->find($review)]);
    });
});

Route::post('/fot/reviews/{review}/cancel', function (Request $request,int $review) use ($fotAccess,$fotReview,$fotScope) {
    $access=$fotAccess($request,'employees.fot.plan');
    $item=$fotReview($review);
    if (!$item) return response()->json(['message'=>'Не найдено'],404);
    if ((int)$item->created_by !== (int)$access['employee_id'] || !$fotScope($access,(int)$item->employee_id)) return response()->json(['message'=>'Недоступно'],403);
    DB::table('salary_reviews')->where('id',$review)->update(['status'=>'cancelled','updated_at'=>now()]);
    return response()->json(['data'=>DB::table('salary_reviews')->find($review)]);
});


// HR transfer is a distinct operation, independent of planned salary reviews.
Route::post('/employees/{employee}/transfer', function (Request $request,int $employee) use ($syncKeycloakDepartment) {
    $access=(array)$request->attributes->get('employees_access', []);
    if (!($access['permissions']['employees.transfer'] ?? false)) return response()->json(['message'=>'Перевод доступен только кадровику'],403);
    $data=Validator::make($request->all(),[
        'department_id'=>['required','integer','exists:departments,id'],
        'effective_from'=>['required','date','before_or_equal:today'],
        'position_id'=>['nullable','integer',Rule::exists('staff_positions','id')],
        'gross_salary'=>['nullable','numeric','min:0'],
    ])->validate();
    $row=DB::table('employees')->where('id',$employee)->first();
    if (!$row) return response()->json(['message'=>'Не найден сотрудник'],404);
    if ($row->employment_status!=='Трудоустроен') return response()->json(['message'=>'Перевод доступен только действующему сотруднику'],409);
    if ((int)$row->department_id===(int)$data['department_id']) return response()->json(['message'=>'Выберите другое направление'],422);
    $position=null;
    if (!empty($data['position_id'])) {
        $position=DB::table('staff_positions')->find($data['position_id']);
        if (!$position || $position->closed_at!==null || (int)$position->direction_id!==(int)$data['department_id']) {
            return response()->json(['message'=>'Должность должна быть открыта в новом подразделении'],422);
        }
    }
    $assignment=DB::table('employment_assignment_history')->where('employee_id',$employee)->whereNull('effective_to')->first();
    if (!$assignment || $data['effective_from'] <= $assignment->effective_from) {
        return response()->json(['message'=>'Дата перевода должна быть позже начала текущего назначения'],409);
    }
    $salary=DB::table('salary_history')->where('employee_id',$employee)->whereNull('effective_to')->first();
    $changeSalary=array_key_exists('gross_salary',$data) && $data['gross_salary']!==null;
    if ($changeSalary && (!$salary || $data['effective_from'] <= $salary->effective_from)) {
        return response()->json(['message'=>'Дата нового оклада должна быть позже даты текущей зарплаты'],409);
    }
    DB::transaction(function () use ($employee,$data,$position,$assignment,$salary,$changeSalary) {
        $previous=CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString();
        DB::table('employment_assignment_history')->where('id',$assignment->id)
            ->update(['effective_to'=>$previous,'updated_at'=>now()]);
        DB::table('employment_assignment_history')->insert([
            'employee_id'=>$employee,'department_id'=>$data['department_id'],
            'position'=>$position?->name,'effective_from'=>$data['effective_from'],
            'effective_to'=>null,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('employees')->where('id',$employee)->update([
            'department_id'=>$data['department_id'],'position_id'=>$position?->id,
            'position'=>$position?->name,'updated_at'=>now(),
        ]);
        if ($changeSalary) {
            DB::table('salary_history')->where('id',$salary->id)
                ->update(['effective_to'=>$previous,'status'=>'Завершена','updated_at'=>now()]);
            DB::table('salary_history')->insert([
                'employee_id'=>$employee,'effective_from'=>$data['effective_from'],
                'effective_to'=>null,'gross_salary'=>$data['gross_salary'],
                'bonus'=>$salary->bonus,'status'=>'Действует',
                'comment'=>'Изменение оклада при кадровом переводе',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    });
    if ($row->keycloak_user_id) {
        try {
            $syncKeycloakDepartment((string)$row->keycloak_user_id,(int)$data['department_id']);
        } catch (Throwable $e) {
            report($e);
            DB::table('employees')->where('id',$employee)->update(['identity_status'=>'sync_failed','updated_at'=>now()]);
        }
    }
    return response()->json(['data'=>DB::table('employees')->find($employee)]);
});
