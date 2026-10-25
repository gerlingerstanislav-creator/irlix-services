<?php

namespace App\Services;

use App\Models\ClientAccountDelegation;
use App\Models\MemberTerm;
use App\Models\Project;
use App\Models\ReportingPeriod;

/** Read-only historical Timesheets v1 API; canonical v2 uses TimesheetMembershipDirectory. */
final class TimesheetAssignmentsService
{
    public function snapshot(): array
    {
        $delegations = ClientAccountDelegation::query()
            ->whereNull('revoked_at')->whereDate('starts_at', '<=', today())->whereDate('ends_at', '>=', today())
            ->get()->groupBy('client_id');
        // Compatibility query uses only Clients-owned tables; no commercial rates. No user scope or commercial rates.
        $rows = MemberTerm::query()->from('member_terms as terms')
            ->join('project_members as members', 'members.id', '=', 'terms.project_member_id')
            ->join('projects', 'projects.id', '=', 'members.project_id')
            ->join('clients', 'clients.id', '=', 'projects.client_id')
            ->whereNotNull('terms.valid_from')->whereNotNull('members.specialist_id')
            ->select([
                'members.specialist_id as employee_id', 'members.specialist_name as employee_name',
                'clients.id as client_id', 'clients.name as client_name',
                'projects.id as project_id', 'projects.name as project_name',
                'clients.account_employee_id', 'terms.valid_from', 'terms.valid_to',
            ])->orderBy('terms.id')->get()->map(fn ($row) => [
                ...$row->getRawOriginal(),
                'employee_id' => (int) $row->employee_id,
                'client_id' => (int) $row->client_id,
                'project_id' => (int) $row->project_id,
                'project_name' => $row->project_name ?: $row->client_name,
                'account_employee_id' => $row->account_employee_id === null ? null : (int) $row->account_employee_id,
                'delegated_account_employee_ids' => ($delegations->get($row->client_id) ?? collect())->pluck('to_employee_id')->map(fn ($id) => (int) $id)->values()->all(),
            ])->values()->all();

        // Historical ownership must survive the end of a member's terms. Do not derive it from active assignments.
        $projectClients = Project::query()->select(['id', 'client_id'])->orderBy('id')->get()
            ->map(fn ($project) => ['project_id' => (int) $project->id, 'client_id' => (int) $project->client_id])->all();

        $lockedPeriods = ReportingPeriod::query()->where('status', '<>', 'Новый')
            ->select(['id', 'client_id', 'period_start', 'period_end', 'status', 'timesheets_sent_at'])
            ->orderBy('id')->get()->map(fn ($period) => [
                ...$period->getRawOriginal(), 'id' => (int) $period->id, 'client_id' => (int) $period->client_id,
            ])->values()->all();
        return ['data' => $rows, 'complete' => true, 'count' => count($rows),
            'locked_reporting_periods' => $lockedPeriods, 'locked_reporting_periods_complete' => true,
            'locked_reporting_periods_count' => count($lockedPeriods),
            'project_clients' => $projectClients, 'project_clients_complete' => true,
            'project_clients_count' => count($projectClients)];
    }
}
