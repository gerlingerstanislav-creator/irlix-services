<?php

namespace App\Http\Controllers;

use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;
use App\Models\SalaryHistory;
use App\Models\StaffPosition;
use App\Services\EmployeeLifecycleService;
use App\Services\EmployeeReadService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

final class EmployeeController extends Controller
{
    private const COOPERATION_TYPES = ['Штат', 'ГПХ', 'ИП', 'Самозанятый'];

    public function __construct(
        private readonly EmployeeLifecycleService $lifecycle,
        private readonly EmployeeReadService $read
    ) {}

    private function rules(?int $employee = null): array
    {
        return [
            'full_name' => ['nullable','string','max:255'],
            'first_name' => ['nullable','string','max:255'],
            'last_name' => ['nullable','string','max:255'],
            'middle_name' => ['nullable','string','max:255'],
            'gender' => ['nullable', Rule::in(['Мужчина', 'Женщина'])],
            'login' => [
                'nullable','string','max:64',
                'regex:/^[a-z0-9]+(?:\.[a-z0-9]+)+$/',
                Rule::unique('employees', 'login')->ignore($employee),
            ],
            'work_email' => ['nullable','email','max:255',
                Rule::unique('employees', 'work_email')->ignore($employee)],
            'personal_email' => ['nullable','email','max:255'],
            'department_id' => ['nullable','integer','exists:departments,id'],
            'position' => ['nullable','string','max:255'],
            'specialization' => ['nullable','string','max:255'],
            'employment_status' => ['nullable', Rule::in([
                'Ожидает трудоустройства','Трудоустроен','Уволен',
            ])],
            'work_format' => ['nullable', Rule::in(['Офис','Удалённо'])],
            'cooperation_type' => ['nullable', Rule::in(self::COOPERATION_TYPES)],
            'hired_at' => ['nullable','date'],
            'fired_at' => ['nullable','date'],
            'birth_date' => ['nullable','date'],
            'city' => ['nullable','string','max:255'],
            'phone' => ['nullable','string','max:64'],
            'telegram' => ['nullable','string','max:255'],
            'skype' => ['nullable','string','max:255'],
            'is_remote' => ['nullable','boolean'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $access = $request->attributes->get('employees_access', []);
        $rules = $this->rules();
        $rules['first_name'] = ['required','string','max:255'];
        $rules['last_name'] = ['required','string','max:255'];
        $rules['gender'] = ['required', Rule::in(['Мужчина','Женщина'])];
        $rules['login'] = [
            'required','string','max:64','regex:/^[a-z0-9]+(?:\.[a-z0-9]+)+$/',
            Rule::unique('employees', 'login'),
        ];
        $rules['personal_email'] = ['required','email','max:255'];
        $rules['department_id'] = ['required','integer','exists:departments,id'];
        $rules['hired_at'] = ['nullable','date'];
        $rules['cooperation_type'] = ['required',Rule::in(self::COOPERATION_TYPES)];
        $rules['gross_salary'] = ['required','numeric','min:0'];
        $rules['hire_immediately'] = ['sometimes','boolean'];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $data = $validator->validated();
        if (($access['scope'] ?? 'none') !== 'all'
            && !in_array((int) $data['department_id'],
                array_map('intval', $access['department_ids'] ?? []), true)) {
            return response()->json(['message' => 'Подразделение вне доступного контура'], 403);
        }
        if (!empty($data['hire_immediately'])
            && !($access['permissions']['employees.hire'] ?? false)) {
            return response()->json(['message' => 'Только кадровик может трудоустроить сразу.'], 403);
        }
        if (!empty($data['hire_immediately']) && empty($data['hired_at'])) {
            return response()->json(['errors' => ['hired_at' => [
                'Укажите дату трудоустройства.',
            ]]], 422);
        }
        $id = $this->lifecycle->application($data);
        if (!empty($data['hire_immediately'])) {
            $hireRequest = Request::create('/api/employees/'.$id.'/hire', 'POST', [
                'started_at' => $request->input('hired_at'),
                'gross_salary' => $data['gross_salary'],
            ]);
            $hireRequest->attributes->set('employees_access', $access);
            $hireResponse = $this->hire($hireRequest, $id);
            if ($hireResponse->getStatusCode() !== 200) return response()->json([
                'data' => $this->read->raw($id),
                'meta' => [
                    'warning' => 'Заявка сохранена, но трудоустройство не завершилось. Кадровик может повторить действие в карточке.',
                ],
            ], 201);
            return response()->json(['data' => $this->read->raw($id)], 201);
        }
        return response()->json([
            'data' => $this->read->raw($id),
            'meta' => ['identity' => 'not_provisioned', 'onboarding_email' => 'not_requested'],
        ], 201);
    }

    public function hire(Request $request, int $employee): JsonResponse
    {
        if (!($request->attributes->get('employees_access', [])['permissions']['employees.hire'] ?? false)) {
            return response()->json(['message' => 'Hiring permission required'], 403);
        }
        $data = Validator::make($request->all(), [
            'started_at' => ['required','date'],
            'gross_salary' => ['required','numeric','min:0'],
        ])->validate();
        $row = $this->read->record($employee);
        if (!$row) return response()->json(['message' => 'Employee not found'], 404);
        if ($row->employment_status !== 'Ожидает трудоустройства') return response()->json([
            'message' => 'Трудоустроить можно только сотрудника в ожидании.',
        ], 409);
        try {
            $this->lifecycle->hire($row, $data['started_at'], $data['gross_salary']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Не удалось создать учётную запись Keycloak. Заявка сохранена, трудоустройство не выполнено.',
            ], 502);
        }
        return response()->json(['data' => $this->read->raw($employee)]);
    }

    public function update(Request $request, int $employee): JsonResponse
    {
        $current = $this->read->record($employee);
        if (!$current) return response()->json(['message' => 'Employee not found'], 404);
        if ($current->login === 'admin' && $request->has('login')
            && $request->input('login') !== 'admin') return response()->json([
                'message' => 'Логин системного аккаунта IRLIX нельзя изменить.',
            ], 409);
        $validator = Validator::make($request->all(), $this->rules($employee));
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $data = $validator->validated();
        foreach (['employment_status','cooperation_type','hired_at','fired_at','work_email'] as $field) {
            unset($data[$field]);
        }
        if (!$data) return response()->json(['message' => 'No editable attributes supplied'], 422);
        if (array_key_exists('login', $data)) $data['work_email'] = $data['login'].'@irlix.ru';
        try {
            $this->lifecycle->update($current, $data);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Не удалось синхронизировать данные сотрудника с Keycloak.',
            ], 502);
        }
        return response()->json(['data' => $this->read->raw($employee)]);
    }

    public function dismiss(Request $request, int $employee): JsonResponse
    {
        $validator = Validator::make($request->all(), ['date' => ['required','date']]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $row = $this->read->record($employee);
        if (!$row) return response()->json(['message' => 'Employee not found'], 404);
        if ($row->login === 'admin') return response()->json([
            'message' => 'Системный аккаунт IRLIX нельзя уволить.',
        ], 409);
        if ($row->employment_status !== 'Трудоустроен') return response()->json([
            'message' => 'Уволить можно только трудоустроенного сотрудника.',
        ], 422);
        $date = $validator->validated()['date'];
        $period = EmploymentPeriod::query()->where('employee_id', $employee)
            ->whereNull('ended_at')->first();
        if (!$period) return response()->json(['message' => 'У сотрудника нет открытой записи ТУ.'], 422);
        if ($date < $period->started_at) return response()->json(['errors' => ['date' => [
            'Дата увольнения не может быть раньше даты начала текущего ТУ.',
        ]]], 422);
        try {
            $this->lifecycle->dismiss($row, $date);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Не удалось заблокировать пользователя в Keycloak.'], 502);
        }
        return response()->json(['data' => $this->read->raw($employee)]);
    }

    public function rehire(Request $request, int $employee): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'started_at' => ['required','date'],
            'cooperation_type' => ['required',Rule::in(self::COOPERATION_TYPES)],
            'department_id' => ['required','integer','exists:departments,id'],
            'position' => ['nullable','string','max:255'],
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $row = $this->read->record($employee);
        if (!$row) return response()->json(['message' => 'Employee not found'], 404);
        if ($row->employment_status !== 'Уволен') return response()->json([
            'message' => 'Вернуть можно только уволенного сотрудника.',
        ], 422);
        if (EmploymentPeriod::query()->where('employee_id', $employee)
            ->whereNull('ended_at')->exists()) {
            return response()->json(['message' => 'У сотрудника уже есть открытая запись ТУ.'], 422);
        }
        try {
            $this->lifecycle->rehire($row, $validator->validated());
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Не удалось восстановить пользователя в Keycloak.'], 502);
        }
        return response()->json(['data' => $this->read->raw($employee)]);
    }

    public function changeCooperation(Request $request, int $employee): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effective_from' => ['required','date'],
            'cooperation_type' => ['required',Rule::in(self::COOPERATION_TYPES)],
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $row = $this->read->record($employee);
        if (!$row) return response()->json(['message' => 'Employee not found'], 404);
        if ($row->employment_status !== 'Трудоустроен') return response()->json([
            'message' => 'Тип сотрудничества можно менять только у трудоустроенного сотрудника.',
        ], 422);
        $period = EmploymentPeriod::query()->where('employee_id', $employee)
            ->whereNull('ended_at')->first();
        if (!$period) return response()->json(['message' => 'У сотрудника нет открытой записи ТУ.'], 422);
        $data = $validator->validated();
        if ($data['effective_from'] <= $period->started_at) return response()->json([
            'errors' => ['effective_from' => ['Дата изменения должна быть позже начала текущего ТУ.']],
        ], 422);
        $this->lifecycle->changeCooperation($row, $period, $data);
        return response()->json(['data' => $this->read->raw($employee)]);
    }

    public function addSalary(Request $request, int $employee): JsonResponse
    {
        $row = $this->read->record($employee);
        if (!$row) return response()->json(['message' => 'Employee not found'], 404);
        $validator = Validator::make($request->all(), [
            'effective_from' => ['required','date'],
            'gross_salary' => ['required','numeric','min:0'],
            'bonus' => ['nullable','numeric','min:0'],
            'comment' => ['nullable','string','max:2000'],
            'position_id' => ['nullable','integer',Rule::exists('staff_positions', 'id')],
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $data = $validator->validated();
        $position = null;
        if (($data['position_id'] ?? null) !== null) {
            $position = StaffPosition::query()->whereKey((int) $data['position_id'])->first();
            if (!$position || $position->closed_at !== null) return response()->json([
                'errors' => ['position_id' => ['Выберите открытую должность.']],
            ], 422);
            if ((string) $position->direction_id !== (string) ($row->department_id ?? '')) {
                return response()->json(['errors' => ['position_id' => [
                    'Должность должна принадлежать текущему подразделению сотрудника.',
                ]]], 422);
            }
        }
        $active = SalaryHistory::query()->where('employee_id', $employee)
            ->whereNull('effective_to')->first();
        if ($active && $data['effective_from'] <= $active->effective_from) {
            return response()->json(['errors' => ['effective_from' => [
                'Новая зарплата должна начинаться позже текущей действующей зарплаты.',
            ]]], 422);
        }
        $changed = (string) ($data['position_id'] ?? '')
            !== (string) ($row->position_id ?? '');
        $assignment = $changed
            ? EmploymentAssignmentHistory::query()->where('employee_id', $employee)
                ->whereNull('effective_to')->first()
            : null;
        if ($assignment && $data['effective_from'] <= $assignment->effective_from) {
            return response()->json(['errors' => ['effective_from' => [
                'Дата смены должности должна быть позже начала текущего назначения.',
            ]]], 422);
        }
        $id = $this->lifecycle->addSalary($row, $data, $position);
        $record = SalaryHistory::query()->whereKey($id)->first();
        return response()->json(['data' => $record ? (object) $record->getRawOriginal() : null], 201);
    }

    public function deleteSalary(int $employee, int $salary): \Symfony\Component\HttpFoundation\Response
    {
        $item = SalaryHistory::query()->whereKey($salary)->where('employee_id', $employee)->first();
        if (!$item) return response()->json(['message' => 'Salary history item not found'], 404);
        $this->lifecycle->deleteSalary($employee, $item);
        return response()->noContent();
    }
}
