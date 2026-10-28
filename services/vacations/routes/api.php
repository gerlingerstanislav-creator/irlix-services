<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\AttentionController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\ClientServiceCalendarController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VacationsPermissionsController;
use App\Http\Controllers\VacationsAccessController;

Route::get('/health', [SystemController::class, 'health']);
Route::get('/permissions/me', [VacationsAccessController::class, 'me']);
Route::get('/permissions', [VacationsPermissionsController::class, 'index']);
Route::put('/permissions', [VacationsPermissionsController::class, 'update']);
Route::delete('/permissions', [VacationsPermissionsController::class, 'remove']);
Route::get('/production-calendar', [SystemController::class, 'calendar']);
Route::get('/me', [SystemController::class, 'me']);
Route::get('/calendar-absences', [SystemController::class, 'calendarAbsences']);
Route::get('/client-service-calendar', ClientServiceCalendarController::class);
Route::get('/client-service-absence-alerts', [ClientServiceCalendarController::class, 'alerts']);

Route::get('/attention', [AttentionController::class, 'index']);
Route::get('/workspace', [WorkspaceController::class, 'overview']);
Route::get('/department-roster', [WorkspaceController::class, 'departmentRoster']);
Route::get('/registry', [WorkspaceController::class, 'registry']);
Route::get('/history', [WorkspaceController::class, 'history']);
Route::get('/absences/{absence}/workspace', [WorkspaceController::class, 'show'])->whereNumber('absence');
Route::get('/absences', [AbsenceController::class, 'index']);
Route::get('/absences/occupied', [AbsenceController::class, 'occupied']);
Route::post('/absences', [AbsenceController::class, 'store']);
Route::post('/absences/for-employee', [AbsenceController::class, 'storeForEmployee']);
Route::get('/absences/{absence}', [AbsenceController::class, 'show'])->whereNumber('absence');
Route::patch('/absences/{absence}', [AbsenceController::class, 'update'])->whereNumber('absence');
Route::delete('/absences/{absence}', [AbsenceController::class, 'destroy'])->whereNumber('absence');
Route::post('/absences/{absence}/submit', [AbsenceController::class, 'submit'])->whereNumber('absence');
Route::get('/absences/{absence}/history', [AbsenceController::class, 'history'])->whereNumber('absence');
Route::get('/absences/{absence}/attachments', [AttachmentController::class, 'index'])->whereNumber('absence');
Route::post('/absences/{absence}/attachments', [AttachmentController::class, 'store'])->whereNumber('absence');
Route::get('/absences/{absence}/attachments/{attachment}/download', [AttachmentController::class, 'download'])->whereNumber('absence')->whereNumber('attachment');
Route::delete('/absences/{absence}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('absence')->whereNumber('attachment');
Route::get('/approvals', [ApprovalController::class, 'index']);
Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->whereNumber('approval');
Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->whereNumber('approval');
Route::post('/absences/{absence}/return-to-planned', [ApprovalController::class, 'returnToPlanned'])->whereNumber('absence');
