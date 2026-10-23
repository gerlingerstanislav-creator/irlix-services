<?php

namespace App\Migration\Services;

use App\Migration\Core\SchemaMigration;
use App\Migration\Core\TimesheetsResolution;
use Illuminate\Support\Facades\DB;

class TimesheetsMigration extends SchemaMigration
{
    use TimesheetsClientMemberImport;

    private function clientMemberTarget(): bool
    {
        return DB::connection('target_clients')->getSchemaBuilder()->hasTable('client_members')
            && DB::connection('target_timesheets')->getSchemaBuilder()->hasColumn('timesheet_entries','client_member_id');
    }

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

    // Only the large fact table is streamed. The small dependency tables
    // remain loaded until they have been validated and mapped.
    protected function deferredSourceTables(): array { return ['timesheets']; }

    public function migrate(int $runId, bool $dryRun): array
    {
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
            ['version'=>2,'sha256'=>$this->dependencyPlanHash]);
        return $result;
    }

    /**
     * The owner explicitly excluded rate-less historical facts from this cutover.
     * Keep them in the scanned inventory/hash, but not in the imported-fact count.
     * Validate refuses any existing target mapping for an excluded source row.
     */
    protected function validateRows(array $rows, ?array $onlyEntities = null): array
    {
        if ($this->clientMemberTarget() && isset($rows['timesheets'])) {
            $rows['timesheets'] = array_values(array_filter($rows['timesheets'],
                static fn (array $row) => ($row['rate_id'] ?? null) !== null
                    && (string) $row['rate_id'] !== ''));
        }
        return parent::validateRows($rows, $onlyEntities);
    }

    public function validate(int $runId): array
    {
        if ($this->clientMemberTarget()) {
            $this->runId=$runId;
            $this->requireBaseline=\App\Migration\Core\ControlMode::$enabled;
            // Alias-aware source IDs + target row integrity, without legacy
            // project_member resolution that has no meaning in the new schema.
            $result=parent::validate($runId);
            $sourceIds=[];$excludedIds=[];$excludedHoursCents=0;
            foreach($this->sourceRows('timesheets') as $fact) {
                $id=(string)$fact['id'];
                if (($fact['rate_id'] ?? null) === null || (string)$fact['rate_id'] === '') {
                    $excludedIds[$id]=true;
                    $excludedHoursCents += $this->v3Cents($fact['hours'] ?? null);
                } else $sourceIds[$id]=true;
            }
            $changed=0;
            foreach(DB::table('migration_mappings')->where(['service'=>'timesheets','entity_type'=>'timesheets'])->cursor() as $mapping){
                if(!isset($sourceIds[(string)$mapping->legacy_id])){$changed++;continue;}
                $meta=json_decode($mapping->metadata??'{}',true);
                if((int)($meta['resolution']['version']??0)!==3){$changed++;continue;}
                foreach($meta['resolution']['source_group']??[] as $sourceId)if(!isset($sourceIds[(string)$sourceId]))$changed++;
            }
            $result['provenance']=['importable_source_inventory'=>count($sourceIds),
                'excluded_rate_null'=>count($excludedIds),
                'excluded_rate_null_hours'=>number_format($excludedHoursCents/100,2,'.',''),
                'source_inventory'=>count($sourceIds)+count($excludedIds),'changed'=>$changed];
            $result['ok']=$result['ok']&&$changed===0;
            return $result;
        }
        $this->runId = $runId;
        $this->requireBaseline = \App\Migration\Core\ControlMode::$enabled;
        $result = parent::validate($runId);
        $source = $this->extract();
        $this->source = $source;
        $plans = []; $changed = 0; $checked = 0;
        foreach ($source['rates'] ?? [] as $rate) {
            $memberSource = null;
            foreach ($source['members'] ?? [] as $member) if ((string)$member['id'] === (string)$rate['member_id']) $memberSource = $member;
            if (!$memberSource || ($memberSource['memberable_type'] ?? '') !== 'employee') continue;
            ++$checked;
            try {
                $mapping = DB::table('migration_mappings')->where('service','timesheets')->where('entity_type','rates')
                    ->where('legacy_id',(string)$rate['id'])->first();
                if (!$mapping) throw new \DomainException('Missing rate provenance');
                $meta = json_decode($mapping->metadata ?? '{}',true);
                if (($meta['source'] ?? null) != $rate) throw new \DomainException('Source rate changed');
                $memberId = $this->store->mapping('timesheets','members',(string)$rate['member_id']);
                $member = DB::connection('target_clients')->table('project_members')->find((int)$memberId);
                if (!$member) throw new \DomainException('Member missing');
                $candidates = TimesheetsResolution::candidates((int)$member->specialist_id);
                $found = array_values(array_filter($candidates,static fn ($c) => $c['target_id'] === (int)$memberId));
                if (count($found) !== 1) throw new \DomainException('Project missing');
                $connection = $found[0] + ['employee_id'=>(int)$member->specialist_id];
                // Validate legacy scalar anchors against Clients, never target_timesheets.
                TimesheetsResolution::rate($rate,$connection,[],$this->store);
                $saved = $meta['resolution'] ?? null;
                if ($saved !== null) {
                    if (($saved['version'] ?? null) !== 2 || (int)$saved['project_member_id'] !== (int)$memberId
                        || (int)$saved['employee_id'] !== (int)$member->specialist_id
                        || (int)$saved['project_id'] !== (int)$member->project_id)
                        throw new \DomainException('Rate lineage changed');
                    $terms = array_column($connection['terms'],null,'id');
                    foreach ($saved['terms'] ?? [] as $term) if (($terms[$term['id']] ?? null) != $term)
                        throw new \DomainException('Effective term changed');
                }
                $plans[(string)$rate['id']] = [$connection + ['source_member_id'=>(string)$rate['member_id']],$saved];
            } catch (\DomainException $e) { ++$changed; }
        }
        foreach ($this->sourceRows('timesheets') as $fact) {
            if (!isset($plans[(string)$fact['rate_id']])) continue;
            [$connection,$saved] = $plans[(string)$fact['rate_id']];
            try {
                $term = TimesheetsResolution::termForDate($connection['terms'],(string)$fact['date']);
                if ($saved !== null && !in_array((int)$term['id'],$saved['term_ids'] ?? [],true)) ++$changed;
                $metadata = DB::table('migration_mappings')->where('service','timesheets')->where('entity_type','timesheets')
                    ->where('legacy_id',(string)$fact['id'])->value('metadata');
                $factPlan = json_decode($metadata ?? '{}',true)['resolution'] ?? null;
                if ($factPlan !== null && (($factPlan['version'] ?? null) !== 2
                    || (int)$factPlan['employee_id'] !== (int)$connection['employee_id']
                    || (int)$factPlan['project_id'] !== (int)$connection['project_id']
                    || (int)$factPlan['project_member_id'] !== (int)$connection['target_id']
                    || (int)$factPlan['target_term_id'] !== (int)$term['id']
                    || (string)$factPlan['source_rate_id'] !== (string)$fact['rate_id']
                    || (string)$factPlan['source_member_id'] !== $connection['source_member_id'])) ++$changed;
            } catch (\DomainException $e) { ++$changed; }
        }
        $result['provenance'] = ['rates'=>$checked,'changed'=>$changed];
        $result['ok'] = $result['ok'] && $changed === 0;
        return $result;
    }

    private function guardClientMemberPlan(): void
    {
        $state=[
            'source_references'=>$this->source,
            'client_members'=>DB::connection('target_clients')->table('client_members')->orderBy('id')->get()->toArray(),
            'commercial_terms'=>DB::connection('target_clients')->table('member_terms')->orderBy('id')->get()->toArray(),
            'mappings'=>DB::table('migration_mappings')->whereIn('service',['timesheets','clients','employees'])
                ->whereIn('entity_type',['users','members','rates','employee','clients'])
                ->orderBy('service')->orderBy('entity_type')->orderBy('legacy_id')->get()->toArray(),
            'overrides'=>DB::table('migration_overrides')->where('service','timesheets')
                ->orderBy('entity_type')->orderBy('legacy_id')->get()->toArray(),
        ];
        $hash=hash('sha256',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $expected=$this->requireBaseline
            ?$this->store->completedControlDryRunPlanHash($this->runId):$this->dependencyPlanHash;
        if(($this->requireBaseline && $expected===null)
            || ($expected!==null && !hash_equals((string)$expected,$hash)))
            throw new \RuntimeException('ClientMember reference catalog changed after preflight; repeat dry-run.');
        $this->dependencyPlanHash=$hash;
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
        if ($this->clientMemberTarget()) {
            $this->guardClientMemberPlan();
            $this->importClientMemberFacts();
            return;
        }
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
        $deferredMembers = [];
        $this->rows('members', function ($r, $id) use ($uuidUsers, $uuidSourceUsers, $rateWindows, &$members, &$deferredMembers) {
            // Not every unknown polymorphic type is an external contractor. Only
            // the confirmed subcontract/partner types may be deferred safely.
            if (in_array(mb_strtolower(trim((string) ($r['memberable_type'] ?? ''))), ['subcontract', 'contractor', 'external', 'partner'], true)) {
                if ($this->store->mapping('timesheets', 'members', $id) !== null) {
                    throw new \App\Migration\Core\SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                        'Внешний участник ранее был сопоставлен с сотрудником. Требуется ручная сверка.');
                }
                $deferredMembers[(string) $id] = true;
                $this->warning('members', $id, 'EXTERNAL_SPECIALIST_DEFERRED',
                    'Внешний специалист: перенос отложен до появления модели внешних специалистов в Timesheets.',
                    ['source' => $r, 'source_type' => $r['memberable_type'] ?? null]);
                return;
            }
            if (($r['memberable_type'] ?? null) !== 'employee') {
                throw new \DomainException('Unknown member type: explicit reconciliation required');
            }
            foreach ($uuidSourceUsers[(string)($r['employee_id'] ?? '')] ?? [] as $userId) $this->ref('users',$userId);
            $employees = array_unique($uuidUsers[$r['employee_id']] ?? []);
            if (count($employees) !== 1) throw new \DomainException('Timesheets member employee UUID must resolve through a unique corporate login');
            $employee = $employees[0];
            $resolved = TimesheetsResolution::member($r, $employee, $rateWindows[(string)$id] ?? [], $this->store);
            $members[$id] = $resolved;
            $this->map('members', $id, (int)$resolved['target_id'], $r, ['resolution'=>[
                'version'=>2,'employee_id'=>$employee,'project_id'=>$resolved['project_id'],'project_member_id'=>$resolved['target_id']]]);
        });
        $rates = [];
        $deferredRates = [];
        $this->rows('rates', function ($r, $id) use ($members, $deferredMembers, $factDates, &$rates, &$deferredRates) {
            if (isset($deferredMembers[(string) ($r['member_id'] ?? '')])) {
                if ($this->store->mapping('timesheets', 'rates', $id) !== null) {
                    throw new \App\Migration\Core\SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                        'Условие внешнего специалиста ранее сопоставлено. Требуется ручная сверка.');
                }
                $deferredRates[(string) $id] = true;
                $this->warning('rates', $id, 'EXTERNAL_SPECIALIST_DEFERRED',
                    'Условие внешнего специалиста отложено вместе с участником.',
                    ['source' => $r, 'member_id' => $r['member_id'] ?? null]);
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
        $daily = [];
        $this->store->progress($this->runId, 'timesheets-preflight', 'Сверяем существующие часы по сотрудникам и дням.');
        $externalHours = $this->externalDailyHours($rates);
        $processed = 0;
        $this->auditPages = !$this->isPreflight();
        $this->rows('timesheets', function ($r, $id) use ($rates, $deferredRates, &$daily, $externalHours, &$processed) {
            if (++$processed % 500 === 0) {
                $this->store->progress($this->runId, 'timesheets-rows', 'Проверено исходных записей часов: '.$processed.'.');
            }
            if (isset($deferredRates[(string) ($r['rate_id'] ?? '')])) {
                if ($this->store->mapping('timesheets', 'timesheets', $id) !== null) {
                    throw new \App\Migration\Core\SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                        'Часы внешнего специалиста ранее перенесены. Требуется ручная сверка.');
                }
                $this->warning('timesheets', $id, 'EXTERNAL_SPECIALIST_DEFERRED',
                    'Исторические часы внешнего специалиста сохранены для последующего переноса.',
                    ['source' => $r, 'rate_id' => $r['rate_id'] ?? null]);
                return;
            }
            $this->ref('rates',$r['rate_id']);
            $rate = $this->need($rates[(string) $r['rate_id']] ?? null, 'Timesheet rate is unresolved');
            $this->dates($r['date'], $r['date']);
            $term = TimesheetsResolution::termForDate($rate['terms'], $r['date']);
            if (!\App\Migration\Core\TimesheetsConflictEvidence::withinPeriod($r['date'], $rate['from'], $rate['to']))
                $this->warning('timesheets',$id,'LEGACY_RATE_DATE_INCONSISTENCY',
                    'Фактическая дата вне исходной ставки; целевое условие подтверждено. Исходные значения сохранены.',
                    ['source'=>$r,'legacy_rate_from'=>$rate['from'],'legacy_rate_to'=>$rate['to'],'target_term_id'=>$term['id']]);
            $hours = $this->number($r['hours'], 24);
            if (abs($hours - round($hours, 2)) > 0.000001) throw new \DomainException('Hours exceed target precision; refusing silent rounding');
            $dayKey = $rate['employee_id'].':'.$r['date'];
            if (($externalHours[$dayKey] ?? 0) + ($daily[$dayKey] ?? 0) + $hours > 24.000001) throw new \DomainException('Total employee hours exceed 24 for the day');
            $payload = array_intersect_key($rate, array_flip(['employee_id','project_id']))
                + ['work_date' => $r['date'], 'hours' => $hours, 'description' => $r['description']];
            $this->write('timesheets', $id, 'timesheet_entries', $payload, $r,
                array_intersect_key($payload, array_flip(['employee_id','project_id','work_date'])),
                ['resolution'=>['version'=>2,'employee_id'=>$rate['employee_id'],'project_id'=>$rate['project_id'],
                    'project_member_id'=>$rate['target_id'],'target_term_id'=>(int)$term['id'],
                    'source_rate_id'=>(string)$r['rate_id'],'source_member_id'=>(string)$rate['source_member_id'],
                    'plan_hash'=>$this->dependencyPlanHash]]);
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
