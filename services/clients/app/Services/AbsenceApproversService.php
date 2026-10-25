<?php

namespace App\Services;

use App\Models\ClientMember;

final class AbsenceApproversService
{
    /**
     * AM approvals follow canonical client memberships and dated commercial
     * terms. Project assignments are optional and must not limit vacation
     * approvals. Re-joining via project_members silently skips new members.
     */
    public function forEmployee(int $employeeId, string $from, string $to): array
    {
        $rows = ClientMember::query()
            ->join('member_terms as terms', 'terms.client_member_id', '=', 'client_members.id')
            ->join('clients as client', 'client.id', '=', 'client_members.client_id')
            ->where('client_members.employee_id', $employeeId)
            ->whereDate('terms.valid_from', '<=', $to)
            ->where(static function ($query) use ($from): void {
                $query->whereNull('terms.valid_to')->orWhereDate('terms.valid_to', '>=', $from);
            })
            ->select([
                'client_members.id as client_member_id',
                'client.account_employee_id as account_employee_id',
            ])
            ->distinct()
            ->get();

        return [
            'account_manager_ids' => $rows->pluck('account_employee_id')
                ->filter(static fn ($id): bool => (int) $id > 0)
                ->map(static fn ($id): int => (int) $id)
                ->unique()->values()->all(),
            'connection_count' => $rows->pluck('client_member_id')->unique()->count(),
        ];
    }
}
