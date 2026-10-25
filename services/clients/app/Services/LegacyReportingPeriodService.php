<?php

namespace App\Services;

use App\Models\ReportingPeriod;

final class LegacyReportingPeriodService
{
    /** Compatibility for old controller actions; current public routes use ReportingPeriodsController. */
    public function period(int $id): ?object
    {
        $row = ReportingPeriod::query()->find($id);
        return $row ? (object) $row->getRawOriginal() : null;
    }

    public function create(array $data): object
    {
        $id = ReportingPeriod::query()->insertGetId([
            ...$data, 'status' => $data['status'] ?? 'Новый',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->period($id);
    }

    public function update(int $id, array $data): object
    {
        abort_unless($this->period($id), 404, 'Reporting period not found');
        ReportingPeriod::query()->whereKey($id)->update([...$data, 'updated_at' => now()]);
        return $this->period($id);
    }

    public function assertTermsNotPaid(int $clientId, string $from, ?string $to): void
    {
        $locked = ReportingPeriod::query()->where('client_id', $clientId)
            ->where('status', 'Счет оплачен')
            ->whereDate('period_start', '<=', $to ?? '9999-12-31')
            ->whereDate('period_end', '>=', $from)->exists();
        abort_if($locked, 422, 'Условия нельзя изменить: период уже попал в закрытый отчётный период.');
    }
}
