<?php

namespace App\Services;

use App\Models\OutboxEvent;
use Illuminate\Support\Collection;
use Throwable;

final class OutboxDeliveryService
{
    public function pending(int $limit = 50): Collection
    {
        return OutboxEvent::query()
            ->whereIn('status', ['pending', 'retry'])
            ->where('available_at', '<=', now())
            ->orderBy('occurred_at')->limit($limit)->get();
    }

    public function markPublished(OutboxEvent $event): void
    {
        OutboxEvent::query()->whereKey($event->id)->update([
            'status' => 'published',
            'attempts' => (int) $event->attempts + 1,
            'published_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markFailed(OutboxEvent $event, Throwable $error, int $maxAttempts): void
    {
        $attempts = (int) $event->attempts + 1;
        $deadLettered = $attempts >= $maxAttempts;
        $delaySeconds = min(3600, 5 * (2 ** min($attempts, 9)));
        OutboxEvent::query()->whereKey($event->id)->update([
            'status' => $deadLettered ? 'dead_lettered' : 'retry',
            'attempts' => $attempts,
            'available_at' => now()->addSeconds($delaySeconds),
            'last_error' => mb_substr($error->getMessage(), 0, 4000),
        ]);
    }
}
