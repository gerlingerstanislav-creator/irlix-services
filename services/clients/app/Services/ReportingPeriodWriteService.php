<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ReportingPeriod;
use App\Models\ReportingPeriodHistory;
use Illuminate\Support\Facades\DB;

final class ReportingPeriodWriteService
{
    private static function raw($model): ?object
    {
        return $model ? (object) $model->getRawOriginal() : null;
    }

    public function period(int $id): ?object
    {
        return self::raw(ReportingPeriod::query()->find($id));
    }

    public function assertNoOverlap(int $clientId, string $from, string $to, ?int $excludeId = null): void
    {
        $query = ReportingPeriod::query()->where('client_id', $clientId)
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from);
        if ($excludeId) $query->where('id', '<>', $excludeId);
        abort_if($query->exists(), 422,
            'Отчётный период пересекается с уже существующим периодом клиента.');
    }

    public function store(array $data, ?string $username): object
    {
        $this->assertNoOverlap((int) $data['client_id'],
            $data['period_start'], $data['period_end']);

        $client = self::raw(Client::query()->whereKey((int) $data['client_id'])->first());
        abort_unless($client, 422, 'Client not found');
        $id = ReportingPeriod::query()->insertGetId([
            ...$data,
            'overtime_multiplier' => $client->overtime_multiplier,
            'status' => 'Новый',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->recordEvent($id, $username, 'created', 'Отчётный период создан',
            $data['period_start'].' — '.$data['period_end']);
        return $this->period($id);
    }

    public function note(int $id, string $text, ?string $username): void
    {
        abort_unless(ReportingPeriod::query()->whereKey($id)->exists(), 404,
            'Reporting period not found');
        $this->recordEvent($id, $username, 'comment', 'Комментарий', trim($text));
    }

    public function lockStatus(int $clientId, string $from, ?string $to): bool
    {
        return ReportingPeriod::query()->where('client_id', $clientId)
            ->whereDate('period_start', '<=', $to ?? $from)
            ->whereDate('period_end', '>=', $from)
            ->where('status', '<>', 'Новый')->exists();
    }

    public function delete(int $id): void
    {
        DB::transaction(static function () use ($id): void {
            $clientId = ReportingPeriod::query()->whereKey($id)->value('client_id');
            if ($clientId) Client::query()->whereKey($clientId)->lockForUpdate()->first();
            $row = ReportingPeriod::query()->whereKey($id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Reporting period not found');
            abort_if($row->status !== 'Новый', 423,
                'Защищённый отчётный период удалять нельзя.');
            ReportingPeriod::query()->whereKey($id)->delete();
        });
    }

    public function overtimeMultiplier(int $id, array $data, ?string $username): array
    {
        return DB::transaction(function () use ($id, $data, $username): array {
            $clientId = ReportingPeriod::query()->whereKey($id)->value('client_id');
            if ($clientId) Client::query()->whereKey($clientId)->lockForUpdate()->first();

            $row = self::raw(ReportingPeriod::query()->whereKey($id)->lockForUpdate()->first());
            abort_unless($row, 404, 'Reporting period not found');
            abort_if($row->status === 'Счет оплачен', 423,
                'Коэффициент оплаченного периода нельзя менять.');
            if (array_key_exists('expected_multiplier', $data)) {
                abort_if(abs((float) $row->overtime_multiplier
                    - (float) $data['expected_multiplier']) > 0.0005,
                    409, 'Коэффициент уже изменён другим пользователем. Обновите период.');
            }
            $factor = round((float) $data['overtime_multiplier'], 3);
            if (abs((float) $row->overtime_multiplier - $factor) > 0.00001) {
                ReportingPeriod::query()->whereKey($id)->update([
                    'overtime_multiplier' => $factor, 'updated_at' => now(),
                ]);
                $this->recordEvent($id, $username, 'overtime_multiplier',
                    'Коэффициент переработок изменён',
                    (string) $row->overtime_multiplier.' → '.number_format($factor, 3, '.', ''));
            }
            return ['id' => $id, 'overtime_multiplier' => $factor];
        });
    }

    public function recordEvent(
        int $id, ?string $username, string $type, string $title, ?string $text = null
    ): void {
        ReportingPeriodHistory::query()->insert([
            'reporting_period_id' => $id, 'type' => $type, 'title' => $title,
            'text' => $text, 'author' => $username,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
