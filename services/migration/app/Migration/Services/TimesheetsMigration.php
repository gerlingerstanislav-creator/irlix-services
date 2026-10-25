<?php

namespace App\Migration\Services;

use App\Migration\Core\SchemaMigration;
use App\Migration\Core\TimesheetsResolution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TimesheetsMigration extends SchemaMigration
{
    private array $pageHashes = [];
    private ?string $dependencyPlanHash = null;
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

    protected function reloadSourceForWrite(): array
    {
        // A fresh reference inventory must not discard the completed fact preflight.
        $hashes = $this->pageHashes; $pass = $this->pagePass;
        $rows = $this->extract();
        $this->pageHashes = $hashes; $this->pagePass = $pass;
        return $rows;
    }

    protected function timesheetsBatchSize(): int
    {
        return max(100, min(2000, (int) config('migration.timesheets_batch_size', 1000)));
    }

    /**
     * Aggregation keeps every distinct ClientMember/day and its source rows in
     * PHP memory until writes start. Paged SQL reads alone do NOT bound memory.
     * Abort with an actionable diagnosis before PHP or the container is OOM-killed.
     * The absolute cap may be tuned per deployment; never disable the PHP cap.
     */
    /**
     * Sort a spool partition on disk, then yield exactly one target
     * ClientMember/day group at a time. No bucket-wide PHP group map.
     */
    private function sortedBucketGroups(string $path, string $spoolDir): \Generator
    {
        $dbPath = $spoolDir.'/sort-'.bin2hex(random_bytes(8)).'.sqlite';
        $db = null;
        try {
            $db = new \PDO('sqlite:'.$dbPath);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA journal_mode=DELETE');
            $db->exec('PRAGMA temp_store=FILE');
            $db->exec('CREATE TABLE records (group_key TEXT NOT NULL, source_id INTEGER NOT NULL, payload TEXT NOT NULL)');
            $insert = $db->prepare('INSERT INTO records (group_key, source_id, payload) VALUES (?, ?, ?)');
            $input = new \SplFileObject($path, 'r');
            $count = 0;
            $db->beginTransaction();
            while (!$input->eof()) {
                $line = $input->fgets();
                if (trim($line) === '') continue;
                $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $insert->execute([$item['key'], (int)$item['id'], $line]);
                if (++$count % 1000 === 0) {
                    $db->commit();
                    $db->beginTransaction();
                    $this->assertTimesheetsMemoryBudget($count, 1);
                }
            }
            $db->commit();
            unset($input, $insert);
            $db->exec('CREATE INDEX records_order ON records(group_key, source_id)');
            $query = $db->query('SELECT payload FROM records ORDER BY group_key, source_id');
            $current = null;
            $group = null;
            while (($line = $query->fetchColumn()) !== false) {
                $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($current !== null && $current !== $item['key']) {
                    yield $group;
                    $group = null;
                }
                if ($group === null) {
                    $current = $item['key'];
                    $group = ['member'=>$item['member'], 'date'=>$item['date'], 'rows'=>[]];
                }
                $group['rows'][] = ['source'=>$item['source'], 'id'=>$item['id'],
                    'hours'=>$item['hours'], 'term_id'=>$item['term_id']];
                if (count($group['rows']) % 250 === 0) $this->assertTimesheetsMemoryBudget($count, 1);
            }
            if ($group !== null) yield $group;
            unset($query);
        } finally {
            $db = null;
            if (is_file($dbPath)) unlink($dbPath);
        }
    }

    private function assertTimesheetsMemoryBudget(int $processed, int $groups): void
    {
        $used = memory_get_usage(true);
        $configuredMb = max(64, (int) config('migration.timesheets_memory_guard_mb', 384));
        $limit = $configuredMb * 1048576;
        $phpLimit = trim((string) ini_get('memory_limit'));
        if ($phpLimit !== '' && $phpLimit !== '-1' && preg_match('/^([0-9]+)([KMG]?)$/i', $phpLimit, $match)) {
            $scale = [''=>1, 'K'=>1024, 'M'=>1048576, 'G'=>1073741824][strtoupper($match[2])];
            $limit = min($limit, (int) floor((int) $match[1] * $scale * 0.8));
        }
        if ($used >= $limit) {
            throw new \RuntimeException(
                'TIMESHEETS_MEMORY_PRESSURE: grouping memory budget reached; '
                .'processed='.$processed.' groups='.$groups
                .' allocated_mb='.round($used / 1048576)
                .' guard_mb='.round($limit / 1048576)
                .'. Re-run with a bounded grouping implementation; do not increase worker timeout.'
            );
        }
    }

    /**
     * Approved historical exception: source rows with SQL NULL rate_id are
     * not ClientMember billable facts. They stay in legacy storage and must
     * never acquire target mappings under this import contract.
     */
    protected function validationEligibleRows(string $table, array $batch): array
    {
        if ($table === 'rates') {
            return array_values(array_filter($batch, fn ($row) =>
                !$this->isEmptyOrphanSourceRate((string)($row['id'] ?? ''), (string)($row['member_id'] ?? ''))));
        }
        if ($table === 'members') {
            return array_values(array_filter($batch, fn ($row) =>
                !$this->isUnreferencedSourceMember((string)($row['id'] ?? ''))));
        }
        if ($table !== 'timesheets') return $batch;
        return array_values(array_filter($batch, static fn ($row) =>
            array_key_exists('rate_id', $row) && $row['rate_id'] !== null));
    }

    /**
     * An unreferenced legacy member is not evidence of a client allocation.
     * Without any legacy rate it has no hours to migrate. Preserve its source
     * row as provenance instead of guessing a ClientMember.
     */
    /**
     * A dangling source rate has no domain fact if its owner is absent and
     * no Timesheets entries refer to it. Preserve only as legacy metadata.
     * A dangling rate WITH facts must still block import.
     */
    private function isEmptyOrphanSourceRate(string $rateId, string $memberId): bool
    {
        foreach ($this->source['members'] ?? [] as $member) {
            if ((string)($member['id'] ?? '') === $memberId) return false;
        }
        foreach ($this->sourceRows('timesheets') as $fact) {
            if ((string)($fact['rate_id'] ?? '') === $rateId) return false;
        }
        return $this->store->mapping('timesheets','rates',$rateId) === null;
    }

    private function isUnreferencedSourceMember(string $legacyId): bool
    {
        foreach ($this->source['rates'] ?? [] as $rate) {
            if ((string)($rate['member_id'] ?? '') === $legacyId) return false;
        }
        return $this->store->mapping('timesheets','members',$legacyId) === null
            && $this->store->override('timesheets','members',$legacyId) === null;
    }

    // Only the large fact table is streamed. The small dependency tables
    // remain loaded until they have been validated and mapped.
    protected function deferredSourceTables(): array { return ['timesheets']; }

    public function migrate(int $runId, bool $dryRun): array
    {
        $missing = [];
        if (!Schema::connection('target_clients')->hasTable('client_members')) $missing[] = 'client_members';
        if (!Schema::connection('target_timesheets')->hasColumn('timesheet_entries', 'client_member_id'))
            $missing[] = 'timesheet_entries.client_member_id';
        if ($missing) throw new \RuntimeException('MIGRATION_PREFLIGHT|TIMESHEETS_TARGET_SCHEMA_MISSING|'
            .'missing='.implode(',', $missing));
        $invalid = ['members' => 0, 'rates' => 0, 'timesheets' => 0];
        foreach (DB::table('migration_mappings')->where('service', 'timesheets')
            ->whereIn('entity_type', ['members','rates','timesheets'])->cursor() as $mapping) {
            $resolution = json_decode($mapping->metadata ?? '{}', true)['resolution'] ?? [];
            if (($resolution['version'] ?? null) !== 3) {
                ++$invalid[$mapping->entity_type];
                if ($dryRun) $this->store->conflict($runId, 'timesheets', $mapping->entity_type, $mapping->legacy_id,
                    'TIMESHEETS_LEGACY_RESOLUTION_VERSION',
                    'Сопоставление основано на прежней модели. Нужна сверка ClientMember и текущих связей до записи.',
                    ['resolution_version'=>is_int($resolution['version'] ?? null) ? $resolution['version'] : null,
                        'target_id_present'=>$mapping->target_id !== null]);
            }
        }
        if (array_sum($invalid) && $dryRun) return ['mode'=>'dry-run','tables'=>[],
            'conflicts'=>array_sum($invalid), 'warnings'=>0,
            'preflight'=>'legacy_resolutions_need_reconciliation','counts'=>$invalid];
        if (array_sum($invalid)) throw new \RuntimeException(
            'MIGRATION_PREFLIGHT|TIMESHEETS_LEGACY_MAPPINGS_INCOMPATIBLE|'
            .implode(',', array_map(static fn ($type) => $type.'='.$invalid[$type], array_keys($invalid))));
        $this->dependencyPlanHash = null;
        $this->requireBaseline = !$dryRun && \App\Migration\Core\ControlMode::$enabled;
        if ($this->requireBaseline
            && $this->store->completedControlDryRunHashes($runId, $this->key(), 'timesheets') === null) {
            // Guard at the worker boundary, before any members or rates are
            // mapped. Previously the paged fact scanner checked the baseline
            // only after reference entities could already be written.
            throw new \RuntimeException(
                'Timesheets: нет завершённого без конфликтов Dry run в этой операции. '
                .'Запись запрещена; проверьте неподтверждённые members и запустите новый перенос.'
            );
        }
        $result = parent::migrate($runId, $dryRun);
        if ($dryRun && $result['conflicts'] === 0) $this->store->event($runId,
            'timesheets-dependency-plan','Проверен план зависимостей Timesheets.','info',
            ['version'=>3,'sha256'=>$this->dependencyPlanHash]);
        return $result;
    }

    public function validate(int $runId): array
    {
        $this->runId = $runId;
        $this->requireBaseline = \App\Migration\Core\ControlMode::$enabled;
        $result = parent::validate($runId);
        $source = $this->extract();
        $this->source = $source;
        $plans = []; $changed = 0; $checked = 0;
        foreach ($source['rates'] ?? [] as $rate) {
            $memberSource = null;
            foreach ($source['members'] ?? [] as $member) if ((string)$member['id'] === (string)$rate['member_id']) $memberSource = $member;
            if (!$memberSource) {
                if ($this->isEmptyOrphanSourceRate((string)$rate['id'], (string)$rate['member_id'])) continue;
                ++$changed;
                continue;
            }
            ++$checked;
            try {
                $mapping = DB::table('migration_mappings')->where('service','timesheets')->where('entity_type','rates')
                    ->where('legacy_id',(string)$rate['id'])->first();
                if (!$mapping) throw new \DomainException('Missing rate provenance');
                $meta = json_decode($mapping->metadata ?? '{}',true);
                if (($meta['source'] ?? null) != $rate) throw new \DomainException('Source rate changed');
                $memberId = $this->store->mapping('timesheets','members',(string)$rate['member_id']);
                $member = DB::connection('target_clients')->table('client_members')->find((int)$memberId);
                if (!$member) throw new \DomainException('Member missing');
                if ($member->partner_specialist_id !== null) {
                    $connection = TimesheetsResolution::partner($memberSource, $this->store);
                } else {
                    $candidates = TimesheetsResolution::candidates((int) $member->employee_id);
                    $found = array_values(array_filter($candidates,static fn ($c) => $c['target_id'] === (int)$memberId));
                    if (count($found) !== 1) throw new \DomainException('ClientMember missing');
                    $connection = $found[0] + ['employee_id'=>(int)$member->employee_id];
                }
                // Validate legacy scalar anchors against Clients, never target_timesheets.
                TimesheetsResolution::rate($rate,$connection,[],$this->store);
                $saved = $meta['resolution'] ?? null;
                if ($saved !== null) {
                    if (($saved['version'] ?? null) !== 3 || (int)$saved['client_member_id'] !== (int)$memberId
                        || ($saved['employee_id'] ?? null) !== ($member->employee_id === null ? null : (int)$member->employee_id)
                        || ($saved['partner_specialist_id'] ?? null) !== ($member->partner_specialist_id === null ? null : (int)$member->partner_specialist_id))
                        throw new \DomainException('Rate lineage changed');
                    $terms = array_column($connection['terms'],null,'id');
                    foreach ($saved['terms'] ?? [] as $term) if (($terms[$term['id']] ?? null) != $term)
                        throw new \DomainException('Effective term changed');
                }
                $plans[(string)$rate['id']] = [$connection + ['source_member_id'=>(string)$rate['member_id']],$saved];
            } catch (\DomainException $e) { ++$changed; }
        }
        $aggregated = [];
        $excluded = ['count'=>0,'hours_cents'=>0,'mapped_conflicts'=>0];
        $importableCents = 0;
        foreach ($this->sourceRows('timesheets') as $fact) {
            if (array_key_exists('rate_id', $fact) && $fact['rate_id'] === null) {
                $excluded['count']++;
                try { $excluded['hours_cents'] += (int) round($this->decimal($fact['hours'], 24) * 100); }
                catch (\DomainException) { ++$changed; }
                if ($this->store->mapping('timesheets','timesheets',(string)$fact['id']) !== null) {
                    ++$excluded['mapped_conflicts'];
                    ++$changed;
                }
                continue;
            }
            try { $importableCents += (int) round($this->decimal($fact['hours'], 24) * 100); }
            catch (\DomainException) { ++$changed; continue; }
            if (!isset($plans[(string)($fact['rate_id'] ?? '')])) continue;
            [$connection,$saved] = $plans[(string)$fact['rate_id']];
            try {
                $term = null;
                try { $term = TimesheetsResolution::termForDate($connection['terms'],(string)$fact['date']); }
                catch (\App\Migration\Core\SourceRowConflict $e) {
                    if ($e->reason !== 'TARGET_TERM_GAP') throw $e;
                }
                if ($saved !== null && $term !== null && !in_array((int)$term['id'],$saved['term_ids'] ?? [],true)) ++$changed;
                $metadata = DB::table('migration_mappings')->where('service','timesheets')->where('entity_type','timesheets')
                    ->where('legacy_id',(string)$fact['id'])->value('metadata');
                $factPlan = json_decode($metadata ?? '{}',true)['resolution'] ?? null;
                if ($factPlan !== null && (($factPlan['version'] ?? null) !== 3
                    || (int)$factPlan['client_member_id'] !== (int)$connection['target_id']
                    || ($factPlan['target_term_id'] ?? null) !== ($term === null ? null : (int)$term['id'])
                    || (string)$factPlan['source_rate_id'] !== (string)$fact['rate_id'])) ++$changed;
                $targetId = $this->store->mapping('timesheets','timesheets',(string)$fact['id']);
                if ($targetId !== null) {
                    $aggregated[$targetId]['hours'] = ($aggregated[$targetId]['hours'] ?? 0) + (float)$fact['hours'];
                    $aggregated[$targetId]['notes'][] = ['id'=>(int)$fact['id'], 'text'=>trim((string)($fact['description'] ?? ''))];
                    $aggregated[$targetId]['member_id'] = (int)$connection['target_id'];
                    $aggregated[$targetId]['date'] = (string)$fact['date'];
                }
            } catch (\DomainException $e) { ++$changed; }
        }
        $targetCents = 0;
        foreach (array_chunk(array_keys($aggregated), 200) as $ids) {
            $targets = DB::connection('target_timesheets')->table('timesheet_entries')
                ->whereIn('id',$ids)->get()->keyBy('id');
            foreach ($ids as $id) {
                $fact = $aggregated[$id]; $target = $targets[$id] ?? null;
                if ($target !== null) {
                    try { $targetCents += (int) round($this->decimal($target->hours, 24) * 100); }
                    catch (\DomainException) { ++$changed; }
                }
                usort($fact['notes'], static fn($a,$b) => $a['id'] <=> $b['id']);
                $notes = array_values(array_filter(array_column($fact['notes'],'text'),static fn($note) => $note !== ''));
                $description = count($fact['notes']) === 1 ? $fact['notes'][0]['text']
                    : (count($notes) ? implode("\n",$notes) : null);
                if (!$target || (int)$target->client_member_id !== $fact['member_id']
                    || substr((string)$target->work_date,0,10) !== $fact['date']
                    || abs((float)$target->hours - $fact['hours']) > 0.000001
                    || trim((string)$target->description) !== trim((string)$description)) ++$changed;
            }
        }
        if ($importableCents !== $targetCents) ++$changed;
        $result['hour_reconciliation'] = [
            'source_importable_hours'=>$importableCents / 100,
            'mapped_target_hours'=>$targetCents / 100,
            'difference_hours'=>($targetCents - $importableCents) / 100,
        ];
        $result['exclusions'] = ['rate_id_null_count'=>$excluded['count'],
            'rate_id_null_hours'=>$excluded['hours_cents'] / 100,
            'previously_mapped'=>$excluded['mapped_conflicts']];
        $result['provenance'] = ['rates'=>$checked,'grouped_facts'=>count($aggregated),'changed'=>$changed];
        $result['ok'] = $result['ok'] && $changed === 0;
        return $result;
    }

    /** The baseline covers reference rows, current identities, aliases, terms and effective mappings. */
    private function guardDependencyPlan(): void
    {
        $state = ['source'=>$this->source, 'identities'=>[], 'candidates'=>[], 'mappings'=>[], 'overrides'=>[]];
        if (in_array('timesheets',$this->deferredSourceTables(),true))
            unset($state['source']['timesheets']); // Streamed fact pages have their own complete hash inventory.
        foreach ($state['source'] as &$rows) usort($rows, static fn ($a,$b) => strcmp(json_encode($a),json_encode($b)));
        unset($rows);
        foreach ($this->source['users'] ?? [] as $row) {
            $id = (string)$row['id'];
            try {
                $employee = TimesheetsResolution::employee($row,$this->store);
                $current = DB::connection('target_employees')->table('employees')->find($employee);
                $alias = str_contains((string)($row['email'] ?? ''),'@')
                    ? \App\Migration\Core\EmployeeLoginRegistry::matchingLogin($row,'timesheets') : null;
                $state['identities'][$id] = ['employee_id'=>$employee,'login'=>$current->login ?? null,'alias'=>$alias];
                $state['candidates'][$employee] = TimesheetsResolution::candidates($employee);
            } catch (\DomainException $e) { $state['identities'][$id] = ['error'=>$e->getMessage()]; }
        }
        foreach (DB::table('migration_mappings')->whereIn('service',['employees','clients','vacations','timesheets'])
            ->orderBy('service')->orderBy('entity_type')->orderBy('legacy_id')->get() as $map) {
            if ($map->service !== 'timesheets' && !in_array($map->entity_type,['users','employee'],true)) continue;
            if ($map->service === 'timesheets' && !in_array($map->entity_type,['users','members','rates'],true)) continue;
            $metadata = json_decode($map->metadata ?? '{}',true);
            $source = $metadata['source'] ?? [];
            $state['mappings'][] = ['service'=>$map->service,'entity_type'=>$map->entity_type,
                'legacy_id'=>(string)$map->legacy_id,'target_id'=>(string)$map->target_id,
                'identity'=>array_intersect_key($source,array_flip(['employee_id','external_key','email'])),
                'resolution'=>array_diff_key($metadata['resolution'] ?? [],['plan_hash'=>true])];
        }
        foreach (DB::table('migration_overrides')->whereIn('service',['timesheets','clients','vacations'])
            ->orderBy('service')->orderBy('entity_type')->orderBy('legacy_id')->get() as $override)
            $state['overrides'][] = ['service'=>$override->service,'entity_type'=>$override->entity_type,
                'legacy_id'=>(string)$override->legacy_id,'target_id'=>(string)$override->target_id,'note'=>$override->note ?? null];
        ksort($state['identities']); ksort($state['candidates']);
        $hash = hash('sha256',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $expected = $this->requireBaseline ? $this->store->completedControlDryRunPlanHash($this->runId) : $this->dependencyPlanHash;
        if (($this->requireBaseline && $expected === null) || ($expected !== null && !hash_equals($expected,$hash)))
            throw new \RuntimeException('Timesheets dependency plan is missing or changed since dry-run; all target and mapping writes blocked. Repeat dry-run.');
        $this->dependencyPlanHash = $hash;
    }

    protected function sourceBatches(string $table): iterable
    {
        if ($table !== 'timesheets' || !in_array('timesheets', $this->deferredSourceTables(), true)) {
            yield from parent::sourceBatches($table);
            return;
        }

        $reader = new \App\Migration\Core\LegacyReader($this->key());
        $highWater = $this->deferredSourceWatermarks[$table] ?? null;
        if ($highWater === null) {
            if ($this->requireBaseline) {
                $known = $this->store->completedControlDryRunHashes($this->runId, $this->key(), $table);
                if ($known === null || $known !== []) {
                    throw new \RuntimeException('Empty legacy Timesheets cannot replace a nonempty or missing control dry-run baseline.');
                }
            }
            if (($this->sourceTotals[$table] ?? 0) !== 0) {
                throw new \RuntimeException('Legacy Timesheets inventory has rows but no source key.');
            }
            return;
        }
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
                if ($this->store->cancellationRequested($this->runId)) {
                    throw new \RuntimeException('Operator cancellation requested after Timesheets batch '.$batchNumber.'.');
                }
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
        $this->guardDependencyPlan();
        $factDates = [];
        foreach ($this->sourceRows('timesheets') as $fact) {
            $factDates[(string)($fact['rate_id'] ?? '')][(string)($fact['date'] ?? '')] = true;
        }
        $uuidUsers = [];
        $this->rows('users', function ($r, $id) use (&$uuidUsers) {
            $employee = $this->employee($r);
            $previous = $this->store->mapping('timesheets', 'users', $id);
            if ($previous !== null && (int) $previous !== $employee) {
                throw new \App\Migration\Core\SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                    'Ранее перенесённый Timesheets user связан с другим сотрудником. Требуется сверка, перепривязка запрещена.');
            }
            $this->map('users', $id, $employee, $r);
            if (!empty($r['employee_id'])) $uuidUsers[$r['employee_id']][] = $employee;
        });
        // Load rate periods once from the source inventory before resolving
        // members. Every candidate is evaluated against ALL source intervals.
        $rateWindows = [];
        foreach ($this->sourceRows('rates') as $rate) {
            $rateWindows[(string) ($rate['member_id'] ?? '')][] = [
                'id' => (string) ($rate['id'] ?? ''),
                'from' => $rate['from'] ?? null,
                'to' => $rate['to'] ?? null,
                'workload' => $rate['workload'] ?? null,
                'fact_dates' => array_keys($factDates[(string)($rate['id'] ?? '')] ?? []),
            ];
        }
        $uuidSourceUsers = [];
        foreach ($this->source['users'] ?? [] as $user) $uuidSourceUsers[(string)($user['employee_id'] ?? '')][] = (string)$user['id'];
        $members = [];
        $this->rows('members', function ($r, $id) use ($uuidUsers, $uuidSourceUsers, $rateWindows, &$members) {
            if ($this->isUnreferencedSourceMember($id)) {
                $this->preserve('members', $r, $id,
                    'Legacy member has no linked rates or hours. Preserve as metadata; do not invent a Clients connection.');
                return;
            }
            $type = mb_strtolower(trim((string) ($r['memberable_type'] ?? '')));
            if (\App\Migration\Core\TimesheetsUnresolvedMembers::isExternal($type)) {
                $resolved = TimesheetsResolution::partner($r, $this->store);
                $members[$id] = $resolved;
                $this->map('members', $id, (int) $resolved['target_id'], $r, ['resolution'=>[
                    'version'=>3, 'client_member_id'=>$resolved['target_id'],
                    'partner_specialist_id'=>$resolved['partner_specialist_id']]]);
                return;
            }
            if ($type !== 'employee') {
                throw new \DomainException('Unknown member type: explicit reconciliation required');
            }
            foreach ($uuidSourceUsers[(string)($r['employee_id'] ?? '')] ?? [] as $userId) $this->ref('users',$userId);
            $employees = array_unique($uuidUsers[$r['employee_id']] ?? []);
            if (count($employees) !== 1) throw new \DomainException('Timesheets member employee UUID must resolve through a unique corporate login');
            $employee = $employees[0];
            $resolved = TimesheetsResolution::member($r, $employee, $rateWindows[(string)$id] ?? [], $this->store);
            $members[$id] = $resolved;
            $this->map('members', $id, (int)$resolved['target_id'], $r, ['resolution'=>[
                'version'=>3,'employee_id'=>$employee,'client_member_id'=>$resolved['target_id']]]);
        });
        $rates = [];
        $this->rows('rates', function ($r, $id) use ($members, $factDates, &$rates) {
            // The absence of the source member is a real referential defect.
            // If there are no hours, retain evidence without inventing a target.
            // If hours exist, continue through strict dependency validation.
            if (!isset($members[(string)$r['member_id']])
                && empty($factDates[(string)$id])
                && $this->isEmptyOrphanSourceRate($id, (string)$r['member_id'])) {
                $this->preserve('rates', $r, $id,
                    'Legacy rate refers to a missing source member and has no hours. Retain as metadata; no target rate or mapping created.');
                return;
            }
            $this->ref('members', $r['member_id']);
            $connection = $this->need($members[(string)$r['member_id']] ?? null, 'Rate member is unresolved');
            $resolution = TimesheetsResolution::rate($r, $connection,
                array_keys($factDates[(string)$id] ?? []), $this->store);
            if (array_filter($resolution['terms'], static fn ($term) =>
                !is_numeric($r['workload'] ?? null) || abs((float)$term['hours_per_day'] - (float)$r['workload']) > 0.000001))
                $this->warning('rates',$id,'LEGACY_WORKLOAD_DIFFERENCE','Нагрузка источника отличается от Clients; исходное значение сохранено, фактические часы не изменены.',
                    ['source'=>$r,'resolution'=>$resolution]);
            $rates[$id] = $connection + ['from'=>$r['from'],'to'=>$r['to'],'resolution'=>$resolution,'source_member_id'=>(string)$r['member_id']];
            $this->map('rates', $id, $resolution['anchor_id'], $r,
                ['resolution'=>$resolution + ['plan_hash'=>$this->dependencyPlanHash]]);
        });
        $this->store->progress($this->runId, 'timesheets-preflight', 'Группируем исходные часы по подключению и дню.');
        // Partition by the exact target grouping key, not source ID. Records
        // belonging to the same ClientMember/day always enter the same bucket.
        // Keep source rows on private disk instead of a run-wide PHP array.
        $bucketCount = max(16, min(256, (int) config('migration.timesheets_group_buckets', 128)));
        $spoolDir = sys_get_temp_dir().'/migration-timesheets-'.bin2hex(random_bytes(12));
        if (!mkdir($spoolDir, 0700) || !is_dir($spoolDir)) {
            throw new \RuntimeException('TIMESHEETS_SPOOL_UNAVAILABLE: cannot create private grouping directory.');
        }
        $handles = [];
        $bucketPaths = [];
        $dailyDbPath = $spoolDir.'/daily.sqlite';
        $dailyDb = new \PDO('sqlite:'.$dailyDbPath);
        $dailyDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dailyDb->exec('PRAGMA journal_mode=DELETE');
        $dailyDb->exec('CREATE TABLE daily_hours (day_key TEXT PRIMARY KEY, hours REAL NOT NULL)');
        $dailyAdd = $dailyDb->prepare('INSERT INTO daily_hours(day_key,hours) VALUES (?,?) ON CONFLICT(day_key) DO UPDATE SET hours=hours+excluded.hours');
        $dailyGet = $dailyDb->prepare('SELECT hours FROM daily_hours WHERE day_key=?');
        $excludedFacts = 0; $excludedCents = 0;
        $this->externalDailyHours($rates, $dailyDb);
        $externalGet = $dailyDb->prepare('SELECT hours FROM external_hours WHERE day_key=?');
        $dailyDb->beginTransaction();
        $processed = 0;
        try {
        $this->rows('timesheets', function ($r, $id) use ($rates, $dailyAdd, $dailyGet, $externalGet, $dailyDb, &$handles, &$bucketPaths, $bucketCount, $spoolDir, &$processed, &$excludedFacts, &$excludedCents) {
            if (array_key_exists('rate_id', $r) && $r['rate_id'] === null) {
                if ($this->store->mapping('timesheets', 'timesheets', $id) !== null)
                    throw new \App\Migration\Core\SourceRowConflict('EXCLUDED_FACT_ALREADY_MAPPED',
                        'Запись без ставки ранее была перенесена: требуется сверка перед повторным запуском.');
                $hours = $this->decimal($r['hours'], 24);
                ++$excludedFacts;
                $excludedCents += (int) round($hours * 100);
                $this->warning('timesheets', $id, 'LEGACY_RATELESS_FACT_EXCLUDED',
                    'Факт с NULL rate_id исключён из текущего клиентского переноса по утверждённому решению.',
                    ['work_date'=>$r['date'] ?? null, 'hours'=>$hours]);
                return;
            }
            ++$processed;
            if ($processed % 250 === 0) {
                $this->assertTimesheetsMemoryBudget($processed, 0);
                $this->store->progress($this->runId, 'timesheets-rows',
                    'Проверено фактов: '.$processed.'; PHP memory allocated: '
                    .'; PHP allocated: '.round(memory_get_usage(true) / 1048576).' MiB.');
                if ($this->store->cancellationRequested($this->runId)) {
                    throw new \RuntimeException('TIMESHEETS_CANCELLED: stopped during fact grouping at '.$processed.'.');
                }
            }
            $this->ref('rates', $r['rate_id']);
            $rate = $this->need($rates[(string) $r['rate_id']] ?? null, 'Timesheet rate is unresolved');
            $this->dates($r['date'], $r['date']);
            $term = null;
            try { $term = TimesheetsResolution::termForDate($rate['terms'], $r['date']); }
            catch (\App\Migration\Core\SourceRowConflict $e) {
                if ($e->reason !== 'TARGET_TERM_GAP') throw $e;
                $this->warning('timesheets', $id, 'HISTORICAL_FACT_WITHOUT_CLIENT_TERM',
                    'Исторические часы сохранены для подтверждённого ClientMember без выдумывания коммерческих условий.',
                    ['work_date'=>$r['date'], 'client_member_id'=>$rate['target_id'], 'amount_calculation'=>'unavailable']);
            }
            if (!\App\Migration\Core\TimesheetsConflictEvidence::withinPeriod($r['date'], $rate['from'], $rate['to']))
                $this->warning('timesheets',$id,'LEGACY_RATE_DATE_INCONSISTENCY',
                    'Фактическая дата вне исходной ставки; целевое условие подтверждено. Исходные значения сохранены.',
                    ['source'=>$r,'legacy_rate_from'=>$rate['from'],'legacy_rate_to'=>$rate['to'],'target_term_id'=>$term['id'] ?? null]);
            $hours = $this->decimal($r['hours'], 24);
            $identity = $rate['employee_id'] !== null ? 'e:'.$rate['employee_id'] : 'p:'.$rate['partner_specialist_id'];
            $dayKey = $identity.':'.$r['date'];
            $dailyAdd->execute([$dayKey, $hours]);
            $dailyGet->execute([$dayKey]);
            $dailyHours = (float)$dailyGet->fetchColumn();
            $dailyGet->closeCursor();
            $externalGet->execute([$dayKey]);
            $priorHours = (float)($externalGet->fetchColumn() ?: 0);
            $externalGet->closeCursor();
            if ($priorHours + $dailyHours > 24.000001)
                throw new \App\Migration\Core\SourceRowConflict('DAILY_HOURS_EXCEEDED', 'Сумма часов за день по одной личности превышает 24.', ['work_date'=>$r['date']]);
            $key = $rate['target_id'].':'.$r['date'];
            $bucket = hexdec(substr(hash('sha256', $key), 0, 8)) % $bucketCount;
            if (!isset($handles[$bucket])) {
                $path = $spoolDir.'/bucket-'.$bucket.'.jsonl';
                $handle = fopen($path, 'ab');
                if ($handle === false) throw new \RuntimeException('TIMESHEETS_SPOOL_UNAVAILABLE: open failed.');
                chmod($path, 0600);
                $handles[$bucket] = $handle;
                $bucketPaths[$bucket] = $path;
            }
            $record = json_encode(['key'=>$key, 'member'=>$rate, 'date'=>$r['date'],
                'source'=>$r, 'id'=>$id, 'hours'=>$hours, 'term_id'=>$term === null ? null : (int)$term['id']],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (fwrite($handles[$bucket], $record) !== strlen($record)) {
                throw new \RuntimeException('TIMESHEETS_SPOOL_IO_ERROR: incomplete bucket write.');
            }
        });
        $dailyDb->commit();
        foreach ($handles as $handle) {
            if (!fflush($handle)) throw new \RuntimeException('TIMESHEETS_SPOOL_IO_ERROR: flush failed.');
            fclose($handle);
        }
        $handles = [];
        $this->summary['exclusions'] = ['rate_id_null_count'=>$excludedFacts,
            'rate_id_null_hours'=>$excludedCents / 100];
        $this->auditPages = !$this->isPreflight();
        $writtenGroups = 0;
        ksort($bucketPaths, SORT_NUMERIC);
        foreach ($bucketPaths as $bucketNumber => $path) {
            $bucketRows = 0;
            $this->store->progress($this->runId, 'timesheets-groups',
                'Обрабатывается часть '.($bucketNumber + 1).'/'.$bucketCount
                .'; PHP allocated: '.round(memory_get_usage(true) / 1048576).' MiB.');
            foreach ($this->sortedBucketGroups($path, $spoolDir) as $group) {
            $bucketRows += count($group['rows']);
            $rows = $group['rows'];
            usort($rows, static fn ($a,$b) => (int)$a['id'] <=> (int)$b['id']);
            $first = $rows[0]; $rate = $group['member'];
            $hours = round(array_sum(array_column($rows, 'hours')), 2);
            $descriptions = array_values(array_filter(array_map(static fn ($item) => trim((string) ($item['source']['description'] ?? '')), $rows), static fn ($text) => $text !== ''));
            $description = count($rows) === 1 ? $first['source']['description'] : (count($descriptions) ? implode("\n", $descriptions) : null);
            $payload = ['client_member_id'=>$rate['target_id'], 'employee_id'=>$rate['employee_id'],
                'project_id'=>null, 'work_date'=>$group['date'], 'hours'=>$hours, 'description'=>$description];
            $targetId = $this->write('timesheets', $first['id'], 'timesheet_entries', $payload, $first['source'],
                ['client_member_id'=>$rate['target_id'], 'work_date'=>$group['date']],
                ['resolution'=>['version'=>3, 'client_member_id'=>$rate['target_id'],
                    'target_term_id'=>$first['term_id'], 'source_rate_id'=>(string)$first['source']['rate_id'],
                    'plan_hash'=>$this->dependencyPlanHash]]);
            foreach (array_slice($rows, 1) as $item) $this->map('timesheets', $item['id'], $targetId, $item['source'],
                ['alias_of'=>$first['id'], 'resolution'=>['version'=>3, 'client_member_id'=>$rate['target_id'],
                    'target_term_id'=>$item['term_id'], 'source_rate_id'=>(string)$item['source']['rate_id'],
                    'plan_hash'=>$this->dependencyPlanHash]]);
            foreach ($rows as $item) if ($this->boolean($item['source']['is_submitted']))
                $this->preserve('timesheets', $item['source'], $item['id'],
                    'Per-entry submission is preserved in metadata; a common employee day or partner confirmation cannot be invented.');
            if (++$writtenGroups % 500 === 0) {
                $this->store->progress($this->runId, 'timesheets-groups',
                    'Обработано групп ClientMember/день: '.$writtenGroups.'.');
                if (!$this->dryRun && $this->store->cancellationRequested($this->runId))
                    throw new \RuntimeException('Operator cancellation requested after Timesheets group '.$writtenGroups.'.');
            }
            }
            $this->store->event($this->runId, 'timesheets-group-bucket',
                'Часть Timesheets проверена.', 'info',
                ['bucket'=>$bucketNumber + 1, 'bucket_count'=>$bucketCount,
                 'rows'=>$bucketRows]);
        }
        $this->auditPages = false;
        foreach (['month_confirmations', 'time_records', 'vacations', 'business_dates', 'departments'] as $table) {
            $this->rows($table, fn ($r, $id) => $this->preserve($table, $r, $id,
                'Legacy confirmations lack the final actor/action timestamp; personal time records lack client/project. Originals preserved without invented approvals or commercial hours.'));
        }
        } finally {
            if (isset($dailyDb) && $dailyDb->inTransaction()) $dailyDb->rollBack();
            unset($externalGet, $dailyGet, $dailyAdd, $dailyDb);
            if (is_file($dailyDbPath)) unlink($dailyDbPath);
            foreach ($handles as $handle) if (is_resource($handle)) fclose($handle);
            foreach ($bucketPaths as $path) if (is_file($path)) unlink($path);
            if (is_dir($spoolDir)) rmdir($spoolDir);
        }
    }

    /**
     * Read existing hours once per source day, rather than building an ever-growing
     * WHERE NOT IN list for every timesheet row (quadratic work at import scale).
     * Only rows mapped from this exact source inventory may be excluded.
     */
    /**
     * Keep source inventory, owned target IDs and external daily balances in
     * a temporary indexed SQLite file. Only distinct specialist IDs remain
     * in PHP (bounded by the specialist registry, not by source fact count).
     */
    private function externalDailyHours(array $rates, \PDO $db): void
    {
        $db->exec('CREATE TABLE source_ids (id TEXT PRIMARY KEY)');
        $db->exec('CREATE TABLE source_days (day_key TEXT PRIMARY KEY)');
        $db->exec('CREATE TABLE owned_ids (id TEXT PRIMARY KEY)');
        $db->exec('CREATE TABLE external_hours (day_key TEXT PRIMARY KEY, hours REAL NOT NULL)');
        $insertId = $db->prepare('INSERT OR IGNORE INTO source_ids(id) VALUES (?)');
        $insertDay = $db->prepare('INSERT OR IGNORE INTO source_days(day_key) VALUES (?)');
        $employees = []; $partners = []; $first = null; $last = null;
        $db->beginTransaction();
        foreach ($this->sourceRows('timesheets') as $row) {
            if (isset($row['id'])) $insertId->execute([(string)$row['id']]);
            $rate = $rates[(string)($row['rate_id'] ?? '')] ?? null;
            $date = $row['date'] ?? null;
            if (!$rate || !is_string($date) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $date)) continue;
            $dayKey = ($rate['employee_id'] !== null ? 'e:'.$rate['employee_id'] : 'p:'.$rate['partner_specialist_id']).':'.$date;
            $insertDay->execute([$dayKey]);
            if ($rate['employee_id'] !== null) $employees[(int)$rate['employee_id']] = true;
            else $partners[(int)$rate['partner_specialist_id']] = true;
            if ($first === null || $date < $first) $first = $date;
            if ($last === null || $date > $last) $last = $date;
        }
        $db->commit();
        unset($insertId, $insertDay);
        if ($first === null) return;
        $sourcePresent = $db->prepare('SELECT 1 FROM source_ids WHERE id=?');
        $insertOwned = $db->prepare('INSERT OR IGNORE INTO owned_ids(id) VALUES (?)');
        $db->beginTransaction();
        foreach (DB::table('migration_mappings')->where('service','timesheets')
            ->where('entity_type','timesheets')->select(['legacy_id','target_id'])->cursor() as $mapping) {
            $sourcePresent->execute([(string)$mapping->legacy_id]);
            $found = $sourcePresent->fetchColumn();
            $sourcePresent->closeCursor();
            if ($found) $insertOwned->execute([(string)$mapping->target_id]);
        }
        $db->commit();
        unset($sourcePresent, $insertOwned);
        $sourceDay = $db->prepare('SELECT 1 FROM source_days WHERE day_key=?');
        $owned = $db->prepare('SELECT 1 FROM owned_ids WHERE id=?');
        $add = $db->prepare('INSERT INTO external_hours(day_key,hours) VALUES (?,?) ON CONFLICT(day_key) DO UPDATE SET hours=hours+excluded.hours');
        $include = static function (string $dayKey, string $targetId) use ($sourceDay, $owned): bool {
            $sourceDay->execute([$dayKey]); $hasDay = $sourceDay->fetchColumn(); $sourceDay->closeCursor();
            if (!$hasDay) return false;
            $owned->execute([$targetId]); $isOwned = $owned->fetchColumn(); $owned->closeCursor();
            return !$isOwned;
        };
        $db->beginTransaction();
        foreach (array_chunk(array_keys($employees), 200) as $employeeChunk) {
            $entries = DB::connection('target_timesheets')->table('timesheet_entries')
                ->whereIn('employee_id', $employeeChunk)->whereBetween('work_date', [$first, $last])
                ->select(['id','employee_id','work_date','hours'])->cursor();
            foreach ($entries as $entry) {
                $dayKey = 'e:'.$entry->employee_id.':'.substr((string)$entry->work_date, 0, 10);
                if ($include($dayKey, (string)$entry->id)) $add->execute([$dayKey,(float)$entry->hours]);
            }
        }
        if ($partners) {
            $members = DB::connection('target_clients')->table('client_members')
                ->whereIn('partner_specialist_id', array_keys($partners))
                ->pluck('partner_specialist_id','id')->all();
            foreach (array_chunk(array_keys($members), 200) as $chunk) {
                $entries = DB::connection('target_timesheets')->table('timesheet_entries')
                    ->whereIn('client_member_id', $chunk)->whereBetween('work_date', [$first, $last])
                    ->select(['id','client_member_id','work_date','hours'])->cursor();
                foreach ($entries as $entry) {
                    $dayKey = 'p:'.$members[$entry->client_member_id].':'.substr((string)$entry->work_date,0,10);
                    if ($include($dayKey, (string)$entry->id)) $add->execute([$dayKey,(float)$entry->hours]);
                }
            }
        }
        $db->commit();
    }

}
