<?php

namespace App\Services;

use App\Models\TimesheetEntry;
use App\Models\FinalApproval;
use App\Models\FinalApprovalException;
use Carbon\Carbon;

/**
 * Partner report slice. Partner identities never enter the Employees namespace,
 * and must never receive the staff utilization denominator implicitly.
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
        $byMember = [];
        foreach ($visible as $member) {
            $byMember[(int) $member['client_member_id']] = $member;
        }
        $ids = array_keys($byMember);
        if (!$ids) return ['rows' => [], 'totals' => [
            'specialists' => 0, 'commercial_hours' => 0, 'unconfirmed_hours' => 0,
            'conflicted_hours' => 0,
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
        $rows = [];
        foreach ($byMember as $id => $member) {
            $rows[] = [
                'identity_type' => 'partner',
                'partner_specialist_id' => (int) $member['partner_specialist_id'],
                'client_member_id' => $id,
                'client_id' => (int) $member['client_id'],
                'client_name' => $member['client_name'],
                'employee_name' => $member['specialist_name'],
                'department_name' => 'Партнёры',
                // Partner contractual capacity is not defined by Employees norm.
                'norm_hours' => null,
                'commercial_percent' => null,
                'idle_hours' => null,
                'commercial_hours' => (float) ($confirmedMap->get($id) ?? 0),
                'unconfirmed_hours' => (float) ($pendingMap->get($id) ?? 0),
                'conflicted_hours' => (float) ($conflictedMap->get($id) ?? 0),
                'absence_hours' => 0,
                'absences' => [],
            ];
        }
        return ['rows' => $rows, 'totals' => [
            'specialists' => count(array_unique(array_column($rows, 'partner_specialist_id'))),
            'commercial_hours' => array_sum(array_column($rows, 'commercial_hours')),
            'unconfirmed_hours' => array_sum(array_column($rows, 'unconfirmed_hours')),
            'conflicted_hours' => array_sum(array_column($rows, 'conflicted_hours')),
        ]];
    }
}
