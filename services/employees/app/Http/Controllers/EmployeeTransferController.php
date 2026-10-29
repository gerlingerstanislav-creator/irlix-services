<?php

namespace App\Http\Controllers;

use App\Models\EmploymentPeriod;
use App\Models\StaffPosition;
use App\Services\EmployeeAssignmentHistoryService;
use App\Services\EmployeeReadService;
use App\Services\EmployeeTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use DomainException;

final class EmployeeTransferController extends Controller
{
    public function __construct(
        private readonly EmployeeTransferService $transfers,
        private readonly EmployeeAssignmentHistoryService $history,
        private readonly EmployeeReadService $employees,
    ) {}

    public function transfer(Request $request, int $employee): JsonResponse
    {
        if (!($request->attributes->get('employees_access', [])['permissions']['employees.transfer'] ?? false)) {
            return response()->json(['message' => 'Кадровый перевод доступен только кадровику.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'position_id' => ['nullable', 'integer', Rule::exists('staff_positions', 'id')],
            'effective_from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gross_salary' => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $employeeRecord = $this->employees->record($employee);
        if (!$employeeRecord) return response()->json(['message' => 'Сотрудник не найден.'], 404);
        if ($employeeRecord->employment_status !== 'Трудоустроен') {
            return response()->json(['message' => 'Перевод доступен только трудоустроенному сотруднику.'], 422);
        }

        $data = $validator->validated();
        if ((string) $employeeRecord->department_id === (string) $data['department_id']) {
            return response()->json(['message' => 'Выберите другое направление.'], 422);
        }
        $position = isset($data['position_id'])
            ? StaffPosition::query()->find((int) $data['position_id']) : null;
        if ($position && ($position->closed_at !== null
            || (string) $position->direction_id !== (string) $data['department_id'])) {
            return response()->json(['message' => 'Выбранная должность не принадлежит направлению или закрыта.'], 422);
        }

        $current = $this->transfers->currentAssignment($employee);
        if (!$current || $data['effective_from'] <= $current->effective_from) {
            return response()->json(['message' => 'Дата перевода должна быть позже начала текущего назначения.'], 422);
        }
        $period = EmploymentPeriod::query()->where('employee_id', $employee)->whereNull('ended_at')->first();
        if (!$period || $data['effective_from'] < $period->started_at) {
            return response()->json(['message' => 'Дата перевода должна попадать в действующее трудоустройство.'], 422);
        }
        $salary = $this->transfers->currentSalary($employee);
        if (array_key_exists('gross_salary', $data)
            && (!$salary || $data['effective_from'] <= $salary->effective_from)) {
            return response()->json(['message' => 'Для смены оклада нужна текущая запись с более ранней датой.'], 422);
        }

        $this->transfers->transfer($employeeRecord, $data, $position, $current, $salary);
        return response()->json(['data' => $this->employees->raw($employee)]);
    }

    public function updateHistory(Request $request, int $employee, int $assignment): JsonResponse
    {
        $access = (array) $request->attributes->get('employees_access', []);
        if (!in_array('platform-admin', (array) ($access['roles'] ?? []), true)) {
            return response()->json(['message' => 'Редактирование истории доступно только администратору платформы.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'position_id' => ['present', 'nullable', 'integer', Rule::exists('staff_positions', 'id')],
            'effective_from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'effective_to' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $row = $this->employees->record($employee);
        if (!$row) return response()->json(['message' => 'Сотрудник не найден.'], 404);
        try {
            $updated = $this->history->update($row, $assignment, $validator->validated());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => (object) $updated->getRawOriginal()]);
    }
}
