<?php

namespace App\Migration\Services;

use App\Migration\Core\SchemaMigration;
use Illuminate\Support\Facades\DB;

class TimesheetsMigration extends SchemaMigration
{
    public function key(): string { return 'timesheets'; }

    protected function import(): void
    {
        $uuidUsers = [];
        $this->rows('users', function ($r, $id) use (&$uuidUsers) {
            $employee = $this->employee($r);
            $this->map('users', $id, $employee, $r);
            if (!empty($r['employee_id'])) $uuidUsers[$r['employee_id']][] = $employee;
        });
        $members = [];
        $this->rows('members', function ($r, $id) use ($uuidUsers, &$members) {
            if ($r['memberable_type'] !== 'employee') throw new \DomainException('External member identity requires explicit reconciliation');
            $employees = array_unique($uuidUsers[$r['employee_id']] ?? []);
            if (count($employees) !== 1) throw new \DomainException('Timesheets member employee UUID must resolve through a unique corporate login');
            $employee = $employees[0];
            // The source schema does not prove shared Clients/Timesheets numeric IDs.
            // Prefer an explicit override; otherwise require one exact employee/project match.
            $clientMemberId = $this->store->override('timesheets', 'members', $id);
            if ($clientMemberId === null) {
                $projectName = mb_strtolower(trim($r['project']));
                $this->need($projectName, 'Legacy project descriptor is empty; explicit member override required');
                $matches = DB::connection('target_clients')->table('project_members as pm')
                    ->join('projects as p', 'p.id', '=', 'pm.project_id')->join('clients as c', 'c.id', '=', 'p.client_id')
                    ->where('pm.specialist_id', $employee)
                    ->where(fn ($q) => $q->whereRaw('LOWER(TRIM(p.name)) = ?', [$projectName])
                        ->orWhere(fn ($q) => $q->where('p.is_default', true)->whereRaw('LOWER(TRIM(c.name)) = ?', [$projectName])))
                    ->pluck('pm.id');
                if ($matches->count() !== 1) throw new \DomainException('Employee/project must resolve to one Clients connection; explicit timesheets.members override required');
                $clientMemberId = (int) $matches->first();
            }
            $member = DB::connection('target_clients')->table('project_members')->find($clientMemberId);
            if (!$member || (int) $member->specialist_id !== $employee) throw new \DomainException('Clients connection disagrees with Timesheets employee');
            $project = DB::connection('target_clients')->table('projects')->find($member->project_id);
            $client = $project ? DB::connection('target_clients')->table('clients')->find($project->client_id) : null;
            $this->need($client, 'Mapped client/project does not exist');
            $members[$id] = ['employee_id' => $employee, 'project_id' => (int) $project->id,
                'client_id' => (int) $client->id, 'account_employee_id' => $client->account_employee_id];
            $this->map('members', $id, $clientMemberId, $r);
        });
        $rates = [];
        $this->rows('rates', function ($r, $id) use ($members, &$rates) {
            $connection = $this->need($members[(string) $r['member_id']] ?? null, 'Rate member is unresolved');
            [$from, $to] = $this->dates($r['from'], $r['to']);
            $workload = $this->number($r['workload'], 24);
            $memberId = $this->ref('members', $r['member_id']);
            $terms = DB::connection('target_clients')->table('member_terms')->where('project_member_id', $memberId)
                ->whereDate('valid_from', $from)->where(fn ($q) => $to === null ? $q->whereNull('valid_to') : $q->whereDate('valid_to', $to))
                ->where('hours_per_day', $workload)->pluck('id');
            if ($terms->count() !== 1) throw new \DomainException('Timesheets rate must match exactly one imported Clients term by member, dates and daily hours');
            $rates[$id] = $connection + ['from' => $from, 'to' => $to];
            $this->map('rates', $id, (int) $terms->first(), $r);
        });
        $daily = [];
        $ownEntries = DB::table('migration_mappings')->where('service', 'timesheets')->where('entity_type', 'timesheets')
            ->whereIn('legacy_id', array_column($this->source['timesheets'], 'id'))->pluck('target_id');
        $this->rows('timesheets', function ($r, $id) use ($rates, &$daily, $ownEntries) {
            $rate = $this->need($rates[(string) $r['rate_id']] ?? null, 'Timesheet rate is unresolved');
            $this->dates($r['date'], $r['date']);
            if ($r['date'] < $rate['from'] || ($rate['to'] !== null && $r['date'] > $rate['to'])) throw new \DomainException('Timesheet date is outside its connection period');
            $hours = $this->number($r['hours'], 24);
            if (abs($hours - round($hours, 2)) > 0.000001) throw new \DomainException('Hours exceed target precision; refusing silent rounding');
            $dayKey = $rate['employee_id'].':'.$r['date'];
            $other = (float) DB::connection('target_timesheets')->table('timesheet_entries')
                ->where('employee_id', $rate['employee_id'])->whereDate('work_date', $r['date'])->whereNotIn('id', $ownEntries)->sum('hours');
            if ($other + ($daily[$dayKey] ?? 0) + $hours > 24.000001) throw new \DomainException('Total employee hours exceed 24 for the day');
            $payload = array_intersect_key($rate, array_flip(['employee_id','client_id','project_id','account_employee_id']))
                + ['work_date' => $r['date'], 'hours' => $hours, 'description' => $r['description']];
            $entryId = $this->write('timesheets', $id, 'timesheet_entries', $payload, $r,
                array_intersect_key($payload, array_flip(['employee_id','project_id','work_date'])));
            $ownEntries->push($entryId);
            $daily[$dayKey] = ($daily[$dayKey] ?? 0) + $hours;
            if ($this->boolean($r['is_submitted'])) $this->preserve('timesheets', $r, $id,
                'Per-entry is_submitted preserved in metadata: the new confirmation covers every project of the employee day, so partial submission cannot be converted safely.');
        });
        foreach (['month_confirmations', 'time_records', 'vacations', 'business_dates', 'departments'] as $table) {
            $this->rows($table, fn ($r, $id) => $this->preserve($table, $r, $id,
                'Legacy confirmations lack the final actor/action timestamp; personal time records lack client/project. Originals preserved without invented approvals or commercial hours.'));
        }
    }
}
