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
            $first = $columns[0];
            $readable = in_array($first->readable, [true,1,'1','t','true'], true);
            $table = ['schema'=>$first->schema_name,'name'=>$first->table_name,'readable'=>$readable,'total'=>null,'columns'=>[],'unavailable_reason'=>$readable ? null : 'SELECT or schema USAGE unavailable.'];
            $relation = self::identifier($first->schema_name).'.'.self::identifier($first->table_name);
            if ($readable) {
                try { $table['total'] = (int) $reader->selectOne('SELECT count(*) AS count FROM '.$relation)->count; }
                catch (\Throwable $e) { $table['unavailable_reason'] = 'Table count unavailable (read denied, timeout or source changed).'; $inventory['complete'] = false; }
            } else $inventory['complete'] = false;
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
                        $counts = $reader->selectOne('SELECT count(*) FILTER (WHERE '.$field.' IS NOT NULL AND '.$field.'::text <> ?) AS filled, count(*) FILTER (WHERE '.$field.' IS NULL) AS null_count FROM '.$relation, ['']);
                        $item['filled'] = (int) $counts->filled; $item['null_count'] = (int) $counts->null_count;
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
