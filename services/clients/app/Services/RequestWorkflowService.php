<?php

namespace App\Services;

use App\Models\ClientRequest;
use App\Models\Position;
use App\Models\ConnectionAttempt;
use App\Models\AttemptInterview;
use App\Support\RequestLifecycle;
use Illuminate\Support\Facades\DB;

final class RequestWorkflowService
{
    private static function raw($model): ?object
    {
        return $model ? (object) $model->getRawOriginal() : null;
    }

    public function assertEntity(string $table, int $id, string $message): void
    {
        $model = match ($table) {
            'client_requests' => ClientRequest::class,
            'positions' => Position::class,
            default => null,
        };
        abort_unless($model && $model::query()->whereKey($id)->exists(), 404, $message);
    }

    public function requestRow(int $id): object
    {
        $row = self::raw(ClientRequest::query()->find($id));
        abort_unless($row, 404, 'Запрос не найден.');
        return $row;
    }

    public function positionRow(int $id): object
    {
        $row = self::raw(Position::query()->find($id));
        abort_unless($row, 404, 'Позиция не найдена.');
        return $row;
    }

    public function updateRequest(int $id, array $data): object
    {
        if (array_key_exists('request_date', $data) || array_key_exists('lifetime_weeks', $data)) {
            $current = $this->requestRow($id);
            $requestDate = \Carbon\CarbonImmutable::parse($data['request_date'] ?? $current->request_date)
                ->startOfDay();
            $lifetimeWeeks = (int) ($data['lifetime_weeks'] ?? $current->lifetime_weeks);
            $data['deadline'] = $requestDate->addWeeks($lifetimeWeeks)->toDateString();
        }

        DB::transaction(static function () use ($id, $data): void {
            ClientRequest::query()->whereKey($id)->lockForUpdate()->first();
            if (($data['status'] ?? null) === 'Закрыт') {
                $positionIds = Position::query()->where('client_request_id', $id)
                    ->orderBy('id')->lockForUpdate()->pluck('id')->all();
                RequestLifecycle::closeNewAttempts($positionIds);
                Position::query()->whereIn('id', $positionIds)->update([
                    'status' => 'Закрыт', 'updated_at' => now(),
                ]);
            }
            ClientRequest::query()->whereKey($id)->update([...$data, 'updated_at' => now()]);
        });
        return $this->requestRow($id);
    }

    public function updatePosition(int $id, array $data): object
    {
        DB::transaction(static function () use ($id, $data): void {
            [$parent, $row] = RequestLifecycle::lockPosition($id);
            if (($data['status'] ?? null) === 'Закрыт') RequestLifecycle::closeNewAttempts([$id]);
            if (($data['status'] ?? null) === 'Открыт') {
                abort_if($parent->status === 'Закрыт', 422, 'Сначала откройте запрос.');
            }
            Position::query()->whereKey($id)->update([...$data, 'updated_at' => now()]);
        });
        return $this->positionRow($id);
    }

    public function createAttempt(int $positionId, array $data, array $cv): int
    {
        return DB::transaction(static function () use ($positionId, $data, $cv): int {
            [$parent, $row] = RequestLifecycle::lockPosition($positionId);
            RequestLifecycle::assertOpen($parent, $row);
            return ConnectionAttempt::query()->insertGetId([
                'position_id' => $positionId,
                'specialist_id' => $data['specialist_id'],
                'is_external' => (bool) ($data['is_external'] ?? false),
                'specialist_name' => $data['specialist_name'],
                'responsible_employee_id' => $data['responsible_employee_id'] ?? null,
                'control_date' => $data['control_date'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => 'Новая',
                'cv_original_name' => $cv['original_name'],
                'cv_mime_type' => $cv['mime_type'],
                'cv_size_bytes' => $cv['size_bytes'],
                'cv_storage_path' => $cv['stored_name'],
                'cv_uploaded_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function deleteAttempt(int $id): void
    {
        $row = DB::transaction(function () use ($id): object {
            $row = $this->lockedAttempt($id);
            abort_unless($row->status === 'Новая', 422, 'Удалить можно только новую попытку.');
            ConnectionAttempt::query()->whereKey($id)->delete();
            return $row;
        });
        if ($row->cv_storage_path) {
            $path = storage_path('app/clients/cv/'.$row->cv_storage_path);
            if (is_file($path)) @unlink($path);
        }
    }

    public function sendCv(int $id): array
    {
        DB::transaction(function () use ($id): void {
            $row = $this->attemptRow($id);
            [$parent, $position] = RequestLifecycle::lockPosition((int) $row->position_id);
            RequestLifecycle::assertOpen($parent, $position);
            $row = self::raw(ConnectionAttempt::query()->whereKey($id)->lockForUpdate()->first());
            abort_unless($row && $row->status === 'Новая', 422, 'CV можно отправить только для новой попытки.');
            abort_unless($row->cv_storage_path, 422, 'К попытке не прикреплено CV.');
            ConnectionAttempt::query()->whereKey($id)->update([
                'status' => 'CV отправлено', 'cv_sent_at' => now(), 'updated_at' => now(),
            ]);
        });
        return $this->attempt($id);
    }

    public function scheduleInterview(int $id, string $scheduledAt): array
    {
        DB::transaction(function () use ($id, $scheduledAt): void {
            $row = $this->lockedAttempt($id);
            abort_unless(in_array($row->status, [
                'CV отправлено', 'Интервью назначено', 'Интервью пройдено',
            ], true), 422, 'Из текущего статуса интервью назначить нельзя.');
            $sequence = ((int) AttemptInterview::query()
                ->where('connection_attempt_id', $id)->max('sequence')) + 1;
            AttemptInterview::query()->insert([
                'connection_attempt_id' => $id, 'sequence' => $sequence,
                'scheduled_at' => $scheduledAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ConnectionAttempt::query()->whereKey($id)->update([
                'status' => 'Интервью назначено', 'updated_at' => now(),
            ]);
        });
        return $this->attempt($id);
    }

    public function completeInterview(int $id, int $interview, array $data): array
    {
        DB::transaction(function () use ($id, $interview, $data): void {
            $row = $this->lockedAttempt($id);
            abort_unless($row->status === 'Интервью назначено', 422, 'Сейчас нет назначенного интервью.');
            $item = self::raw(AttemptInterview::query()->whereKey($interview)
                ->where('connection_attempt_id', $id)->first());
            abort_unless($item, 404, 'Интервью не найдено.');
            abort_if($item->completed_at, 422, 'Итоги интервью уже заполнены.');
            AttemptInterview::query()->whereKey($interview)->update([
                ...$data, 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $pending = AttemptInterview::query()->where('connection_attempt_id', $id)
                ->whereNull('completed_at')->exists();
            ConnectionAttempt::query()->whereKey($id)->update([
                'status' => $pending ? 'Интервью назначено' : 'Интервью пройдено',
                'updated_at' => now(),
            ]);
        });
        return $this->attempt($id);
    }

    public function scheduleConnection(int $id, string $date): array
    {
        DB::transaction(function () use ($id, $date): void {
            $row = $this->lockedAttempt($id);
            abort_unless($row->status === 'Интервью пройдено', 422,
                'Подключение можно назначить после пройденного интервью.');
            ConnectionAttempt::query()->whereKey($id)->update([
                'status' => 'Ожидает подключения', 'connection_date' => $date,
                'updated_at' => now(),
            ]);
        });
        return $this->attempt($id);
    }

    public function closeSuccess(int $id): array
    {
        DB::transaction(function () use ($id): void {
            $row = $this->lockedAttempt($id);
            abort_unless($row->status === 'Ожидает подключения', 422,
                'Успехом можно закрыть только попытку, ожидающую подключения.');
            ConnectionAttempt::query()->whereKey($id)->update([
                'status' => 'Закрыт: успех', 'closed_at' => now(), 'updated_at' => now(),
            ]);
        });
        return $this->attempt($id);
    }

    /**
     * Authorization and validation callback is executed under the original
     * attempt/position lock, preserving the closed-before-permission ordering.
     */
    public function closeFailure(int $id, callable $authorizeAndValidate): array
    {
        DB::transaction(function () use ($id, $authorizeAndValidate): void {
            $row = $this->lockedAttempt($id);
            abort_if(in_array($row->status, ['Закрыт: успех', 'Закрыт: неудача'], true),
                422, 'Попытка уже закрыта.');
            $reasons = array_values(array_unique($authorizeAndValidate()));
            foreach ($reasons as $reason) {
                if (str_starts_with($reason, 'CV:')) {
                    abort_unless($row->status === 'CV отправлено', 422,
                        'Причины CV доступны только в статусе «CV отправлено».');
                }
                if (str_starts_with($reason, 'Интервью:')) {
                    abort_unless($row->status === 'Интервью назначено', 422,
                        'Причины интервью доступны только в статусе «Интервью назначено».');
                }
            }
            ConnectionAttempt::query()->whereKey($id)->update([
                'status' => 'Закрыт: неудача',
                'closed_from_status' => $row->status,
                'failure_reasons' => json_encode($reasons, JSON_UNESCAPED_UNICODE),
                'closed_at' => now(), 'updated_at' => now(),
            ]);
        });
        return $this->attempt($id);
    }

    public function attemptRow(int $id): object
    {
        $row = self::raw(ConnectionAttempt::query()->find($id));
        abort_unless($row, 404, 'Попытка не найдена.');
        return $row;
    }

    public function lockedAttempt(int $id): object
    {
        $current = $this->attemptRow($id);
        RequestLifecycle::lockPosition((int) $current->position_id);
        $row = self::raw(ConnectionAttempt::query()->whereKey($id)->lockForUpdate()->first());
        abort_unless($row, 404, 'Попытка не найдена.');
        return $row;
    }

    public function attempt(int $id): array
    {
        $row = (array) $this->attemptRow($id);
        unset($row['cv_storage_path']);
        $row['failure_reasons'] = json_decode((string) ($row['failure_reasons'] ?? '[]'), true) ?: [];
        $row['interviews'] = AttemptInterview::query()->where('connection_attempt_id', $id)
            ->orderBy('sequence')->get()
            ->map(static fn ($item) => $item->getRawOriginal())->all();
        $row['has_connection'] = !empty($row['client_member_id']);
        return $row;
    }

    /** Create a request and its initial positions atomically. */
    public function createRequest(array $data, int $responsibleId): object
    {
        $id = DB::transaction(static function () use ($data, $responsibleId): int {
            $createdAt = now();
            $requestDate = \Carbon\CarbonImmutable::parse($data['request_date'])->startOfDay();
            $id = ClientRequest::query()->insertGetId([
                'client_id' => $data['client_id'] ?? null,
                'lead_id' => $data['lead_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'responsible_employee_id' => $responsibleId,
                'request_date' => $requestDate->toDateString(),
                'lifetime_weeks' => $data['lifetime_weeks'],
                'deadline' => $requestDate->addWeeks($data['lifetime_weeks'])->toDateString(),
                'status' => $data['status'] ?? 'Открыт',
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            Position::query()->insert(array_map(static fn (array $position): array => [
                'client_request_id' => $id,
                'technology' => $position['technology'],
                'direction' => $position['direction'],
                'direction_department_id' => $position['direction_department_id'],
                'responsible_rn_employee_id' => $position['responsible_rn_employee_id'],
                'level' => $position['level'],
                'quantity' => $position['quantity'],
                'expected_connection_time' => $position['expected_connection_time'] ?? 'Неизвестно',
                'acceptable_tu_format' => $position['acceptable_tu_format'] ?? 'Не важно',
                'description' => $position['description'] ?? null,
                'status' => $data['status'] ?? 'Открыт',
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ], $data['positions']));
            return $id;
        });
        return $this->requestRow($id);
    }

    /** Lock parent request before insertion to prevent new positions on closed requests. */
    public function createPosition(int $requestId, array $data): object
    {
        $id = DB::transaction(static function () use ($requestId, $data): int {
            $parent = ClientRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            abort_unless($parent, 404, 'Запрос не найден.');
            abort_if($parent->status === 'Закрыт', 422, 'Запрос закрыт.');
            return Position::query()->insertGetId([
                'client_request_id' => $requestId,
                'direction' => $data['direction'],
                'direction_department_id' => $data['direction_department_id'],
                'responsible_rn_employee_id' => $data['responsible_rn_employee_id'],
                'technology' => $data['technology'],
                'level' => $data['level'],
                'quantity' => $data['quantity'],
                'expected_connection_time' => $data['expected_connection_time'] ?? 'Неизвестно',
                'acceptable_tu_format' => $data['acceptable_tu_format'] ?? 'Не важно',
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'Открыт',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        return $this->positionRow($id);
    }
}
