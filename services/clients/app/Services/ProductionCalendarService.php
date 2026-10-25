<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class ProductionCalendarService
{
    /** Fetch only the requested year's shared Platform Core calendar over its API. */
    public function fetch(int $year, ?string $bearerToken): array
    {
        $base = rtrim((string) env('PRODUCTION_CALENDAR_API_URL', 'http://platform-core:8000/api'), '/');
        try {
            $response = Http::withToken((string) $bearerToken)->timeout(8)
                ->get($base.'/production-calendar', ['year' => $year]);
            if (!$response->successful()) {
                return ['message' => 'Производственный календарь недоступен'];
            }
            $calendar = $response->json('data');
            if (!is_array($calendar) || empty($calendar['available'])) {
                return ['message' => 'Производственный календарь за этот год не опубликован'];
            }
            return ['data' => $calendar];
        } catch (\Throwable $error) {
            return ['message' => 'Производственный календарь недоступен'];
        }
    }
}
