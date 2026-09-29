<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/employees/{employee}/purge', function (Request $request, int $employee) {
    $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
    $provided = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        return response()->json(['message' => 'Invalid internal purge token'], 403);
    }

    $name = trim((string) $request->input('employee_name', ''));
    $username = trim((string) $request->input('username', ''));

    DB::transaction(function () use ($employee, $name, $username): void {
        $onboardingIds = DB::table('onboarding_processes')->where('employee_id', $employee)->pluck('id')->all();
        if ($onboardingIds) {
            DB::table('tasks')->whereIn('entity_id', $onboardingIds)->whereIn('entity_type', ['onboarding', 'onboarding_process'])->delete();
            DB::table('onboarding_processes')->whereIn('id', $onboardingIds)->delete();
        }
        DB::table('employment_requests')->where('employee_id', $employee)->update(['employee_id' => null]);

        foreach (array_values(array_filter([$name, $username])) as $identityText) {
            DB::table('tasks')->where('assignee', $identityText)->update(['assignee' => null]);
        }
        if ($name !== '') {
            foreach ([
                ['candidates', 'recruiter_name'],
                ['recruitment_requests', 'manager_name'],
                ['recruitment_requests', 'recruiter_name'],
                ['hiring_processes', 'recruiter_name'],
                ['employment_requests', 'manager_name'],
            ] as [$table, $column]) {
                DB::table($table)->where($column, $name)->update([$column => 'Удалённый сотрудник']);
            }
            DB::table('stage_history')->where('actor', $name)->update(['actor' => 'Удалённый сотрудник']);
            DB::table('activities')->where('actor', $name)->update(['actor' => 'Удалённый сотрудник']);
            DB::table('evaluations')->where('author', $name)->update(['author' => 'Удалённый сотрудник']);
        }
    });

    return response()->json(['purged' => true]);
});
