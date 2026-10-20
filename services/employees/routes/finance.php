<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/finance/payroll-conditions',function(Request $request){
    $secret=(string)env('IRLIX_FINANCE_EMPLOYEES_TOKEN','');
    $received=(string)$request->header('X-Irlix-Finance-Employees-Token','');
    abort_unless(strlen($secret)>=32 && $received!=='' && hash_equals($secret,$received),403,
        'Salary data are restricted to Finance integration');
    $data=$request->validate(['month'=>['required','date_format:Y-m']]);
    $first=\Carbon\Carbon::parse($data['month'].'-01')->toDateString();
    $last=\Carbon\Carbon::parse($data['month'].'-01')->endOfMonth()->toDateString();
    $employees=DB::table('employees as e')
        ->leftJoin('departments as d','d.id','=','e.department_id')
        ->select('e.id','e.full_name','e.department_id','d.name as department_name',
            'e.employment_status')->orderBy('e.id')->get();
    $salary=DB::table('salary_history')
        ->whereDate('effective_from','<=',$last)
        ->where(fn($q)=>$q->whereNull('effective_to')->orWhereDate('effective_to','>=',$first))
        ->select('employee_id','gross_salary','effective_from','effective_to','status')->get();
    return response()->json(['data'=>[
        'month'=>$data['month'],'employees'=>$employees,'salary_conditions'=>$salary,
        'source'=>'Employees.salary_history',
        'note'=>'Это согласованные условия gross, не факт начисления или банковской выплаты'
    ]]);
});


// Read-only referential check. A Finance payroll accrual must not reference
// an invented employee ID; do not return salaries or private employee profile.
Route::get('/finance/employees/{employee}',function(Request $request,int $employee){
    $secret=(string)env('IRLIX_FINANCE_EMPLOYEES_TOKEN','');
    $received=(string)$request->header('X-Irlix-Finance-Employees-Token','');
    abort_unless(strlen($secret)>=32 && $received!=='' && hash_equals($secret,$received),403,
        'Finance employee directory access forbidden');
    abort_unless(DB::table('employees')->where('id',$employee)->exists(),404,
        'Employee ID does not exist');
    return response()->json(['data'=>['id'=>$employee]]);
})->whereNumber('employee');
