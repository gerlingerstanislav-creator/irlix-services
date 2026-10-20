<?php
use App\Support\CurrentEmployee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Reuses the same assignment, directory and permission/scope contracts as /workspace
// and /management. No new cross-service access to raw tables is introduced.
Route::get('/attention', function (Request $request, CurrentEmployee $currentEmployee) use (
    $assignments, $directory, $accessInfo, $scopeForPeriod, $absenceData
) {
    $current = $currentEmployee->resolve($request);
    $employeeId = (int) $current['id'];
    $today = Carbon::today();
    $months = [$today->copy()->subMonthNoOverflow()->startOfMonth()];
    if ($today->day >= 25) $months[] = $today->copy()->startOfMonth();
    $allAssignments = $assignments($request);
    $tasks = [];
    foreach ($months as $start) {
        $end = $start->copy()->endOfMonth();
        $from = $start->toDateString();
        $to = $end->toDateString();
        $month = $start->format('Y-m');
        $ownAssignments = collect($allAssignments)->filter(fn (array $a) =>
            (int) $a['employee_id'] === $employeeId && $a['valid_from'] <= $to
            && (empty($a['valid_to']) || $a['valid_to'] >= $from))->values();
        if ($ownAssignments->isNotEmpty()) {
            $entries = DB::table('timesheet_entries')->where('employee_id', $employeeId)
                ->whereBetween('work_date', [$from, $to])->get();
            $entryDates = $entries->filter(fn ($row) => (float) $row->hours > 0)
                ->pluck('work_date')->map(fn ($date) => substr((string) $date,0,10))->unique()->all();
            $confirmedDates = DB::table('employee_confirmations')
                ->where('employee_id', $employeeId)->whereBetween('work_date', [$from,$to])
                ->pluck('work_date')->map(fn ($date)=>substr((string)$date,0,10))->unique()->all();
            $absenceRows = $absenceData($request,$from,$to,[$employeeId]);
            $missing = 0;
            $date = $start->copy();
            $last = $end->lessThan($today) ? $end : $today->copy()->subDay();
            while ($date->lte($last)) {
                $key = $date->toDateString();
                if ($date->isWeekday() && $ownAssignments->contains(fn ($a) =>
                    $a['valid_from'] <= $key && (empty($a['valid_to']) || $a['valid_to'] >= $key))) {
                    $absent = collect($absenceRows)->contains(fn ($a) =>
                        in_array($a['status'] ?? '', ['confirmed','provided'],true)
                        && $a['starts_on'] <= $key && (empty($a['ends_on']) || $a['ends_on'] >= $key));
                    if (!$absent && !in_array($key,$entryDates,true)) $missing++;
                }
                $date->addDay();
            }
            if ($missing) {
                $tasks[] = [
                    'id'=>'fill:'.$month,'title'=>'Заполнить таймшиты за '.$start->translatedFormat('F Y'),
                    'detail'=>'Нет записей за '.$missing.' рабочих дней','kind'=>'attention',
                    'due_at'=>$to,'href'=>'/timesheets/mine/?month='.$month,
                ];
            } elseif (count($entryDates) && count(array_diff($entryDates,$confirmedDates))) {
                $tasks[] = [
                    'id'=>'confirm:'.$month,'title'=>'Подтвердить таймшиты за '.$start->translatedFormat('F Y'),
                    'detail'=>'Записи заполнены, но не все дни подтверждены','kind'=>'attention',
                    'due_at'=>$to,'href'=>'/timesheets/mine/?month='.$month,
                ];
            }
        }

        [$employees,$departments] = $directory($request);
        $access = $accessInfo($request,$current,$allAssignments,$departments);
        if (!$access['platformAdmin'] && !($access['contourPermissions']['timesheets.management.view']['allowed'] ?? false)) continue;
        [$employeeIds,$visible] = $scopeForPeriod($current,$employees,$allAssignments,$access,$from,$to);
        if (!$employeeIds || $visible->isEmpty()) continue;
        $submittedIds = DB::table('employee_confirmations')->whereIn('employee_id',$employeeIds)
            ->whereBetween('work_date',[$from,$to])->pluck('employee_id')->map(fn ($id)=>(int)$id)->unique()->all();
        if (!$submittedIds) continue;
        $approvalKeys = DB::table('final_approvals')->whereIn('employee_id',$submittedIds)
            ->where('month',$from)->get()->map(fn ($row)=>$row->employee_id.':'.$row->project_id)->all();
        $pending = $visible->filter(fn ($a)=>in_array((int)$a['employee_id'],$submittedIds,true)
            && !in_array($a['employee_id'].':'.$a['project_id'],$approvalKeys,true))
            ->map(fn ($a)=>$a['employee_id'].':'.$a['project_id'])->unique()->count();
        if ($pending) {
            $tasks[] = [
                'id'=>'approve:'.$month,'title'=>'Согласовать таймшиты сотрудников',
                'detail'=>'Ожидают подтверждения: '.$pending.' подключений за '.$start->translatedFormat('F Y'),
                'kind'=>'attention','due_at'=>$to,'href'=>'/timesheets/management/?month='.$month,
            ];
        }
    }
    return response()->json(['data'=>$tasks])->header('Cache-Control','no-store');
});
