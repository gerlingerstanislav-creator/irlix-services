<?php

namespace App\Migration\Services;

use App\Migration\Core\SchemaMigration;
use Illuminate\Support\Facades\DB;

class TimesheetsMigration extends SchemaMigration
{
    public function key(): string { return 'timesheets'; }
    /** Primary hours table uses bounded keyset pages. Dimension tables remain small. */
    protected function streamedTables(): array { return ['timesheets']; }
    protected function retainMappedId(string $entity): bool { return $entity !== 'timesheets'; }
    protected function persistentNaturalKeys(string $entity): bool { return $entity === 'timesheets'; }
    private ?\App\Migration\Core\SourceStage $sourceStage = null;

    protected function prepareInput(): void
    {
        if (!$this->isStreamedSource('timesheets')) return;
        $this->sourceStage = new \App\Migration\Core\SourceStage($this->key(), 'timesheets', $this->runId);
        $this->store->progress($this->runId, 'source-snapshot',
            'Сохраняем согласованный read-only снимок исходных часов пакетами по 1 000.');
        $this->sourceStage->prepare();
    }

    protected function sourcePages(string $table): \Generator
    {
        if ($table === 'timesheets' && $this->sourceStage !== null) {
            yield from $this->sourceStage->pages();
            return;
        }
        yield from parent::sourcePages($table);
    }


    /** Stream reconciliation rather than loading all source hours back into PHP. */
    public function validate(int $runId): array
    {
        $this->runId = $runId;
        $this->source = $this->extract();
        $this->prepareInput();
        $totals = ['source'=>0,'mapped'=>0,'target_present'=>0,'changed'=>0,'aliases'=>0];
        $ok = true;
        foreach ($this->sourcePages('timesheets') as $page) {
            // Metadata SQLite may have a 999-variable bind limit. Validation
            // operates on sub-pages so existing target/mapping verification stays bounded.
            foreach (array_chunk($page, 400) as $segment) {
                $report = $this->validateRows(['timesheets'=>$segment]);
                $part = $report['tables']['timesheets'] ?? [];
                foreach ($totals as $name=>&$value) $value += (int) ($part[$name] ?? 0);
                unset($value);
                if (!($report['ok'] ?? false)) $ok = false;
            }
        }
        $ok = $ok && $totals['mapped'] === $totals['source']
            && $totals['target_present'] === $totals['mapped'] && $totals['changed'] === 0;
        return ['ok'=>$ok, 'tables'=>['timesheets'=>$totals]];
    }


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
            if (\App\Migration\Core\ControlMode::$enabled) $this->ref('members',$r['member_id']);
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
        $this->store->progress($this->runId, 'timesheets-preflight', 'Сверяем существующие часы по сотрудникам и дням.');
        $externalHours = $this->externalDailyHours($rates);
        $processed = 0;
        $this->rows('timesheets', function ($r, $id) use ($rates, &$daily, $externalHours, &$processed) {
            if (++$processed % 500 === 0) {
                $this->store->progress($this->runId, 'timesheets-rows', 'Проверено исходных записей часов: '.$processed.'.');
            }
            if (\App\Migration\Core\ControlMode::$enabled) $this->ref('rates',$r['rate_id']);
            $rate = $this->need($rates[(string) $r['rate_id']] ?? null, 'Timesheet rate is unresolved');
            $this->dates($r['date'], $r['date']);
            if ($r['date'] < $rate['from'] || ($rate['to'] !== null && $r['date'] > $rate['to'])) throw new \DomainException('Timesheet date is outside its connection period');
            $hours = $this->number($r['hours'], 24);
            if (abs($hours - round($hours, 2)) > 0.000001) throw new \DomainException('Hours exceed target precision; refusing silent rounding');
            $dayKey = $rate['employee_id'].':'.$r['date'];
            if (($externalHours[$dayKey] ?? 0) + ($daily[$dayKey] ?? 0) + $hours > 24.000001) throw new \DomainException('Total employee hours exceed 24 for the day');
            $payload = array_intersect_key($rate, array_flip(['employee_id','client_id','project_id','account_employee_id']))
                + ['work_date' => $r['date'], 'hours' => $hours, 'description' => $r['description']];
            $this->write('timesheets', $id, 'timesheet_entries', $payload, $r,
                array_intersect_key($payload, array_flip(['employee_id','project_id','work_date'])));
            $daily[$dayKey] = ($daily[$dayKey] ?? 0) + $hours;
            if ($this->boolean($r['is_submitted'])) $this->preserve('timesheets', $r, $id,
                'Per-entry is_submitted preserved in metadata: the new confirmation covers every project of the employee day, so partial submission cannot be converted safely.');
        });
        foreach (['month_confirmations', 'time_records', 'vacations', 'business_dates', 'departments'] as $table) {
            $this->rows($table, fn ($r, $id) => $this->preserve($table, $r, $id,
                'Legacy confirmations lack the final actor/action timestamp; personal time records lack client/project. Originals preserved without invented approvals or commercial hours.'));
        }
    }

    /**
     * Read existing hours once per source day, rather than building an ever-growing
     * WHERE NOT IN list for every timesheet row (quadratic work at import scale).
     * Only rows mapped from this exact source inventory may be excluded.
     */
    private function externalDailyHours(array $rates): array
    {
        $sourceIds = [];
        $sourceDays = [];
        $employees = [];
        $dates = [];
        foreach ($this->sourceRows('timesheets') as $row) {
            if (isset($row['id'])) $sourceIds[(string) $row['id']] = true;
            $rate = $rates[(string) ($row['rate_id'] ?? '')] ?? null;
            $date = $row['date'] ?? null;
            if (!$rate || !is_string($date) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $date)) continue;
            $dayKey = $rate['employee_id'].':'.$date;
            $sourceDays[$dayKey] = true;
            $employees[(int) $rate['employee_id']] = true;
            $dates[$date] = true;
        }
        if (!$sourceDays) return [];

        $ownedIds = [];
        foreach (DB::table('migration_mappings')->where('service', 'timesheets')
            ->where('entity_type', 'timesheets')->select(['legacy_id', 'target_id'])->cursor() as $mapping) {
            if (isset($sourceIds[(string) $mapping->legacy_id])) $ownedIds[(string) $mapping->target_id] = true;
        }

        $hours = [];
        $first = min(array_keys($dates));
        $last = max(array_keys($dates));
        foreach (array_chunk(array_keys($employees), 200) as $employeeChunk) {
            $entries = DB::connection('target_timesheets')->table('timesheet_entries')
                ->whereIn('employee_id', $employeeChunk)
                ->whereBetween('work_date', [$first, $last])
                ->select(['id', 'employee_id', 'work_date', 'hours'])->cursor();
            foreach ($entries as $entry) {
                $dayKey = $entry->employee_id.':'.substr((string) $entry->work_date, 0, 10);
                if (!isset($sourceDays[$dayKey]) || isset($ownedIds[(string) $entry->id])) continue;
                $hours[$dayKey] = ($hours[$dayKey] ?? 0) + (float) $entry->hours;
            }
        }
        return $hours;
    }
}
