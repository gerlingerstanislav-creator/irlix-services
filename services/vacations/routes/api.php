<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\WorkspaceController;
use App\Support\CurrentEmployee;
use App\Support\ProductionCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

Route::get('/health', function () {
    $employeesBase = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
    $employees = 'unavailable';
    try { $response = Http::timeout(3)->get("{$employeesBase}/health"); $employees = $response->successful() ? 'ok' : 'error'; } catch (\Throwable) { $employees = 'error'; }
    return response()->json(['service' => 'vacations','status' => 'ok','database' => DB::select('select 1') ? 'ok' : 'error','employees' => $employees]);
});

Route::get('/production-calendar', function (Request $request, ProductionCalendar $calendar) {
    $validator = Validator::make($request->all(), ['year' => ['required', 'integer', 'min:2000', 'max:2100']]);
    if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
    return response()->json(['data' => $calendar->year((int) $validator->validated()['year'])]);
});

Route::get('/me', function (Request $request, CurrentEmployee $currentEmployee) {
    try { return response()->json(['data' => $currentEmployee->resolve($request)]); }
    catch (\DomainException $e) { return response()->json(['message' => $e->getMessage()], 404); }
    catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 503); }
});

// Cross-service calendar read model. Additional type/status fields are backward-compatible
// and let Timesheets distinguish provided and pending absence categories.
Route::get('/calendar-absences', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
        'employee_ids' => ['nullable', 'array', 'max:500'], 'employee_ids.*' => ['integer', 'min:1'],
    ]);
    if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
    $data = $validator->validated();
    $query = DB::table('absences')->select(['employee_id','type','status','starts_on','ends_on'])
        ->whereDate('starts_on','<=',$data['to'])->whereDate('ends_on','>=',$data['from']);
    if (!empty($data['employee_ids'])) $query->whereIn('employee_id', array_values(array_unique($data['employee_ids'])));
    return response()->json(['data' => $query->orderBy('employee_id')->orderBy('starts_on')->get()]);
});

Route::get('/workspace', [WorkspaceController::class, 'overview']);
Route::get('/registry', [WorkspaceController::class, 'registry']);
Route::get('/history', [WorkspaceController::class, 'history']);
Route::get('/absences/{absence}/workspace', [WorkspaceController::class, 'show'])->whereNumber('absence');
Route::get('/absences', [AbsenceController::class, 'index']);
Route::get('/absences/occupied', [AbsenceController::class, 'occupied']);
Route::post('/absences', [AbsenceController::class, 'store']);
Route::post('/absences/for-employee', [AbsenceController::class, 'storeForEmployee']);
Route::get('/absences/{absence}', [AbsenceController::class, 'show'])->whereNumber('absence');
Route::patch('/absences/{absence}', [AbsenceController::class, 'update'])->whereNumber('absence');
Route::post('/absences/{absence}/submit', [AbsenceController::class, 'submit'])->whereNumber('absence');
Route::get('/absences/{absence}/history', [AbsenceController::class, 'history'])->whereNumber('absence');
Route::get('/absences/{absence}/attachments', [AttachmentController::class, 'index'])->whereNumber('absence');
Route::post('/absences/{absence}/attachments', [AttachmentController::class, 'store'])->whereNumber('absence');
Route::get('/absences/{absence}/attachments/{attachment}/download', [AttachmentController::class, 'download'])->whereNumber('absence')->whereNumber('attachment');
Route::delete('/absences/{absence}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('absence')->whereNumber('attachment');
Route::get('/approvals', [ApprovalController::class, 'index']);
Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->whereNumber('approval');
Route::post('/absences/{absence}/return-to-planned', [ApprovalController::class, 'returnToPlanned'])->whereNumber('absence');
