<?php
namespace App\Migration\Core;

/** Catalog-derived exhaustive schema inventory, only invoked by opt-in control inspect. */
final class SourceInventory
{
    public function __construct(private readonly ?\Closure $readerFactory = null) {}

    private static function identifier(string $value): string { return '"'.str_replace('"', '""', $value).'"'; }

    public function capture(string $service): array
    {
        $reader = $this->readerFactory ? ($this->readerFactory)($service) : new LegacyReader($service);
        $reader->assertSafe();
        $inventory = ['service'=>$service,'generated_at'=>now()->toIso8601String(),'complete'=>true,'source_fingerprint'=>hash('sha256', json_encode(array_intersect_key(config('migration.legacy.'.$service.'.database',[]), array_flip(['host','port','database','username'])))),'consistency'=>'Per-query read-only observations; source can change during inspection.','tables'=>[]];
        $catalog = $reader->select(<<<'SQL'
SELECT n.nspname AS schema_name, c.relname AS table_name, a.attname AS column_name,
       format_type(a.atttypid, a.atttypmod) AS data_type, t.typtype AS type_kind, t.oid AS type_id,
       has_schema_privilege(current_user, n.oid, 'USAGE') AND has_table_privilege(current_user, c.oid, 'SELECT') AS readable
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
JOIN pg_type t ON t.oid = a.atttypid
WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f')
  AND n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND n.nspname NOT LIKE 'pg_toast%'
ORDER BY n.nspname, c.relname, a.attnum
SQL);
        $tables = [];
        foreach ($catalog as $column) $tables[$column->schema_name.'.'.$column->table_name][] = $column;
        foreach ($tables as $columns) {
            if (TableProgress::$activeRun !== null) {
                app(MigrationStore::class)->progress(TableProgress::$activeRun, 'inventory',
                    'Чтение источника: инвентаризация '.$columns[0]->schema_name.'.'.$columns[0]->table_name.'.');
            }
            $first = $columns[0];
            $readable = in_array($first->readable, [true,1,'1','t','true'], true);
            $table = ['schema'=>$first->schema_name,'name'=>$first->table_name,'readable'=>$readable,'total'=>null,'columns'=>[],'unavailable_reason'=>$readable ? null : 'SELECT or schema USAGE unavailable.'];
            $relation = self::identifier($first->schema_name).'.'.self::identifier($first->table_name);
            if ($readable) {
                try { $table['total'] = (int) $reader->selectOne('SELECT count(*) AS count FROM '.$relation)->count; }
                catch (\Throwable $e) { $table['unavailable_reason'] = 'Table count unavailable (read denied, timeout or source changed).'; $inventory['complete'] = false; }
            } else $inventory['complete'] = false;
            // Aggregate multiple column statistics per scan. The old per-column
            // COUNT query repeatedly scanned large Clients tables (activity_log,
            // attachments, attempts) and could consume the entire stage deadline.
            // A failed batch leaves unknown statistics explicit, without guessing 0.
            $statistics = [];
            if ($readable) {
                foreach (array_chunk($columns, 12) as $page => $batch) {
                    $expressions = [];
                    foreach ($batch as $index => $fieldColumn) {
                        $field = self::identifier($fieldColumn->column_name);
                        $alias = $page * 12 + $index;
                        $expressions[] = 'count(*) FILTER (WHERE '.$field.' IS NOT NULL AND '.$field."::text <> '') AS filled_".$alias;
                        $expressions[] = 'count(*) FILTER (WHERE '.$field.' IS NULL) AS null_'. $alias;
                    }
                    try {
                        $statRow = $reader->select('SELECT '.implode(', ', $expressions).' FROM '.$relation, [], false)[0] ?? null;
                        if ($statRow === null) throw new \RuntimeException('Statistics unavailable');
                        foreach ($batch as $index => $fieldColumn) {
                            $alias = $page * 12 + $index;
                            $statistics[$fieldColumn->column_name] = [
                                'filled' => (int) $statRow->{'filled_'.$alias},
                                'null_count' => (int) $statRow->{'null_'.$alias},
                            ];
                        }
                    } catch (\Throwable $e) {
                        $inventory['complete'] = false;
                    }
                }
            }
            foreach ($columns as $column) {
                $item = ['name'=>$column->column_name,'type'=>$column->data_type,'filled'=>null,'null_count'=>null,'values'=>null,'values_complete'=>false,'declared_values'=>null,'declared_values_complete'=>false,
                    'mapping'=>FieldMappingRegistry::resolve($service,$first->schema_name,$first->table_name,$column->column_name),'unavailable_reason'=>$table['unavailable_reason']];
                if ($column->type_kind === 'e') {
                    try {
                        $item['declared_values'] = array_map(fn ($r) => ['value'=>ControlReport::safeText($r->enumlabel),'interpretation'=>FieldMappingRegistry::value($service,$first->schema_name,$first->table_name,$column->column_name,$r->enumlabel)], $reader->select('SELECT enumlabel FROM pg_enum WHERE enumtypid = ? ORDER BY enumsortorder',[$column->type_id]));
                        $item['declared_values_complete'] = true;
                    } catch (\Throwable $e) { $item['unavailable_reason'] = 'Declared enum labels unavailable.'; $inventory['complete'] = false; }
                }
                if ($readable) {
                    $field = self::identifier($column->column_name);
                    try {
                        if (isset($statistics[$column->column_name])) {
                            $item['filled'] = $statistics[$column->column_name]['filled'];
                            $item['null_count'] = $statistics[$column->column_name]['null_count'];
                        } else {
                            $item['unavailable_reason'] = 'Column statistics unavailable (read denied, timeout or unsupported source type).';
                            $inventory['complete'] = false;
                        }
                        if ($column->type_kind === 'e' || preg_match('/(^|_)(status|state|type|role|grade|result|outcome)($|_)/i', $column->column_name)) {
                            $item['values'] = array_map(fn ($r) => ['value'=>ControlReport::safeText($r->value),'count'=>(int) $r->count,'interpretation'=>FieldMappingRegistry::value($service,$first->schema_name,$first->table_name,$column->column_name,$r->value)], $reader->select('SELECT '.$field.'::text AS value, count(*) AS count FROM '.$relation.' GROUP BY '.$field.'::text ORDER BY '.$field.'::text'));
                            $item['values_complete'] = true;
                        }
                    } catch (\Throwable $e) { $item['unavailable_reason'] = 'Column statistics unavailable (read denied, timeout or unsupported source type).'; $inventory['complete'] = false; }
                }
                $table['columns'][] = $item;
            }
            $table['expectation'] = FieldMappingRegistry::tableExpectation($service, $first->schema_name, $first->table_name, $table['columns']);
            $inventory['tables'][] = $table;
        }
        return $inventory;
    }
}
