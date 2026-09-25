<?php

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

$absenceTypes = ['paid_vacation', 'unpaid_vacation', 'sick_leave', 'maternity_leave', 'day_off'];
$employeesBase = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');

$currentEmployee = function (Request $request) use ($employeesBase): array {
    $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
    if (!$token) throw new RuntimeException('Authentication token missing');

    try {
        $response = Http::withToken($token)->acceptJson()->timeout(5)->get("{$employeesBase}/self");
    } catch (Throwable) {
        throw new RuntimeException('Employees service unavailable');
    }
    if ($response->status() === 404) throw new DomainException('Employee profile is not linked to this account');
    if (!$response->successful()) throw new RuntimeException('Employees profile lookup failed: '.$response->status());
    $employee = $response->json('data');
    if (!is_array($employee) || empty($employee['id'])) throw new RuntimeException('Employees profile lookup returned invalid data');
    return $employee;
};

Route::get('/health', function () use ($employeesBase) {
    $employees = 'unavailable';
    try {
        $response = Http::timeout(3)->get("{$employeesBase}/health");
        $employees = $response->successful() ? 'ok' : 'error';
    } catch (Throwable) { $employees = 'error'; }
    return response()->json([
        'service' => 'vacations',
        'status' => 'ok',
        'database' => DB::select('select 1') ? 'ok' : 'error',
        'employees' => $employees,
    ]);
});

Route::get('/me', function (Request $request) use ($currentEmployee) {
    try {
        return response()->json(['data' => $currentEmployee($request)]);
    } catch (DomainException $e) {
        return response()->json(['message' => $e->getMessage()], 404);
    } catch (RuntimeException $e) {
        return response()->json(['message' => $e->getMessage()], 503);
    }
});

Route::get('/absences', function (Request $request) use ($currentEmployee) {
    try { $employee = $currentEmployee($request); }
    catch (DomainException $e) { return response()->json(['message' => $e->getMessage()], 404); }
    catch (RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 503); }

    $year = (int) ($request->query('year') ?: now()->year);
    if ($year < 2000 || $year > 2100) return response()->json(['message' => 'Invalid year'], 422);

    $items = DB::table('absences')
        ->where('employee_id', $employee['id'])
        ->where(function ($query) use ($year) {
            $query->whereYear('starts_on', $year)->orWhereYear('ends_on', $year);
        })
        ->orderBy('starts_on')
        ->orderBy('id')
        ->get();

    return response()->json(['data' => $items, 'meta' => ['year' => $year, 'count' => $items->count()]]);
});

Route::post('/absences', function (Request $request) use ($currentEmployee, $absenceTypes) {
    try { $employee = $currentEmployee($request); }
    catch (DomainException $e) { return response()->json(['message' => $e->getMessage()], 404); }
    catch (RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 503); }

    $validator = Validator::make($request->all(), [
        'type' => ['required', Rule::in($absenceTypes)],
        'starts_on' => ['required', 'date'],
        'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        'comment' => ['nullable', 'string', 'max:2000'],
    ]);
    if ($validator->fails()) return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
    $data = $validator->validated();

    $overlap = DB::table('absences')
        ->where('employee_id', $employee['id'])
        ->where('status', '!=', 'cancelled')
        ->whereDate('starts_on', '<=', $data['ends_on'])
        ->whereDate('ends_on', '>=', $data['starts_on'])
        ->exists();
    if ($overlap) return response()->json(['message' => 'На выбранные даты уже запланировано отсутствие'], 422);

    $starts = Carbon::parse($data['starts_on'])->startOfDay();
    $ends = Carbon::parse($data['ends_on'])->startOfDay();
    $days = $starts->diffInDays($ends) + 1;
    $identity = (array) $request->attributes->get('identity', []);

    $id = DB::table('absences')->insertGetId([
        'employee_id' => $employee['id'],
        'type' => $data['type'],
        'starts_on' => $starts->toDateString(),
        'ends_on' => $ends->toDateString(),
        'calendar_days' => $days,
        'status' => 'planned',
        'comment' => $data['comment'] ?? null,
        'created_by_subject' => (string) ($identity['sub'] ?? ''),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json(['data' => DB::table('absences')->where('id', $id)->first()], 201);
});
