<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class ProductionCalendar
{
    public function year(int $year): array
    {
        $this->assertYear($year);
        $this->syncYearIfDue($year);

        $days = DB::table('production_calendar_days')
            ->where('year', $year)
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->date => [
                    'code' => (int) $row->code,
                    'is_working' => (bool) $row->is_working,
                    'is_day_off' => (bool) $row->is_day_off,
                    'is_holiday' => (bool) $row->is_holiday,
                    'is_short' => (bool) $row->is_short,
                ],
            ])->all();

        $meta = (array) (DB::table('production_calendar_years')->where('year', $year)->first() ?? []);

        return [
            'year' => $year,
            'days' => $days,
            'available' => count($days) === $this->expectedDays($year),
            'source' => $meta['source'] ?? 'isdayoff',
            'state' => $meta['state'] ?? 'unknown',
            'last_checked_at' => $meta['last_checked_at'] ?? null,
            'synced_at' => $meta['synced_at'] ?? null,
        ];
    }

    public function syncHorizon(?int $baseYear = null, int $futureYears = 2): void
    {
        $baseYear ??= (int) now()->year;
        for ($year = $baseYear; $year <= $baseYear + max(0, $futureYears); $year++) {
            $this->syncYearIfDue($year);
        }
    }

    public function syncYearIfDue(int $year): void
    {
        $this->assertYear($year);
        $meta = DB::table('production_calendar_years')->where('year', $year)->first();
        if (!$this->isDue($year, $meta ? (array) $meta : null)) return;

        $checkedAt = now();

        try {
            $codes = $this->fetchYear($year);
            if ($codes === null) {
                DB::table('production_calendar_years')->updateOrInsert(
                    ['year' => $year],
                    [
                        'source' => 'isdayoff',
                        'state' => 'not_published',
                        'last_checked_at' => $checkedAt,
                        'last_error' => null,
                    ]
                );
                return;
            }

            $syncedAt = now();
            $rows = [];
            $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
            foreach (str_split($codes) as $index => $rawCode) {
                $code = (int) $rawCode;
                $rows[] = [
                    'date' => $start->addDays($index)->format('Y-m-d'),
                    'year' => $year,
                    'code' => $code,
                    'is_working' => in_array($code, [0, 2, 4], true),
                    'is_day_off' => in_array($code, [1, 8], true),
                    'is_holiday' => $code === 8,
                    'is_short' => $code === 2,
                    'source' => 'isdayoff',
                    'synced_at' => $syncedAt,
                ];
            }

            DB::transaction(function () use ($year, $rows, $checkedAt, $syncedAt) {
                DB::table('production_calendar_days')->where('year', $year)->delete();
                foreach (array_chunk($rows, 200) as $chunk) DB::table('production_calendar_days')->insert($chunk);
                DB::table('production_calendar_years')->updateOrInsert(
                    ['year' => $year],
                    [
                        'source' => 'isdayoff',
                        'state' => 'available',
                        'last_checked_at' => $checkedAt,
                        'synced_at' => $syncedAt,
                        'last_error' => null,
                    ]
                );
            });
        } catch (Throwable $error) {
            DB::table('production_calendar_years')->updateOrInsert(
                ['year' => $year],
                [
                    'source' => 'isdayoff',
                    'state' => 'error',
                    'last_checked_at' => $checkedAt,
                    'last_error' => mb_substr($error->getMessage(), 0, 4000),
                ]
            );
        }
    }

    private function fetchYear(int $year): ?string
    {
        $url = (string) env('PRODUCTION_CALENDAR_URL', 'https://isdayoff.ru/api/getdata');
        $response = Http::timeout(8)
            ->accept('text/plain')
            ->withHeaders(['User-Agent' => 'isdayoff-php-app/1.0 (IRLIX Vacations)'])
            ->get($url, ['year' => $year, 'cc' => 'ru', 'pre' => 1, 'holiday' => 1]);

        $body = trim($response->body());
        if ($response->status() === 404 || $body === '101') return null;
        if (!$response->successful()) throw new RuntimeException("isDayOff HTTP {$response->status()}");

        if (strlen($body) !== $this->expectedDays($year) || preg_match('/[^01248]/', $body)) {
            throw new RuntimeException('isDayOff returned invalid production calendar payload');
        }

        return $body;
    }

    private function isDue(int $year, ?array $meta): bool
    {
        if (!$meta || empty($meta['last_checked_at'])) return true;
        if ($year < (int) now()->year && ($meta['state'] ?? null) === 'available') return false;

        $lastChecked = CarbonImmutable::parse($meta['last_checked_at']);
        $currentYear = (int) now()->year;
        $days = ($year === $currentYear + 1 && now()->month >= 9 && now()->month <= 12) ? 7 : 30;

        return $lastChecked->lte(now()->subDays($days));
    }

    private function expectedDays(int $year): int
    {
        return CarbonImmutable::create($year, 12, 31)->dayOfYear;
    }

    private function assertYear(int $year): void
    {
        if ($year < 2000 || $year > 2100) throw new RuntimeException('Invalid production calendar year');
    }
}
