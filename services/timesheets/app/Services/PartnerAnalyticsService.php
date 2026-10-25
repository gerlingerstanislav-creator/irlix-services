<?php

namespace App\Services;

use App\Models\TimesheetEntry;
use App\Models\FinalApproval;
use App\Models\FinalApprovalException;

/**
 * Partner report slice. Partner identities never enter the Employees namespace,
 * and contribute actual final-confirmed commercial hours as their norm.
 * ClientMember rows across several clients are aggregated once per person,
 * without inventing an eight-hour working-day obligation. Access scope is
 * applied before aggregation.
 */
final class PartnerAnalyticsService
{
    public function __construct(private readonly CommercialAnalyticsClassifier $classifier) {}

    public function summary(
        array $members,
        array $locks,
        array $current,
        array $access,
        string $from,
        string $to,
        ?int $clientFilter = null,
        bool $includePartners = true,
    ): array {
        $actorId = (int) $current['id'];
        $scope = (string) ($access['scope'] ?? 'none');
        $isGlobal = ($access['platformAdmin'] ?? false) || $scope === 'all';
        $visible = array_values(array_filter($members, static function (array $member) use ($actorId, $scope, $isGlobal, $from, $to): bool {
            if (($member['partner_specialist_id'] ?? null) === null) return false;
            if (($member['valid_from'] ?? null) === null || $member['valid_from'] > $to
                || (!empty($member['valid_to']) && $member['valid_to'] < $from)) return false;
            if ($isGlobal) return true;
            if (!in_array($scope, ['own', 'team'], true)) return false;
            return (int) ($member['account_employee_id'] ?? 0) === $actorId
                || in_array($actorId, $member['delegated_account_employee_ids'] ?? [], true);
        }));
        // Options are permission-scoped but not narrowed by active filters.
        // This permits switching client/type filters without losing choices.
        $clientOptions = [];
        foreach ($visible as $member) {
            $clientOptions[(int) $member['client_id']] = [
                'id' => (int) $member['client_id'],
                'name' => (string) $member['client_name'],
            ];
        }
        if ($clientFilter !== null) {
            $visible = array_values(array_filter($visible,
                static fn (array $member) => (int) $member['client_id'] === $clientFilter));
        }
        if (!$includePartners) $visible = [];
        $byMember = [];
        foreach ($visible as $member) {
            $byMember[(int) $member['client_member_id']] = $member;
        }
        $ids = array_keys($byMember);
        if (!$ids) return ['rows' => [], 'client_options' => array_values($clientOptions), 'totals' => [
            'specialists' => 0, 'norm_hours' => 0, 'commercial_hours' => 0,
            'unconfirmed_hours' => 0, 'conflicted_hours' => 0, 'idle_hours' => 0,
        ]];

        $facts = TimesheetEntry::query()->whereIn('client_member_id', $ids)
            ->whereNull('employee_id')->whereBetween('work_date', [$from, $to])->get();
        $approvals = FinalApproval::query()->whereIn('client_member_id', $ids)
            ->whereNull('employee_id')->where('month', $from)->get();
        $exceptions = FinalApprovalException::query()->whereIn('client_member_id', $ids)
            ->whereBetween('work_date', [$from, $to])->get();

        [$confirmed, $pending, $conflicted] = $this->classifier->classify(
            $facts, $approvals, $exceptions, $locks, $visible
        );
        $confirmedMap = $confirmed->groupBy('client_member_id')->map->sum('hours');
        $pendingMap = $pending->groupBy('client_member_id')->map->sum('hours');
        $conflictedMap = $conflicted->groupBy('client_member_id')->map->sum('hours');
        // One report row and one denominator per visible partner identity,
        // even when the same person has multiple ClientMember connections.
        $people = [];
        foreach ($byMember as $id => $member) {
            $personId = (int) $member['partner_specialist_id'];
            if (!isset($people[$personId])) {
                $people[$personId] = [
                    'identity_type' => 'partner',
                    'partner_specialist_id' => $personId,
                    'employee_id' => null,
                    'employee_name' => $member['specialist_name'],
                    'department_id' => 'partners',
                    'department_name' => 'Партнёры',
                    'client_names' => [],
                    'client_member_ids' => [],
                    'norm_hours' => 0.0,
                    'commercial_hours' => 0.0,
                    'unconfirmed_hours' => 0.0,
                    'conflicted_hours' => 0.0,
                    'absence_hours' => 0,
                    'absences' => [],
                ];
            }
            $people[$personId]['client_names'][(int) $member['client_id']] = $member['client_name'];
            $people[$personId]['client_member_ids'][] = $id;
            $people[$personId]['commercial_hours'] += (float) ($confirmedMap->get($id) ?? 0);
            $people[$personId]['unconfirmed_hours'] += (float) ($pendingMap->get($id) ?? 0);
            $people[$personId]['conflicted_hours'] += (float) ($conflictedMap->get($id) ?? 0);
        }

        $rows = [];
        foreach ($people as $person) {
            $person['client_name'] = implode(', ', array_unique(array_values($person['client_names'])));
            unset($person['client_names']);
            // Partners are paid only for final-confirmed worked hours:
            // commercial norm == paid commercial hours, no capacity idle.
            // 0/0 displays 100% for a present partner by this business rule.
            $person['norm_hours'] = $person['commercial_hours'];
            $person['commercial_percent'] = 100;
            $person['idle_hours'] = 0.0;
            $rows[] = $person;
        }

        return ['rows' => $rows, 'client_options' => array_values($clientOptions), 'totals' => [
            'specialists' => count($rows),
            'norm_hours' => array_sum(array_column($rows, 'norm_hours')),
            'commercial_hours' => array_sum(array_column($rows, 'commercial_hours')),
            'unconfirmed_hours' => array_sum(array_column($rows, 'unconfirmed_hours')),
            'conflicted_hours' => array_sum(array_column($rows, 'conflicted_hours')),
            'idle_hours' => array_sum(array_column($rows, 'idle_hours')),
        ]];
    }
}
