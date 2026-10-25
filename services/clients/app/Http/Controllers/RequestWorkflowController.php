<?php

namespace App\Http\Controllers;

use App\Support\ProductionDirection;
use App\Support\RequestLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RequestWorkflowController extends Controller
{
    public const ATTEMPT_STATUSES = [
        'Новая', 'CV отправлено', 'Интервью назначено', 'Интервью пройдено',
        'Ожидает подключения', 'Закрыт: успех', 'Закрыт: неудача',
    ];

    public const FAILURE_REASONS = [
        'Запрос закрыт',
        'Заведомо не подходил по уровню',
        'Интервью: отрицательная ОС',
        'Отказ специалиста',
        'CV: Недостаточно отраслевого опыта',
        'Специалист уволился',
        'Интервью: Положительная ОС без подключения',
        'CV: Нет ОС',
        'Интервью: отменено',
        'CV: Недостаточно ком. опыта',
        'CV: Не пройдено',
        'CV: Недостаточно информации для положительного ответа',
        'CV: Нет опыта в требующейся технологии',
        'Подключение не состоялось',
        'Интервью: не прошел тестовое',
        'Причина не указана в старом сервисе',
    ];

    private const REQUEST_STATUSES = ['Открыт', 'Закрыт'];
    private const POSITION_STATUSES = ['Открыт', 'Закрыт'];
    private const EXPECTED_CONNECTION_TIMES = ['Неизвестно', 'Месяц', 'Квартал', 'Пол года', 'Год'];
    private const ACCEPTABLE_TU_FORMATS = ['Не важно', 'Штат', 'Штат / ГПХ'];
    private const INTERVIEW_RATINGS = ['Положительно', 'Нейтрально', 'Отрицательно'];

    private function workflow(): \App\Services\RequestWorkflowService
    {
        return app(\App\Services\RequestWorkflowService::class);
    }

    public function updateRequest(Request $request, int $clientRequest)
    {
        $this->assertPermission($request, 'requests.manage');
        $this->assertEntity('client_requests', $clientRequest, 'Запрос не найден.');
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'responsible_employee_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'request_date' => ['sometimes', 'required', 'date'],
            'lifetime_weeks' => ['sometimes', 'required', 'integer', Rule::in([1, 2, 3, 4])],
            'status' => ['sometimes', 'required', Rule::in(self::REQUEST_STATUSES)],
        ]);
        if (array_key_exists('responsible_employee_id', $data)) {
            $access = $this->access($request);
            $scope = (string) ($access['permissions']['requests.manage']['scope'] ?? 'none');
            $allowed = match ($scope) {
                'own' => [(int) ($access['employee']['id'] ?? 0)],
                'team' => array_map('intval', $access['team_employee_ids'] ?? []),
                'all' => array_map('intval', $access['employee_ids'] ?? []),
                default => [],
            };
            abort_unless(($access['platform_admin'] ?? false)
                || in_array((int) $data['responsible_employee_id'], $allowed, true),
                422, 'Выберите ответственного сотрудника в доступной области.');
        }
        return response()->json(['data' => $this->workflow()->updateRequest($clientRequest, $data)]);
    }

    public function updatePosition(Request $request, int $position)
    {
        $this->assertPermission($request, 'positions.manage');
        $this->assertEntity('positions', $position, 'Позиция не найдена.');
        $data = $request->validate([
            'direction' => ['sometimes', 'nullable', 'string', 'max:100'],
            'direction_department_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'responsible_rn_employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'technology' => ['sometimes', 'required', 'string', 'max:100'],
            'level' => ['sometimes', 'required', 'string', 'max:100'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'expected_connection_time' => ['sometimes', 'required', Rule::in(self::EXPECTED_CONNECTION_TIMES)],
            'acceptable_tu_format' => ['sometimes', 'required', Rule::in(self::ACCEPTABLE_TU_FORMATS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'required', Rule::in(self::POSITION_STATUSES)],
        ]);
        if (array_key_exists('direction', $data) || array_key_exists('direction_department_id', $data)) {
            $data = ProductionDirection::resolve($request, $data);
        }
        if (array_key_exists('responsible_rn_employee_id', $data)) {
            $data['responsible_rn_employee_id'] = ProductionDirection::responsibleId(
                $request, [...(array) $this->workflow()->positionRow($position), ...$data]
            );
        }
        return response()->json(['data' => $this->workflow()->updatePosition($position, $data)]);
    }

    public function storeAttempt(Request $request, int $position)
    {
        $this->assertPermission($request, 'attempts.manage');
        $this->assertEntity('positions', $position, 'Позиция не найдена.');
        $data = $request->validate([
            'is_external' => ['sometimes', 'boolean'],
            'specialist_id' => [Rule::requiredIf(!$request->boolean('is_external')), 'nullable', 'integer', 'min:1'],
            'specialist_name' => ['required', 'string', 'max:255'],
            'responsible_employee_id' => ['nullable', 'integer', 'min:1'],
            'control_date' => ['nullable', 'date'],
            'proposed_rate' => ['prohibited'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cv' => ['required', 'file', 'max:15360', 'mimes:pdf,doc,docx'],
        ]);
        $access = $this->access($request);
        $external = (bool) ($data['is_external'] ?? false);
        if ($external) {
            $data['specialist_id'] = null;
            $data['specialist_name'] = trim($data['specialist_name']);
            abort_if($data['specialist_name'] === '', 422, 'Введите ФИО внешнего специалиста.');
        } else {
            abort_unless(
                in_array((int) $data['specialist_id'], array_map('intval', $access['attempt_employee_ids'] ?? []), true),
                422,
                'Можно выбрать только специалиста своего производственного направления или его дочерних подразделений.'
            );
        }
        $file = $request->file('cv');
        abort_unless($file?->isValid(), 422, 'Не удалось загрузить CV.');
        $directory = storage_path('app/clients/cv');
        abort_if(!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory), 503, 'Не удалось подготовить хранилище CV.');
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
        $sizeBytes = $file->getSize() ?: 0;
        $storedName = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $file->move($directory, $storedName);

        try {
            $id = $this->workflow()->createAttempt($position, $data, [
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'stored_name' => $storedName,
            ]);
        } catch (\Throwable $exception) {
            @unlink($directory.'/'.$storedName);
            throw $exception;
        }
        return response()->json(['data' => $this->attempt($id)], 201);
    }

    public function destroyAttempt(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        $this->workflow()->deleteAttempt($attempt);
        return response()->json(['data' => ['deleted' => true]]);
    }

    public function downloadCv(Request $request, int $attempt)
    {
        $row = $this->workflow()->attemptRow($attempt);
        abort_unless($row->cv_storage_path, 404, 'CV не найдено.');
        $path = storage_path('app/clients/cv/'.$row->cv_storage_path);
        abort_unless(is_file($path), 404, 'Файл CV недоступен.');
        return response()->download($path, $row->cv_original_name, [
            'Content-Type' => $row->cv_mime_type ?: 'application/octet-stream',
        ]);
    }

    public function sendCv(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        return response()->json(['data' => $this->workflow()->sendCv($attempt)]);
    }

    public function scheduleInterview(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);
        return response()->json(['data' => $this->workflow()->scheduleInterview(
            $attempt, $data['scheduled_at']
        )]);
    }

    public function completeInterview(Request $request, int $attempt, int $interview)
    {
        $this->assertPermission($request, 'attempts.manage');
        $data = $request->validate([
            'rating' => ['required', Rule::in(self::INTERVIEW_RATINGS)],
            'feedback' => ['required', 'string', 'max:1000'],
        ]);
        return response()->json(['data' => $this->workflow()->completeInterview(
            $attempt, $interview, $data
        )]);
    }

    public function scheduleConnection(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        $data = $request->validate(['connection_date' => ['required', 'date']]);
        return response()->json(['data' => $this->workflow()->scheduleConnection(
            $attempt, $data['connection_date']
        )]);
    }

    public function closeSuccess(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        return response()->json(['data' => $this->workflow()->closeSuccess($attempt)]);
    }

    public function closeFailure(Request $request, int $attempt)
    {
        return response()->json(['data' => $this->workflow()->closeFailure(
            $attempt,
            function () use ($request): array {
                $this->assertPermission($request, 'attempts.manage');
                $data = $request->validate([
                    'reasons' => ['required', 'array', 'min:1'],
                    'reasons.*' => ['string', Rule::in(self::FAILURE_REASONS)],
                ]);
                return $data['reasons'];
            }
        )]);
    }

    private function attempt(int $id): array
    {
        return $this->workflow()->attempt($id);
    }

    private function lockedAttempt(int $attempt): object
    {
        return $this->workflow()->lockedAttempt($attempt);
    }

    private function attemptRow(int $attempt): object
    {
        return $this->workflow()->attemptRow($attempt);
    }

    private function assertEntity(string $table, int $id, string $message): void
    {
        $this->workflow()->assertEntity($table, $id, $message);
    }

    private function access(Request $request): array
    {
        return (array) $request->attributes->get('client_contour_access', []);
    }

    private function assertPermission(Request $request, string $permission): void
    {
        $access = $this->access($request);
        abort_unless(($access['platform_admin'] ?? false) || ($access['permissions'][$permission]['allowed'] ?? false), 403, 'Недостаточно прав для этого действия.');
    }

    private function isClosed(string $status): bool
    {
        return in_array($status, ['Закрыт: успех', 'Закрыт: неудача'], true);
    }
}
