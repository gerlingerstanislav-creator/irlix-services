<?php

namespace App\Migration\Services;

use App\Migration\Core\SchemaMigration;
use Illuminate\Support\Facades\DB;

class TimesheetsMigration extends SchemaMigration
{
    private array $pageHashes = [];
    private int $pagePass = 0;
    private bool $auditPages = false;
    private bool $requireBaseline = false;

    public function key(): string { return 'timesheets'; }

    protected function resetSourceScan(): void
    {
        $this->pageHashes = [];
        $this->pagePass = 0;
        $this->auditPages = false;
    }

    protected function extract(): array
    {
        $this->resetSourceScan();
        return parent::extract();
    }

    protected function timesheetsBatchSize(): int
    {
        return max(100, min(2000, (int) config('migration.timesheets_batch_size', 1000)));
    }

    // Only the large fact table is streamed. The small dependency tables
    // remain loaded until they have been validated and mapped.
    protected function deferredSourceTables(): array { return ['timesheets']; }

    public function migrate(int $runId, bool $dryRun): array
    {
        $this->requireBaseline = !$dryRun && \App\Migration\Core\ControlMode::$enabled;
        return parent::migrate($runId, $dryRun);
    }

    public function validate(int $runId): array
    {
        $this->runId = $runId;
        $this->requireBaseline = \App\Migration\Core\ControlMode::$enabled;
        return parent::validate($runId);
    }



    protected function sourceBatches(string $table): iterable
    {
        if ($table !== 'timesheets' || !in_array('timesheets', $this->deferredSourceTables(), true)) {
            yield from parent::sourceBatches($table);
            return;
        }

        $reader = new \App\Migration\Core\LegacyReader($this->key());
        $highWater = $this->deferredSourceWatermarks[$table] ?? null;
        if ($highWater === null) return;
        $last = null;
        $seen = 0;
        $batchNumber = 0;
        $pass = ++$this->pagePass;
        $baseline = null;
        if ($pass === 1 && $this->requireBaseline) {
            $baseline = $this->store->completedControlDryRunHashes($this->runId, $this->key(), $table);
            if ($baseline === null) {
                throw new \RuntimeException('Timesheets import requires a completed dry-run within the same control operation.');
            }
        }
        $size = $this->timesheetsBatchSize();
        do {
            $page = $this->readTimesheetsPage($reader, $highWater, $last, $size);
            if (!$page) break;
            $batch = array_map(fn ($row) => (array) $row, $page);
            $next = $batch[array_key_last($batch)]['id'] ?? null;
            if ($next === null || ($last !== null && $next <= $last)) {
                throw new \RuntimeException('Source Timesheets page cursor did not advance.');
            }
            $seen += count($batch);
            $last = $next;
            ++$batchNumber;
            $signature = hash('sha256', json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if ($pass === 1) {
                if ($baseline !== null && ($baseline[$batchNumber] ?? null) !== $signature) {
                    throw new \RuntimeException('Legacy Timesheets differ from the completed dry-run; target writes blocked.');
                }
                $this->pageHashes[$batchNumber] = $signature;
            } elseif (($this->pageHashes[$batchNumber] ?? null) !== $signature) {
                throw new \RuntimeException('Source Timesheets changed between preparation and processing; import blocked.');
            }
            yield $batch;
            if ($this->auditPages) {
                $this->store->batchProcessed($this->runId, $this->key(), 'timesheets', $batchNumber, $batch);
            }
        } while (count($batch) === $size);

        if ($baseline !== null && count($baseline) !== $batchNumber) {
            throw new \RuntimeException('Legacy Timesheets batch inventory differs from completed dry-run.');
        }
        if ($pass > 1 && count($this->pageHashes) !== $batchNumber) {
            throw new \RuntimeException('Source Timesheets page count changed during the operation; import blocked.');
        }
        if ($seen !== ($this->sourceTotals[$table] ?? $seen)) {
            throw new \RuntimeException('Source Timesheets changed during paged read; import blocked.');
        }
    }

    protected function readTimesheetsPage(\App\Migration\Core\LegacyReader $reader, mixed $highWater, mixed $last, int $size): array
    {
        $reader->assertSafe();
        $sql = 'SELECT * FROM public.timesheets WHERE id <= ?';
        $bindings = [$highWater];
        if ($last !== null) {
            $sql .= ' AND id > ?';
            $bindings[] = $last;
        }
        $sql .= ' ORDER BY id LIMIT '.$size;
        return $reader->select($sql, $bindings, false);
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
        $this->auditPages = !$this->isPreflight();
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
        $this->auditPages = false;
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
