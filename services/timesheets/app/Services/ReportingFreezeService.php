<?php
namespace App\Services;

use App\Models\ReportingFreezeRecord;
use App\Support\CommercialData;
use App\Support\ReportingFreeze;
use Illuminate\Support\Facades\DB;

final class ReportingFreezeService
{
    public function __construct(
        private readonly CommercialData $commercial,
        private readonly CashFlowFactsReader $canonicalFacts,
    ) {}

    public function createLegacy(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            ReportingFreeze::lock();
            $existing = ReportingFreezeRecord::query()->where('reporting_period_id', $data['reporting_period_id'])
                ->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->client_id === (int) $data['client_id']
                    && $existing->period_start === $data['from'] && $existing->period_end === $data['to'],
                    409, 'Reporting freeze identity mismatch');
                return ['freeze_token' => $existing->freeze_token, 'revision' => $existing->revision];
            }
            $snapshot = $this->commercial->read($data['from'], $data['to'], (int) $data['client_id']);
            $token = bin2hex(random_bytes(32));
            ReportingFreezeRecord::query()->create([
                'reporting_period_id' => $data['reporting_period_id'], 'client_id' => $data['client_id'],
                'period_start' => $data['from'], 'period_end' => $data['to'],
                'freeze_token' => $token, 'revision' => $snapshot['revision'],
            ]);
            return ['freeze_token' => $token, 'revision' => $snapshot['revision']];
        });
    }

    public function createCanonical(array $data): array
    {
        $ids = array_values(array_unique($data['client_member_ids']));
        return DB::transaction(function () use ($data, $ids): array {
            ReportingFreeze::lock();
            $snapshot = $this->canonicalFacts->read($data['from'], $data['to'], $ids);
            $existing = ReportingFreezeRecord::query()->where('reporting_period_id', $data['reporting_period_id'])
                ->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->client_id === $data['client_id']
                    && $existing->period_start === $data['from'] && $existing->period_end === $data['to']
                    && hash_equals((string) $existing->revision, $snapshot['revision']), 409,
                    'Reporting freeze identity or revision mismatch');
                return [
                    'freeze_token' => $existing->freeze_token, 'revision' => $existing->revision,
                    'membership_revision' => $data['membership_revision'],
                ];
            }
            $token = bin2hex(random_bytes(32));
            ReportingFreezeRecord::query()->create([
                'reporting_period_id' => $data['reporting_period_id'], 'client_id' => $data['client_id'],
                'period_start' => $data['from'], 'period_end' => $data['to'],
                'freeze_token' => $token, 'revision' => $snapshot['revision'],
            ]);
            return [
                'freeze_token' => $token, 'revision' => $snapshot['revision'],
                'membership_revision' => $data['membership_revision'],
            ];
        });
    }

    public function release(int $period, string $token): void
    {
        DB::transaction(static function () use ($period, $token): void {
            ReportingFreeze::lock();
            $existing = ReportingFreezeRecord::query()->where('reporting_period_id', $period)
                ->lockForUpdate()->first();
            if (!$existing) return;
            abort_unless(hash_equals((string) $existing->freeze_token, $token),
                409, 'Reporting freeze token mismatch');
            $existing->delete();
        });
    }
}
