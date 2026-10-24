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
     * Approved historical exception: source rows with SQL NULL rate_id are
     * not ClientMember billable facts. They stay in legacy storage and must
     * never acquire target mappings under this import contract.
     */
    protected function validationEligibleRows(string $table, array $batch): array
    {
        if ($table !== 'timesheets') return $batch;
        return array_values(array_filter($batch, static fn ($row) =>
            array_key_exists('rate_id', $row) && $row['rate_id'] !== null));
    }

    // Only the large fact table is streamed. The small dependency tables
    // remain loaded until they have been validated and mapped.
    protected function deferredSourceTables(): array { return ['timesheets']; }

    public function migrate(int $runId, bool $dryRun): array
    {
        if (!Schema::connection('target_clients')->hasTable('client_members')
            || !Schema::connection('target_timesheets')->hasColumn('timesheet_entries', 'client_member_id'))
            throw new \RuntimeException('Expanded ClientMember and Timesheets target schemas are required.');
        foreach (DB::table('migration_mappings')->where('service', 'timesheets')
            ->whereIn('entity_type', ['members','rates','timesheets'])->cursor() as $mapping) {
            $resolution = json_decode($mapping->metadata ?? '{}', true)['resolution'] ?? [];
            if (($resolution['version'] ?? null) !== 3) throw new \RuntimeException(
                'Pre-cutover Timesheets mappings must be reconciled before ClientMember import.');
        }
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
            if (!$memberSource) { ++$changed; continue; }
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
                $term = TimesheetsResolution::termForDate($connection['terms'],(string)$fact['date']);
                if ($saved !== null && !in_array((int)$term['id'],$saved['term_ids'] ?? [],true)) ++$changed;
                $metadata = DB::table('migration_mappings')->where('service','timesheets')->where('entity_type','timesheets')
                    ->where('legacy_id',(string)$fact['id'])->value('metadata');
                $factPlan = json_decode($metadata ?? '{}',true)['resolution'] ?? null;
                if ($factPlan !== null && (($factPlan['version'] ?? null) !== 3
                    || (int)$factPlan['client_member_id'] !== (int)$connection['target_id']
                    || (int)$factPlan['target_term_id'] !== (int)$term['id']
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
        $groups = []; $daily = [];
        $excludedFacts = 0; $excludedCents = 0;
        $externalHours = $this->externalDailyHours($rates);
        $processed = 0;
        $this->rows('timesheets', function ($r, $id) use ($rates, &$groups, &$daily, $externalHours, &$processed, &$excludedFacts, &$excludedCents) {
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
            if (++$processed % 500 === 0) $this->store->progress($this->runId, 'timesheets-rows', 'Проверено исходных записей часов: '.$processed.'.');
            $this->ref('rates', $r['rate_id']);
            $rate = $this->need($rates[(string) $r['rate_id']] ?? null, 'Timesheet rate is unresolved');
            $this->dates($r['date'], $r['date']);
            $term = TimesheetsResolution::termForDate($rate['terms'], $r['date']);
            if (!\App\Migration\Core\TimesheetsConflictEvidence::withinPeriod($r['date'], $rate['from'], $rate['to']))
                $this->warning('timesheets',$id,'LEGACY_RATE_DATE_INCONSISTENCY',
                    'Фактическая дата вне исходной ставки; целевое условие подтверждено. Исходные значения сохранены.',
                    ['source'=>$r,'legacy_rate_from'=>$rate['from'],'legacy_rate_to'=>$rate['to'],'target_term_id'=>$term['id']]);
            $hours = $this->decimal($r['hours'], 24);
            $identity = $rate['employee_id'] !== null ? 'e:'.$rate['employee_id'] : 'p:'.$rate['partner_specialist_id'];
            $dayKey = $identity.':'.$r['date'];
            $daily[$dayKey] = ($daily[$dayKey] ?? 0) + $hours;
            if (($externalHours[$dayKey] ?? 0) + $daily[$dayKey] > 24.000001)
                throw new \App\Migration\Core\SourceRowConflict('DAILY_HOURS_EXCEEDED', 'Сумма часов за день по одной личности превышает 24.', ['work_date'=>$r['date']]);
            $key = $rate['target_id'].':'.$r['date'];
            $groups[$key]['member'] = $rate;
            $groups[$key]['date'] = $r['date'];
            $groups[$key]['rows'][] = ['source'=>$r, 'id'=>$id, 'hours'=>$hours, 'term_id'=>(int)$term['id']];
        });
        $this->summary['exclusions'] = ['rate_id_null_count'=>$excludedFacts,
            'rate_id_null_hours'=>$excludedCents / 100];
        $this->auditPages = !$this->isPreflight();
        $writtenGroups = 0;
        foreach ($groups as $group) {
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
        $partners = [];
        $dates = [];
        foreach ($this->sourceRows('timesheets') as $row) {
            if (isset($row['id'])) $sourceIds[(string) $row['id']] = true;
            $rate = $rates[(string) ($row['rate_id'] ?? '')] ?? null;
            $date = $row['date'] ?? null;
            if (!$rate || !is_string($date) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $date)) continue;
            $identity = $rate['employee_id'] !== null ? 'e:'.$rate['employee_id'] : 'p:'.$rate['partner_specialist_id'];
            $dayKey = $identity.':'.$date;
            $sourceDays[$dayKey] = true;
            if ($rate['employee_id'] !== null) $employees[(int) $rate['employee_id']] = true;
            else $partners[(int) $rate['partner_specialist_id']] = true;
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
                $dayKey = 'e:'.$entry->employee_id.':'.substr((string) $entry->work_date, 0, 10);
                if (!isset($sourceDays[$dayKey]) || isset($ownedIds[(string) $entry->id])) continue;
                $hours[$dayKey] = ($hours[$dayKey] ?? 0) + (float) $entry->hours;
            }
        }
        if ($partners) {
            $members = DB::connection('target_clients')->table('client_members')
                ->whereIn('partner_specialist_id', array_keys($partners))
                ->pluck('partner_specialist_id', 'id')->all();
            foreach (array_chunk(array_keys($members), 200) as $chunk) {
                $entries = DB::connection('target_timesheets')->table('timesheet_entries')
                    ->whereIn('client_member_id', $chunk)->whereBetween('work_date', [$first, $last])
                    ->select(['id','client_member_id','work_date','hours'])->cursor();
                foreach ($entries as $entry) {
                    $dayKey = 'p:'.$members[$entry->client_member_id].':'.substr((string) $entry->work_date, 0, 10);
                    if (!isset($sourceDays[$dayKey]) || isset($ownedIds[(string) $entry->id])) continue;
                    $hours[$dayKey] = ($hours[$dayKey] ?? 0) + (float) $entry->hours;
                }
            }
        }
        return $hours;
    }
}
