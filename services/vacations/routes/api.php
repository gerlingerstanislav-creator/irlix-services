<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\ApprovalController;
use App\Support\CurrentEmployee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $employeesBase = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
    $employees = 'unavailable';
    try {
        $response = Http::timeout(3)->get("{$employeesBase}/health");
        $employees = $response->successful() ? 'ok' : 'error';
    } catch (\Throwable) {
        $employees = 'error';
    }

    return response()->json([
        'service' => 'vacations',
        'status' => 'ok',
        'database' => DB::select('select 1') ? 'ok' : 'error',
        'employees' => $employees,
    ]);
});

Route::get('/me', function (Request $request, CurrentEmployee $currentEmployee) {
    try {
        return response()->json(['data' => $currentEmployee->resolve($request)]);
    } catch (\DomainException $e) {
        return response()->json(['message' => $e->getMessage()], 404);
    } catch (\RuntimeException $e) {
        return response()->json(['message' => $e->getMessage()], 503);
    }
});

Route::get('/absences', [AbsenceController::class, 'index']);
Route::post('/absences', [AbsenceController::class, 'store']);
Route::get('/absences/{absence}', [AbsenceController::class, 'show'])->whereNumber('absence');
Route::patch('/absences/{absence}', [AbsenceController::class, 'update'])->whereNumber('absence');
Route::post('/absences/{absence}/submit', [AbsenceController::class, 'submit'])->whereNumber('absence');
Route::get('/absences/{absence}/history', [AbsenceController::class, 'history'])->whereNumber('absence');

Route::get('/approvals', [ApprovalController::class, 'index']);
Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->whereNumber('approval');
Route::post('/absences/{absence}/return-to-planned', [ApprovalController::class, 'returnToPlanned'])->whereNumber('absence');
