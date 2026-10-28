<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Application\AbsenceReadService;
use App\Support\CurrentEmployee;
use App\Support\ProductionCalendar;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class SystemController extends Controller
{
    public function __construct(private readonly AbsenceReadService $reads) {}

    public function health(): JsonResponse
    {
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
            'database' => Absence::query()->getConnection()->select('select 1') ? 'ok' : 'error',
            'employees' => $employees,
        ]);
    }

    public function calendar(Request $request, ProductionCalendar $calendar): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        return response()->json(['data' => $calendar->year((int) $validator->validated()['year'])]);
    }

    public function me(Request $request, CurrentEmployee $currentEmployee): JsonResponse
    {
        try {
            return response()->json(['data' => $currentEmployee->resolve($request)]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function calendarAbsences(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'employee_ids' => ['nullable', 'array', 'max:500'],
            'employee_ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $data = $validator->validated();

        // In V2, an omitted employee_ids filter MUST NOT become "all
        // employees" for a team/own role. The service-local scope resolves
        // allowed IDs first; the read use case keeps its legacy contract.
        $local = $request->attributes->get('vacations_local_permissions');
        if (is_array($local)) {
            $scope = (string) ($local['vacations.department.view']['scope'] ?? 'none');
            $ids = app(\App\Services\VacationsScopedAccess::class)->visibleEmployeeIds(
                $request, $scope,
                (int) $request->attributes->get('vacations_local_employee_id', 0),
                $data['employee_ids'] ?? []
            );
            if ($ids !== null) {
                if ($ids === []) return response()->json(['data' => []]);
                $data['employee_ids'] = $ids;
            }
        }

        $rows = $this->reads->calendarAbsences(
            $data['from'], $data['to'], $data['employee_ids'] ?? []
        );
        return response()->json(['data' => $rows]);
    }
}
