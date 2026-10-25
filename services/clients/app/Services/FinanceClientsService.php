<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportingPeriodHistory;
use App\Models\MemberTerm;
use App\Support\MemberTermHistory;
use Illuminate\Support\Facades\DB;

final class FinanceClientsService
{
    public function projectsDirectory(): array
    {
        return [
            'clients' => Client::query()->select('id', 'name')->orderBy('name')
                ->limit(5000)->get()->map(fn ($x) => (object) $x->getRawOriginal()),
            'projects' => Project::query()->select('id', 'client_id', 'name')->orderBy('name')
                ->limit(10000)->get()->map(fn ($x) => (object) $x->getRawOriginal()),
        ];
    }

    public function directCostEntity(int $clientId, ?int $projectId): array
    {
        $client = Client::query()->whereKey($clientId)->first(['id', 'name']);
        abort_unless($client, 422, 'Client does not exist');
        $project = $projectId ? Project::query()->whereKey($projectId)
            ->where('client_id', $clientId)->first(['id', 'name']) : null;
        if ($projectId) abort_unless($project, 422, 'Project does not belong to client');
        return [
            'client_id' => (int) $client->id, 'client_name' => (string) $client->name,
            'project_id' => $project ? (int) $project->id : null,
            'project_name' => $project ? (string) $project->name : null,
        ];
    }

    public function approvedPeriods(): array
    {
        return ReportingPeriod::query()->from('reporting_periods as rp')
            ->join('clients as c', 'c.id', '=', 'rp.client_id')
            ->select('rp.id', 'rp.client_id', 'rp.period_start', 'rp.period_end', 'rp.status',
                'c.name as client_name')
            ->whereIn('rp.status', ['Акт согласован', 'Счет оплачен'])
            ->orderByDesc('rp.period_end')->limit(500)->get()
            ->map(static fn ($x) => (object) $x->getRawOriginal())->all();
    }

    public function commercialTerms(string $from, string $to): array
    {
        // Preserve a single consistent, revisioned Finance view. PostgreSQL
        // REPEATABLE READ is scoped to this transaction as in the legacy API.
        return DB::transaction(static function () use ($from, $to): array {
            if (DB::getDriverName() === 'pgsql') DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            $periods = ReportingPeriod::query()
                ->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from)
                ->get(['id', 'client_id', 'period_start', 'period_end', 'status',
                    'overtime_multiplier', 'timesheet_snapshot', 'timesheet_revision'])
                ->map(static fn ($x) => (object) $x->getRawOriginal());

            $allocationFrom = min($from, $periods->min('period_start') ?? $from);
            $allocationTo = max($to, $periods->max('period_end') ?? $to);

            $terms = MemberTerm::query()->from('member_terms as mt')
                ->join('client_members as cm', 'cm.id', '=', 'mt.client_member_id')
                ->join('clients as c', 'c.id', '=', 'cm.client_id')
                ->whereDate('mt.valid_from', '<=', $allocationTo)
                ->where(static fn ($q) => $q->whereNull('mt.valid_to')->orWhereDate('mt.valid_to', '>=', $allocationFrom))
                ->select('mt.id as term_id', 'mt.hourly_rate', 'mt.valid_from', 'mt.valid_to',
                    'cm.employee_id', 'cm.partner_specialist_id', 'cm.specialist_name',
                    'cm.id as client_member_id', 'mt.project_member_id',
                    'cm.client_id', 'c.name as client_name')
                ->orderBy('mt.id')->get()
                ->map(static fn ($x) => (object) ($x->getRawOriginal() + ['project_id' => null, 'project_name' => null]));

            $overrides = MemberTermHistory::approvedHours($periods->pluck('id')->all());
            return [
                'complete' => true, 'count' => $terms->count(),
                'terms' => $terms, 'periods' => $periods, 'approved_hours_overrides' => $overrides,
                'allocation_from' => $allocationFrom, 'allocation_to' => $allocationTo,
                'revision' => hash('sha256', json_encode([$terms, $periods, $overrides], JSON_THROW_ON_ERROR)),
            ];
        });
    }

    public function period(int $id): array
    {
        $row = ReportingPeriod::query()->whereKey($id)->first();
        abort_unless($row, 404, 'Reporting period not found');
        return [
            'id' => (int) $row->id, 'client_id' => (int) $row->client_id,
            'status' => $row->status,
            'period_start' => substr((string) $row->period_start, 0, 10),
            'period_end' => substr((string) $row->period_end, 0, 10),
            'updated_at' => (string) $row->getRawOriginal('updated_at'),
        ];
    }

    public function paid(int $period, array $data): array
    {
        return DB::transaction(static function () use ($period, $data): array {
            $clientId = ReportingPeriod::query()->whereKey($period)->value('client_id');
            if ($clientId) Client::query()->whereKey($clientId)->lockForUpdate()->first();

            $row = ReportingPeriod::query()->whereKey($period)->lockForUpdate()->first();
            abort_unless($row, 404, 'Reporting period not found');
            if ($row->status === 'Счет оплачен') {
                return ['id' => $period, 'status' => 'Счет оплачен', 'already_paid' => true];
            }
            abort_unless($row->status === 'Акт согласован', 409, 'Перед оплатой нужно согласовать акт');
            ReportingPeriod::query()->whereKey($period)->update([
                'status' => 'Счет оплачен', 'paid_at' => now()->toDateString(), 'updated_at' => now(),
            ]);
            ReportingPeriodHistory::query()->insert([
                'reporting_period_id' => $period, 'type' => 'payment',
                'title' => 'Все счета оплачены через Finance',
                'text' => sprintf('Документы: %d, ledger v%d, операция %s',
                    $data['invoice_count'], $data['ledger_version'], $data['evidence_id']),
                'author' => 'Finance', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => $period, 'status' => 'Счет оплачен', 'already_paid' => false];
        });
    }
}
