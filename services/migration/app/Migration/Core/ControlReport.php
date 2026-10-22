<?php
namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

/** Full immutable-export input. Raw legacy source payloads and credentials never leave metadata. */
final class ControlReport
{
    public static function safeContext(array $context): array
    {
        $allowed = ['service','table','field','reference_table','reference_id','source_id','legacy_id','employee_id','target_id','dependency','cause','period','overlapping_period','from','to','operation_id','snapshot_id','count','counts','reason','format','sqlstate','category','reconstructed','alias_of','target_table','target_matches','source_key'];
        $out = [];
        foreach ($context as $key=>$value) {
            if (!in_array((string) $key, $allowed, true)) continue;
            if (is_array($value)) $out[$key] = self::safeContext($value);
            elseif (is_scalar($value) || $value === null) $out[$key] = is_string($value) ? self::safeText($value) : $value;
        }
        return $out;
    }

    public static function safeText(?string $text): ?string
    {
        if ($text === null) return null;
        // Exception diagnostics may embed a DSN or encrypted source value. Keep useful reasons only.
        if (preg_match('/(SQLSTATE|password|secret|token|ciphertext|eyJpdiI|migration:v1:|postgres(?:ql)?:\/\/)/i', $text)) return 'Diagnostic details withheld; inspect protected server logs.';
        return preg_replace('/https?:\/\/\S+/i', '[url withheld]', $text);
    }

    public function export(int $runId, string $operation, int $inlineItemsLimit = PHP_INT_MAX): array
    {
        $request = \Illuminate\Http\Request::create('/', 'GET', ['operation_id'=>$operation]);
        if (!ConsoleRunIdentity::matches($request, $runId)) throw new \RuntimeException('Run does not belong to this operation; restored metadata cannot substitute an archived report.');
        $run = app(MigrationStore::class)->run($runId);
        if (!$run) throw new \RuntimeException('Run unavailable.');
        $inventory = $run['summary']['inventory'] ?? null;
        if ($inventory === null) {
            foreach (DB::table('migration_runs')->where('service',$run['service'])->where('mode','inspect')->where('id','<=',$runId)->orderByDesc('id')->cursor() as $inspect) {
                if (!ConsoleRunIdentity::matches($request, (int) $inspect->id)) continue;
                $inventory = json_decode($inspect->summary ?? '{}', true)['inventory'] ?? null;
                break;
            }
        }
        // Stream potentially large client diagnostics instead of materializing
        // a second full Eloquent/Collection copy before building the immutable
        // export. The output still retains all items and preserves source order.
        $items = []; $totalItems = 0; $groups = []; $groupRows = []; $affected = []; $rowOutcomes = []; $impacts = [];
        foreach (DB::table('migration_conflicts')->where('migration_run_id',$runId)->orderBy('id')->cursor() as $conflict) {
            $table = $run['service'] === 'clients' && $conflict->entity_type === 'default_projects' ? 'clients' : TableProgress::table($run['service'],$conflict->entity_type);
            $rowKey = $conflict->legacy_id === null ? null : $table.':'.$conflict->legacy_id;
            $message = self::safeText($conflict->message);
            $key = json_encode([$table,$conflict->code,$conflict->severity,$message]);
            $groups[$key] ??= ['service'=>$run['service'],'table'=>$table,'code'=>$conflict->code,'severity'=>$conflict->severity,'message'=>self::safeText($conflict->message),'count'=>0,'affected_rows'=>0,'examples'=>[]];
            $groups[$key]['count']++;
            // Root causes are indexed only for actual dependencies in a second pass.
            $context = json_decode($conflict->context ?? '{}',true) ?: [];
            if (isset($context['dependency']['table'],$context['dependency']['legacy_id'])) {
                $dependency = $context['dependency'];
                $root = ($dependency['service'] ?? $run['service']).':'.$dependency['table'].':'.$dependency['legacy_id'];
                $impacts[$root] ??= ['dependency_service'=>$dependency['service'] ?? $run['service'],'dependency_table'=>$dependency['table'],'dependency_legacy_id'=>$dependency['legacy_id'],'blocked_rows'=>[],'dependent_tables'=>[],'cause'=>self::safeText($dependency['cause'] ?? null)];
                if ($rowKey !== null) $impacts[$root]['blocked_rows'][$rowKey] = true;
                $impacts[$root]['dependent_tables'][$table] = true;
                $groups[$key]['dependency'] = self::safeContext($dependency);
            }
            if (count($groups[$key]['examples']) < 5) $groups[$key]['examples'][] = ['legacy_id'=>$conflict->legacy_id,'context'=>self::safeContext(json_decode($conflict->context ?? '{}',true) ?: [])];
            if ($rowKey !== null) {
                $affected[$rowKey] = true;
                $groupRows[$key][$rowKey] = true;
                $groups[$key]['affected_rows'] = count($groupRows[$key]);
                $outcome = $conflict->severity === 'error' ? 'failed_unique' : ($conflict->code === 'LEGACY_METADATA_ONLY' ? 'metadata_only' : (str_contains($conflict->code,'DEFERRED') ? 'deferred' : 'warnings_unique'));
                $rowOutcomes[$table][$outcome][(string) $conflict->legacy_id] = true;
                if ($conflict->code === 'REPORTING_PERIOD_ALIAS') $rowOutcomes[$table]['aliases'][(string)$conflict->legacy_id] = true;
            }
            $totalItems++;
            if (count($items) < $inlineItemsLimit) $items[] = ['id'=>(int)$conflict->id,'entity_type'=>$conflict->entity_type,'table'=>$table,'legacy_id'=>$conflict->legacy_id,'severity'=>$conflict->severity,'code'=>$conflict->code,'message'=>self::safeText($conflict->message),'context'=>self::safeContext(json_decode($conflict->context ?? '{}',true) ?: [])];
        }
        $rootGroups = [];
        if ($impacts) {
            foreach (DB::table('migration_conflicts')->where('migration_run_id',$runId)
                ->orderBy('id')->select(['entity_type','legacy_id','code','severity','message'])->cursor() as $conflict) {
                if ($conflict->legacy_id === null) continue;
                $table = $run['service'] === 'clients' && $conflict->entity_type === 'default_projects'
                    ? 'clients' : TableProgress::table($run['service'],$conflict->entity_type);
                $root = $run['service'].':'.$table.':'.$conflict->legacy_id;
                if (!isset($impacts[$root])) continue;
                $groupKey = json_encode([$table,$conflict->code,$conflict->severity,self::safeText($conflict->message)]);
                $rootGroups[$root][$groupKey] = true;
            }
        }
        foreach ($impacts as $root=>&$impact) {
            $impact['blocked_unique'] = count($impact['blocked_rows']);
            $impact['dependent_tables'] = array_keys($impact['dependent_tables']);
            $impact['root_groups'] = array_values(array_map(fn ($key) => array_intersect_key($groups[$key],array_flip(['service','table','code','severity','message','count','affected_rows'])),array_keys($rootGroups[$root] ?? [])));
            unset($impact['blocked_rows']);
        }
        unset($impact);
        $inventoryTables = [];
        foreach ($inventory['tables'] ?? [] as $inventoryTable) {
            $name = ($inventoryTable['schema'] ?? 'public') === 'public'
                ? ($inventoryTable['name'] ?? $inventoryTable['table_name'] ?? '')
                : ($inventoryTable['schema'].'.'.($inventoryTable['name'] ?? $inventoryTable['table_name'] ?? ''));
            if ($name !== '') $inventoryTables[$name] = $inventoryTable;
        }
        $unknownExpectation = [
            'status'=>'undocumented',
            'domain_write_expected'=>null,
            'decision_complete'=>false,
            'reason'=>'Решение о переносе таблицы не подтверждено доступной инвентаризацией.',
            'fates'=>[],
        ];
        $tables = [];
        foreach ($run['tables'] as $table) {
            $referenceTables = ['employees'=>[], 'vacations'=>['users'], 'clients'=>['users'], 'timesheets'=>['users','members','rates']];
            $reference = in_array($table['table_name'],$referenceTables[$run['service']] ?? [],true);
            $aliases = count($rowOutcomes[$table['table_name']]['aliases'] ?? []);
            $outcomes = ['domain_written'=>$reference ? 0 : max(0,(int)$table['success_count']-$aliases),'reference_matched'=>$reference ? (int)$table['success_count'] : $aliases];
            foreach (['metadata_only','deferred','failed_unique'] as $name) $outcomes[$name] = count($rowOutcomes[$table['table_name']][$name] ?? []);
            $outcomes['unprocessed'] = $table['total'] === null ? null : max(0,(int)$table['total']-(int)$table['processed_count']);
            $table['source_outcomes'] = $outcomes;
            $table['expectation'] = $inventoryTables[$table['table_name']]['expectation'] ?? $unknownExpectation;
            $tables[$table['table_name']] = $table;
        }
        // Every inventoried source table remains visible, even if the adapter never touched it.
        foreach ($inventory['tables'] ?? [] as $table) {
            $name = $table['schema'] === 'public' ? $table['name'] : $table['schema'].'.'.$table['name'];
            if (isset($tables[$name])) continue;
            $tables[$name] = ['table_name'=>$name,'total'=>$table['total'],'read_count'=>0,'processed_count'=>0,'success_count'=>0,'ready_count'=>0,'blocked_count'=>0,'error_count'=>0,'warning_count'=>0,'state'=>'unprocessed','expectation'=>$table['expectation'] ?? $unknownExpectation,'source_outcomes'=>['domain_written'=>0,'reference_matched'=>0,'metadata_only'=>0,'deferred'=>0,'failed_unique'=>0,'unprocessed'=>$table['total']]];
        }
        $events = [];
        foreach (DB::table('migration_run_events')->where('migration_run_id',$runId)->orderBy('id')->cursor() as $event) $events[] = ['id'=>(int)$event->id,'level'=>$event->level,'event'=>$event->event,'message'=>self::safeText($event->message),'context'=>self::safeContext(json_decode($event->context ?? '{}',true) ?: []),'created_at'=>$event->created_at];
        unset($run['events'],$run['conflicts'],$run['tables']);
        // Summary can contain adapter-private values; export structural counters and inventory separately.
        $summary = $run['summary'];
        unset($summary['inventory']);
        $run['summary'] = $this->safeSummary($summary ?? []);
        $run['error'] = self::safeText($run['error']);
        $completedBatch = null;
        $batchCount = 0;
        if ($run['service'] === 'timesheets') {
            $batchCount = DB::table('migration_batch_progress')
                ->where('migration_run_id', $runId)->where('table_name', 'timesheets')->count();
            $completedBatch = DB::table('migration_batch_progress')
                ->where('migration_run_id', $runId)->where('table_name', 'timesheets')
                ->orderByDesc('batch_number')->first(['batch_number','last_source_id','row_count','state']);
        }
        $chat = $run['service'].' / '.$run['mode'].' / '.$run['status'].'; diagnostic occurrences: '.$totalItems.'; affected source rows: '.count($affected).'.';
        if ($completedBatch) {
            $chat .= ' Completed source batches: '.$batchCount.'; last batch '.$completedBatch->batch_number
                .' (through source ID '.$completedBatch->last_source_id.').';
        }
        foreach (array_slice(array_values($groups),0,12) as $group) $chat .= "\n".$group['table'].': '.$group['code'].' ('.$group['severity'].') — '.$group['count'].' occurrences, '.$group['affected_rows'].' rows.';
        return ['schema_version'=>1,'implementation_revision'=>self::implementationRevision(),'generated_at'=>now()->toIso8601String(),'operation_id'=>$operation,'run_id'=>$runId,'service'=>$run['service'],'mode'=>$run['mode'],'status'=>$run['status'],'run'=>$run,'summary'=>$run['summary'],'inventory'=>$inventory,'tables'=>array_values($tables),'diagnostics'=>['occurrences'=>$totalItems,'items_total'=>$totalItems,'items_complete'=>count($items)===$totalItems,'affected_rows'=>count($affected),'groups'=>array_values($groups),'items'=>$items],'dependency_impacts'=>array_values($impacts),'root_cause_groups'=>array_values($groups),'events'=>$events,'batch_progress'=>['processed_batches'=>$batchCount, 'last'=> $completedBatch ? (array) $completedBatch : null], 'chat_summary'=>$chat,'counter_semantics'=>'Diagnostic occurrences may exceed unique affected rows. Domain writes, reference matches and metadata warnings overlap; do not sum them as distinct source rows.'];
    }

    /**
     * Enumerate every safe diagnostic as a cursor. The coordinator stores this
     * in a separate immutable line-delimited archive if inline rows were capped.
     */
    public function streamDiagnosticItems(int $runId, string $operation): \Generator
    {
        $request = \Illuminate\Http\Request::create('/', 'GET', ['operation_id'=>$operation]);
        if (!ConsoleRunIdentity::matches($request, $runId)) {
            throw new \RuntimeException('Run does not belong to this operation.');
        }
        $run = app(MigrationStore::class)->run($runId);
        if (!$run) throw new \RuntimeException('Run unavailable.');
        foreach (DB::table('migration_conflicts')->where('migration_run_id', $runId)
            ->orderBy('id')->select(['id','entity_type','legacy_id','severity','code','message','context'])->cursor() as $conflict) {
            $table = $run['service'] === 'clients' && $conflict->entity_type === 'default_projects'
                ? 'clients' : TableProgress::table($run['service'], $conflict->entity_type);
            yield ['id'=>(int)$conflict->id,'entity_type'=>$conflict->entity_type,
                'table'=>$table,'legacy_id'=>$conflict->legacy_id,'severity'=>$conflict->severity,
                'code'=>$conflict->code,'message'=>self::safeText($conflict->message),
                'context'=>self::safeContext(json_decode($conflict->context ?? '{}', true) ?: [])];
        }
    }

    private static function implementationRevision(): string
    {
        if ($revision = getenv('APP_REVISION') ?: getenv('GIT_SHA')) return $revision;
        $files = [];
        foreach ([app_path('Migration'),base_path('routes'),config_path()] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) if ($file->getExtension() === 'php') $files[] = $file->getPathname();
        }
        sort($files);
        return 'sha256:'.hash('sha256',implode('',array_map(fn ($path) => hash_file('sha256',$path),$files)));
    }

    /** Keep server aggregates and inventory, omit full diagnostic/event arrays from browser summaries. */
    public static function compact(array $report): array
    {
        foreach (['items','events','audit_snapshots','snapshots_history','raw','logs'] as $key) unset($report[$key]);
        foreach ($report as $key=>$value) if (is_array($value)) $report[$key] = self::compact($value);
        return $report;
    }

    private function safeSummary(array $summary): array
    {
        $out = [];
        foreach ($summary as $key=>$value) {
            if (is_array($value)) $out[$key] = $this->safeSummary($value);
            elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) $out[$key] = $value;
        }
        return $out;
    }
}
