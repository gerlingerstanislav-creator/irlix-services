<?php

namespace App\Support;

use App\Models\ClientRequest;
use App\Models\Position;
use App\Models\ConnectionAttempt;

final class RequestLifecycle
{
    public const CLOSED_ATTEMPTS = ['Закрыт: успех', 'Закрыт: неудача'];

    public static function requestDisplayStatus(string $status, iterable $positions): string
    {
        if ($status === 'Закрыт') return 'Закрыт';
        foreach ($positions as $position) {
            if (($position['display_status'] ?? $position['status'] ?? null) === 'В работе') return 'В работе';
        }
        return 'Открыт';
    }

    // Always lock in request -> position -> attempt order.
    public static function lockPosition(int $positionId): array
    {
        $position = Position::query()->find($positionId);
        abort_unless($position, 404, 'Позиция не найдена.');
        $request = ClientRequest::query()->where('id', $position->client_request_id)->lockForUpdate()->first();
        $position = Position::query()->where('id', $positionId)->lockForUpdate()->first();
        abort_unless($request && $position, 404, 'Позиция не найдена.');
        return [$request, $position];
    }

    public static function assertOpen(object $request, object $position): void
    {
        abort_if($request->status === 'Закрыт' || $position->status === 'Закрыт', 422, 'Запрос или позиция закрыты.');
    }

    public static function closeNewAttempts(array $positionIds): void
    {
        $attempts = ConnectionAttempt::query()->whereIn('position_id', $positionIds)
            ->orderBy('id')->lockForUpdate()->get();
        abort_if($attempts->contains(fn ($attempt) => $attempt->status !== 'Новая'
            && !in_array($attempt->status, self::CLOSED_ATTEMPTS, true)), 422, 'Сперва закройте активные попытки');
        ConnectionAttempt::query()->whereIn('position_id', $positionIds)->where('status', 'Новая')->update([
            'status' => 'Закрыт: неудача',
            'closed_from_status' => 'Новая',
            'failure_reasons' => json_encode(['Запрос закрыт'], JSON_UNESCAPED_UNICODE),
            'closed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
