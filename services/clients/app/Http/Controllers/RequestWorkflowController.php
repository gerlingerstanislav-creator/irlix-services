<?php

namespace App\Http\Controllers;

use App\Support\ProductionDirection;
use App\Support\RequestLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            abort_unless(($access['platform_admin'] ?? false) || in_array((int) $data['responsible_employee_id'], $allowed, true), 422, 'Выберите ответственного сотрудника в доступной области.');
        }
        if (array_key_exists('request_date', $data) || array_key_exists('lifetime_weeks', $data)) {
            $current = DB::table('client_requests')->find($clientRequest);
            $requestDate = \Carbon\CarbonImmutable::parse($data['request_date'] ?? $current->request_date)->startOfDay();
            $lifetimeWeeks = (int) ($data['lifetime_weeks'] ?? $current->lifetime_weeks);
            $data['deadline'] = $requestDate->addWeeks($lifetimeWeeks)->toDateString();
        }
        DB::transaction(function () use ($clientRequest, $data): void {
            DB::table('client_requests')->where('id', $clientRequest)->lockForUpdate()->first();
            if (($data['status'] ?? null) === 'Закрыт') {
                $positionIds = DB::table('positions')->where('client_request_id', $clientRequest)
                    ->orderBy('id')->lockForUpdate()->pluck('id')->all();
                RequestLifecycle::closeNewAttempts($positionIds);
                DB::table('positions')->whereIn('id', $positionIds)->update(['status' => 'Закрыт', 'updated_at' => now()]);
            }
            DB::table('client_requests')->where('id', $clientRequest)->update([...$data, 'updated_at' => now()]);
        });
        return response()->json(['data' => DB::table('client_requests')->find($clientRequest)]);
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
            $data['responsible_rn_employee_id'] = ProductionDirection::responsibleId($request, [...(array) DB::table('positions')->find($position), ...$data]);
        }
        DB::transaction(function () use ($position, $data): void {
            [$parent, $row] = RequestLifecycle::lockPosition($position);
            if (($data['status'] ?? null) === 'Закрыт') RequestLifecycle::closeNewAttempts([$position]);
            if (($data['status'] ?? null) === 'Открыт') abort_if($parent->status === 'Закрыт', 422, 'Сначала откройте запрос.');
            DB::table('positions')->where('id', $position)->update([...$data, 'updated_at' => now()]);
        });
        return response()->json(['data' => DB::table('positions')->find($position)]);
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
            $id = DB::transaction(function () use ($data, $position, $originalName, $mimeType, $sizeBytes, $storedName): int {
                [$parent, $row] = RequestLifecycle::lockPosition($position);
                RequestLifecycle::assertOpen($parent, $row);
                $id = DB::table('connection_attempts')->insertGetId([
                    'position_id' => $position,
                    'specialist_id' => $data['specialist_id'],
                    'is_external' => (bool) ($data['is_external'] ?? false),
                    'specialist_name' => $data['specialist_name'],
                    'responsible_employee_id' => $data['responsible_employee_id'] ?? null,
                    'control_date' => $data['control_date'] ?? null,
                    'description' => $data['description'] ?? null,
                    'status' => 'Новая',
                    'cv_original_name' => $originalName,
                    'cv_mime_type' => $mimeType,
                    'cv_size_bytes' => $sizeBytes,
                    'cv_storage_path' => $storedName,
                    'cv_uploaded_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                return $id;
            });
        } catch (\Throwable $exception) {
            @unlink($directory.'/'.$storedName);
            throw $exception;
        }
        return response()->json(['data' => $this->attempt($id)], 201);
    }

    public function destroyAttempt(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        $row = DB::transaction(function () use ($attempt): object {
            $row = $this->lockedAttempt($attempt);
            abort_unless($row->status === 'Новая', 422, 'Удалить можно только новую попытку.');
            DB::table('connection_attempts')->where('id', $attempt)->delete();
            return $row;
        });
        if ($row->cv_storage_path) {
            $path = storage_path('app/clients/cv/'.$row->cv_storage_path);
            if (is_file($path)) @unlink($path);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function downloadCv(Request $request, int $attempt)
    {
        $row = $this->attemptRow($attempt);
        abort_unless($row->cv_storage_path, 404, 'CV не найдено.');
        $path = storage_path('app/clients/cv/'.$row->cv_storage_path);
        abort_unless(is_file($path), 404, 'Файл CV недоступен.');
        return response()->download($path, $row->cv_original_name, ['Content-Type' => $row->cv_mime_type ?: 'application/octet-stream']);
    }

    public function sendCv(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        DB::transaction(function () use ($attempt): void {
            $row = $this->attemptRow($attempt);
            [$parent, $position] = RequestLifecycle::lockPosition((int) $row->position_id);
            RequestLifecycle::assertOpen($parent, $position);
            $row = DB::table('connection_attempts')->where('id', $attempt)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'Новая', 422, 'CV можно отправить только для новой попытки.');
            abort_unless($row->cv_storage_path, 422, 'К попытке не прикреплено CV.');
            DB::table('connection_attempts')->where('id', $attempt)->update(['status' => 'CV отправлено', 'cv_sent_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function scheduleInterview(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);
        DB::transaction(function () use ($attempt, $data): void {
            $row = $this->lockedAttempt($attempt);
            abort_unless(in_array($row->status, ['CV отправлено', 'Интервью назначено', 'Интервью пройдено'], true), 422, 'Из текущего статуса интервью назначить нельзя.');
            $sequence = ((int) DB::table('attempt_interviews')->where('connection_attempt_id', $attempt)->max('sequence')) + 1;
            DB::table('attempt_interviews')->insert([
                'connection_attempt_id' => $attempt,
                'sequence' => $sequence,
                'scheduled_at' => $data['scheduled_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('connection_attempts')->where('id', $attempt)->update(['status' => 'Интервью назначено', 'updated_at' => now()]);
        });
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function completeInterview(Request $request, int $attempt, int $interview)
    {
        $this->assertPermission($request, 'attempts.manage');
        $data = $request->validate([
            'rating' => ['required', Rule::in(self::INTERVIEW_RATINGS)],
            'feedback' => ['required', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($attempt, $interview, $data): void {
            $row = $this->lockedAttempt($attempt);
            abort_unless($row->status === 'Интервью назначено', 422, 'Сейчас нет назначенного интервью.');
            $interviewRow = DB::table('attempt_interviews')->where('id', $interview)->where('connection_attempt_id', $attempt)->first();
            abort_unless($interviewRow, 404, 'Интервью не найдено.');
            abort_if($interviewRow->completed_at, 422, 'Итоги интервью уже заполнены.');
            DB::table('attempt_interviews')->where('id', $interview)->update([...$data, 'completed_at' => now(), 'updated_at' => now()]);
            $hasPending = DB::table('attempt_interviews')->where('connection_attempt_id', $attempt)->whereNull('completed_at')->exists();
            DB::table('connection_attempts')->where('id', $attempt)->update(['status' => $hasPending ? 'Интервью назначено' : 'Интервью пройдено', 'updated_at' => now()]);
        });
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function scheduleConnection(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        $data = $request->validate(['connection_date' => ['required', 'date']]);
        DB::transaction(function () use ($attempt, $data): void {
            $row = $this->lockedAttempt($attempt);
            abort_unless($row->status === 'Интервью пройдено', 422, 'Подключение можно назначить после пройденного интервью.');
            DB::table('connection_attempts')->where('id', $attempt)->update(['status' => 'Ожидает подключения', 'connection_date' => $data['connection_date'], 'updated_at' => now()]);
        });
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function closeSuccess(Request $request, int $attempt)
    {
        $this->assertPermission($request, 'attempts.manage');
        DB::transaction(function () use ($attempt): void {
            $row = $this->lockedAttempt($attempt);
            abort_unless($row->status === 'Ожидает подключения', 422, 'Успехом можно закрыть только попытку, ожидающую подключения.');
            DB::table('connection_attempts')->where('id', $attempt)->update([
                'status' => 'Закрыт: успех',
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
        });
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function closeFailure(Request $request, int $attempt)
    {
        DB::transaction(function () use ($attempt, $request): void {
            $row = $this->lockedAttempt($attempt);
            abort_if($this->isClosed($row->status), 422, 'Попытка уже закрыта.');
            $this->assertPermission($request, 'attempts.manage');
            $data = $request->validate(['reasons' => ['required', 'array', 'min:1'], 'reasons.*' => ['string', Rule::in(self::FAILURE_REASONS)]]);
            $reasons = array_values(array_unique($data['reasons']));
            foreach ($reasons as $reason) {
                if (str_starts_with($reason, 'CV:')) abort_unless($row->status === 'CV отправлено', 422, 'Причины CV доступны только в статусе «CV отправлено».');
                if (str_starts_with($reason, 'Интервью:')) abort_unless($row->status === 'Интервью назначено', 422, 'Причины интервью доступны только в статусе «Интервью назначено».');
            }
            DB::table('connection_attempts')->where('id', $attempt)->update([
                'status' => 'Закрыт: неудача',
                'closed_from_status' => $row->status,
                'failure_reasons' => json_encode($reasons, JSON_UNESCAPED_UNICODE),
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
        });
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    private function attempt(int $id): array
    {
        $row = (array) $this->attemptRow($id);
        unset($row['cv_storage_path']);
        $row['failure_reasons'] = json_decode((string) ($row['failure_reasons'] ?? '[]'), true) ?: [];
        $row['interviews'] = DB::table('attempt_interviews')->where('connection_attempt_id', $id)->orderBy('sequence')->get()->map(fn ($item) => (array) $item)->all();
        $row['has_connection'] = DB::table('project_members')->where('source_attempt_id', $id)->exists();
        return $row;
    }

    private function lockedAttempt(int $attempt): object
    {
        $current = $this->attemptRow($attempt);
        RequestLifecycle::lockPosition((int) $current->position_id);
        $row = DB::table('connection_attempts')->where('id', $attempt)->lockForUpdate()->first();
        abort_unless($row, 404, 'Попытка не найдена.');
        return $row;
    }

    private function attemptRow(int $attempt): object
    {
        $row = DB::table('connection_attempts')->find($attempt);
        abort_unless($row, 404, 'Попытка не найдена.');
        return $row;
    }

    private function assertEntity(string $table, int $id, string $message): void
    {
        abort_unless(DB::table($table)->where('id', $id)->exists(), 404, $message);
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
