<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\StaffPosition;
use App\Services\EmployeeTransferService;
use App\Services\PayrollOverviewService;
use App\Services\SalaryReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SalaryReviewController extends Controller
{
    public function __construct(
        private readonly SalaryReviewService $reviews,
        private readonly PayrollOverviewService $payroll,
        private readonly EmployeeTransferService $transfers
    ) {}

    private function access(Request $request, string $permission): array
    {
        $access = (array) $request->attributes->get('employees_access', []);
        abort_unless($access['permissions'][$permission] ?? false, 403, 'Недостаточно прав');
        if (str_starts_with($permission, 'employees.fot.')) {
            abort_unless($access['permissions']['employees.salary.read'] ?? false,
                403, 'Нет права просмотра окладов');
        }
        return $access;
    }

    public function overview(Request $request): JsonResponse
    {
        $access = $this->access($request, 'employees.fot.view');
        $data = Validator::make($request->query(), [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ])->validate();
        $permitted = array_map('intval', $access['department_ids'] ?? []);
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;
        if ($departmentId !== null && !in_array($departmentId, $permitted, true)) {
            return response()->json(['message' => 'Подразделение недоступно'], 403);
        }
        $result = $this->payroll->overview((int) $data['year'], $permitted, $departmentId);
        $status = $result['status'];
        unset($result['status']);
        return response()->json($status === 200 ? ['data' => $result['data']] : $result, $status);
    }

    public function index(Request $request): JsonResponse
    {
        $access = $this->access($request, 'employees.fot.view');
        return response()->json(['data' => $this->reviews->list($access)]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $access = $this->access($request, 'employees.fot.approve');
        return response()->json(['data' => $this->reviews->inbox((int) ($access['employee_id'] ?? 0))]);
    }

    public function store(Request $request): JsonResponse
    {
        $access = $this->access($request, 'employees.fot.plan');
        $data = Validator::make($request->all(), [
            'employee_id' => ['required','integer','exists:employees,id'],
            'effective_month' => ['required','date','date_format:Y-m-d'],
            'gross_salary' => ['required','numeric','min:0'],
            'comment' => ['nullable','string','max:3000'],
        ])->validate();
        if (CarbonImmutable::parse($data['effective_month'])->day !== 1) {
            return response()->json(['message' => 'Пересмотр может начинаться только с первого числа'], 422);
        }
        if (!$this->reviews->scope($access, (int) $data['employee_id'])) {
            return response()->json(['message' => 'Сотрудник вне вашего направления'], 403);
        }
        if ($this->reviews->conflicts((int) $data['employee_id'], $data['effective_month'])) {
            return response()->json(['message' => 'Пересмотр на этот месяц уже существует'], 409);
        }
        return response()->json([
            'data' => $this->reviews->create($data, (int) $access['employee_id']),
        ], 201);
    }

    public function update(Request $request, int $review): JsonResponse
    {
        $access = $this->access($request, 'employees.fot.plan');
        $item = $this->reviews->review($review);
        if (!$item) return response()->json(['message' => 'Пересмотр не найден'], 404);
        if ((int) $item->created_by !== (int) $access['employee_id']
            || !$this->reviews->scope($access, (int) $item->employee_id)) {
            return response()->json(['message' => 'Недоступно'], 403);
        }
        if (!in_array($item->status, ['draft','returned','approved'], true)) {
            return response()->json(['message' => 'Нельзя редактировать текущий статус'], 409);
        }
        $data = Validator::make($request->all(), [
            'effective_month' => ['sometimes','required','date','date_format:Y-m-d'],
            'gross_salary' => ['sometimes','required','numeric','min:0'],
            'comment' => ['nullable','string','max:3000'],
        ])->validate();
        $month = $data['effective_month'] ?? $item->effective_month;
        if (CarbonImmutable::parse($month)->day !== 1) {
            return response()->json(['message' => 'Нужен первый день месяца'], 422);
        }
        if ($this->reviews->conflicts((int) $item->employee_id, $month, $review)) {
            return response()->json(['message' => 'Пересмотр на этот месяц уже существует'], 409);
        }
        return response()->json([
            'data' => $this->reviews->edit($item, $data, (int) $access['employee_id']),
        ]);
    }

    public function submit(Request $request, int $review): JsonResponse
    {
        $access = $this->access($request, 'employees.fot.plan');
        $item = $this->reviews->review($review);
        if (!$item) return response()->json(['message' => 'Не найдено'], 404);
        if ((int) $item->created_by !== (int) $access['employee_id']
            || !$this->reviews->scope($access, (int) $item->employee_id)) {
            return response()->json(['message' => 'Недоступно'], 403);
        }
        if (!in_array($item->status, ['draft','returned'], true)) {
            return response()->json(['message' => 'Требуется черновик или возврат'], 409);
        }
        $chain = $this->reviews->chain((int) $item->employee_id, (int) $access['employee_id']);
        return response()->json(['data' => $this->reviews->submit($item, $chain)]);
    }

    public function decision(Request $request, int $review): JsonResponse
    {
        $access = $this->access($request, 'employees.fot.approve');
        $data = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['approved','returned'])],
            'comment' => ['required_if:decision,returned','nullable','string','max:3000'],
        ])->validate();
        $result = $this->reviews->decide($review, (int) $access['employee_id'], $data);
        $status = $result['status'];
        unset($result['status']);
        return response()->json($result, $status);
    }

    public function cancel(Request $request, int $review): JsonResponse
    {
        $access = $this->access($request, 'employees.fot.plan');
        $item = $this->reviews->review($review);
        if (!$item) return response()->json(['message' => 'Не найдено'], 404);
        if ((int) $item->created_by !== (int) $access['employee_id']
            || !$this->reviews->scope($access, (int) $item->employee_id)) {
            return response()->json(['message' => 'Недоступно'], 403);
        }
        return response()->json(['data' => $this->reviews->cancel($item)]);
    }

    public function transfer(Request $request, int $employee): JsonResponse
    {
        $access = (array) $request->attributes->get('employees_access', []);
        if (!($access['permissions']['employees.transfer'] ?? false)) {
            return response()->json(['message' => 'Перевод доступен только кадровику'], 403);
        }
        $data = Validator::make($request->all(), [
            'department_id' => ['required','integer','exists:departments,id'],
            'effective_from' => ['required','date','before_or_equal:today'],
            'position_id' => ['nullable','integer',Rule::exists('staff_positions','id')],
            'gross_salary' => ['nullable','numeric','min:0'],
        ])->validate();
        $row = Employee::query()->whereKey($employee)->first();
        if (!$row) return response()->json(['message' => 'Не найден сотрудник'], 404);
        if ($row->employment_status !== 'Трудоустроен') {
            return response()->json(['message' => 'Перевод доступен только действующему сотруднику'], 409);
        }
        if ((int) $row->department_id === (int) $data['department_id']) {
            return response()->json(['message' => 'Выберите другое направление'], 422);
        }
        $position = null;
        if (!empty($data['position_id'])) {
            $position = StaffPosition::query()->whereKey($data['position_id'])->first();
            if (!$position || $position->closed_at !== null
                || (int) $position->direction_id !== (int) $data['department_id']) {
                return response()->json(['message' => 'Должность должна быть открыта в новом подразделении'], 422);
            }
        }
        $assignment = $this->transfers->currentAssignment($employee);
        if (!$assignment || $data['effective_from'] <= $assignment->effective_from) {
            return response()->json(['message' => 'Дата перевода должна быть позже начала текущего назначения'], 409);
        }
        $salary = $this->transfers->currentSalary($employee);
        $changeSalary = array_key_exists('gross_salary', $data) && $data['gross_salary'] !== null;
        if ($changeSalary && (!$salary || $data['effective_from'] <= $salary->effective_from)) {
            return response()->json(['message' => 'Дата нового оклада должна быть позже даты текущей зарплаты'], 409);
        }
        return response()->json(['data' =>
            $this->transfers->transfer($row, $data, $position, $assignment, $salary),
        ]);
    }
}
