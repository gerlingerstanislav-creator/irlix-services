<?php

namespace App\Services;

use App\Models\KeycloakAuthSync;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class AuthorizationSyncQueueService
{
    public function recoverStale(): void
    {
        KeycloakAuthSync::query()->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'retry', 'available_at' => now()]);
        KeycloakAuthSync::query()->where('status', 'synced')
            ->where('synced_at', '<', now()->subDay())
            ->update(['status' => 'pending', 'available_at' => now()]);
    }

    public function ready()
    {
        return KeycloakAuthSync::query()->whereIn('status', ['pending', 'retry'])
            ->where('available_at', '<=', now())->orderBy('available_at')->limit(25)->get();
    }

    public function claim(KeycloakAuthSync $item): bool
    {
        return KeycloakAuthSync::query()->where('employee_id', $item->employee_id)
            ->where('version', $item->version)->whereIn('status', ['pending', 'retry'])
            ->update(['status' => 'processing']) > 0;
    }

    public function markSynced(KeycloakAuthSync $item): void
    {
        KeycloakAuthSync::query()->where('employee_id', $item->employee_id)
            ->where('version', $item->version)->where('status', 'processing')
            ->update([
                'status' => 'synced', 'synced_at' => now(),
                'attempts' => 0, 'last_error' => null,
            ]);
    }

    public function markFailure(KeycloakAuthSync $item, Throwable $e, int $maxAttempts): void
    {
        $attempts = (int) $item->attempts + 1;
        $refused = $e instanceof ConnectionException
            && str_contains($e->getMessage(), 'cURL error 7:');
        $delay = $refused
            ? min(30, 5 * (2 ** min($attempts, 3)))
            : min(3600, 5 * (2 ** min($attempts, 9)));

        KeycloakAuthSync::query()->where('employee_id', $item->employee_id)
            ->where('version', $item->version)->where('status', 'processing')
            ->update([
                'status' => $attempts >= $maxAttempts ? 'failed' : 'retry',
                'attempts' => $attempts,
                'available_at' => now()->addSeconds($delay),
                'last_error' => mb_substr($e->getMessage(), 0, 600),
            ]);
    }
}
