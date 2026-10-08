<?php

namespace App\Migration\Core;

use App\Migration\Contracts\ServiceMigration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared mechanics only: each adapter owns the explicit domain transformations. */
abstract class SchemaMigration implements ServiceMigration
{
    protected array $source = [], $ids = [], $summary = [];
    protected int $runId;
    protected bool $dryRun;
    private int $virtualId = -1;
    private array $naturalKeys = [], $failedIds = [];
    private array $intervals = [];
    private bool $preflight = false;

    public function __construct(protected readonly MigrationStore $store) {}
    abstract protected function import(): void;

    protected function extract(): array
    {
        $reader = new LegacyReader($this->key());
        $reader->assertSafe();
        $rows = [];
        foreach (config('migration.legacy.'.$this->key().'.required_tables') as $table) {
            // Table names come exclusively from the application-owned registry, never requests.
            if (!preg_match('/^[a-z_]+$/D', $table)) throw new RuntimeException('Invalid source table');
            $reader->selectOne('SELECT count(*) AS count FROM public.'.$table);
            $rows[$table] = array_map(fn ($row) => (array) $row, $reader->select('SELECT * FROM public.'.$table));
        }
        return $rows;
    }

    public function inspect(int $runId): array
    {
        $rows = $this->extract();
        $enums = [];
        foreach (['vacations' => ['type','status'], 'leads' => ['status'], 'positions' => ['status','grade'], 'results' => ['title']] as $table => $fields) {
            foreach ($fields as $field) if (isset($rows[$table])) $enums[$table.'.'.$field] = array_values(array_unique(array_column($rows[$table], $field)));
        }
        return ['service' => $this->key(), 'counts' => array_map('count', $rows), 'legacy_values' => $enums,
            'notes' => ['Unknown enums, missing relations and invalid amounts are conflicts. Attachments and unsupported history remain in private migration metadata, never fetched from legacy URLs.']];
    }

    protected function assertCheckpoint(): void
    {
        [$code, $state] = app(MigrationOperationsClient::class)->request('GET', '/console/state');
        if (in_array($state['snapshot_operation']['state'] ?? '', ['queued', 'running'], true)) throw new RuntimeException('Checkpoint service is busy.', 409);
        if ($code !== 200 || !collect($state['snapshots'][$this->key()] ?? [])->contains(fn ($s) => ($s['compatible'] ?? true))
            || in_array($state['snapshot_operation']['state'] ?? '', ['queued', 'running'], true)) {
            throw new RuntimeException('Create an available service rollback point before import.');
        }
    }

    public function migrate(int $runId, bool $dryRun): array
    {
        $this->runId = $runId; $this->dryRun = $dryRun;
        $this->preflight = false;
        $this->resetPlan($dryRun);
        $this->source = $this->extract();
        if (!$dryRun) {
            $this->assertCheckpoint();
            // Enforce the same full preflight for direct API/CLI calls, not just the console.
            $this->dryRun = true; $this->preflight = true;
            $this->import();
            if ($this->summary['conflicts'] > 0) throw new RuntimeException('Import blocked by unresolved source rows; target data was not changed.');
            $this->dryRun = false; $this->preflight = false; $this->resetPlan(false);
        }
        $this->import();
        return $this->summary;
    }

    private function resetPlan(bool $dryRun): void
    {
        $this->ids = []; $this->virtualId = -1; $this->summary = ['mode' => $dryRun ? 'dry-run' : 'migrate', 'tables' => [], 'conflicts' => 0, 'warnings' => 0];
        $this->naturalKeys = []; $this->failedIds = [];
        $this->intervals = [];
    }

    protected function rows(string $table, callable $callback): void
    {
        foreach ($this->source[$table] ?? [] as $row) {
            $keys = config('migration.source_keys.'.$table);
            $id = (string) ($row['id'] ?? ($keys ? implode(':', array_map(fn ($key) => $row[$key], $keys)) : implode(':', array_values(array_filter($row, fn ($v) => is_scalar($v))))));
            try { $callback($row, $id); }
            catch (\DomainException $e) {
                $this->failedIds[$table][$id] = $e->getMessage();
                $this->store->conflict($this->runId, $this->key(), $table, $id, 'SOURCE_ROW_UNRESOLVED', $e->getMessage(), ['table' => $table, 'source' => array_intersect_key($row, array_flip(['id','user_id','employee_id','email','type','status','from','to','working_hours','name','mime','attachmentable_id','vacation_id','order']))]);
                $this->summary['conflicts']++;
            }
        }
    }

    protected function need(mixed $value, string $message): mixed
    {
        if ($value === null || $value === '' || $value === false || $value === []) throw new \DomainException($message);
        return $value;
    }

    protected function ref(string $table, mixed $id, ?string $service = null): int
    {
        $service ??= $this->key();
        if ($service === $this->key() && isset($this->failedIds[$table][(string) $id])) {
            $cause = $this->failedIds[$table][(string) $id];
            throw new \DomainException(($this->key() === 'vacations' && $table === 'users' ? 'Не определён сотрудник для отпуска (users.'.$id.'): ' : 'Referenced source row failed current preflight: '.$table.'.'.$id.'. ').$cause);
        }
        $value = $service === $this->key() ? ($this->ids[$table][(string) $id] ?? null) : null;
        $value ??= $this->store->mapping($service, $table, (string) $id);
        return (int) $this->need($value, "Unresolved {$service}.{$table} reference: {$id}");
    }

    protected function dates(mixed $from, mixed $to): array
    {
        foreach ([$from, $to] as $date) {
            if ($date !== null && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || !($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) || $parsed->format('Y-m-d') !== $date)) throw new \DomainException('Invalid calendar date');
        }
        $this->need($from, 'Start date is required');
        if ($to !== null && $from > $to) throw new \DomainException('End date precedes start date');
        return [$from, $to];
    }

    protected function number(mixed $value, float $max = 9999999999): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > $max) throw new \DomainException('Amount is invalid or encrypted; decrypted numeric export required');
        return (float) $value;
    }

    protected function decimal(mixed $value, float $max): float
    {
        $value = $this->number($value, $max);
        if (abs($value - round($value, 2)) > 0.000001) throw new \DomainException('Amount exceeds target decimal precision; refusing rounding');
        return $value;
    }

    protected function noOverlap(string $entity, string $id, string $table, array $owner, string $fromField, string $toField, string $from, ?string $to): void
    {
        $key = $table.':'.json_encode($owner);
        foreach ($this->intervals[$key] ?? [] as [$previousFrom,$previousTo,$previousId]) {
            if ($previousId !== $id && ($to === null || $previousFrom <= $to) && ($previousTo === null || $previousTo >= $from)) throw new \DomainException('Source periods overlap');
        }
        $mapped = $this->store->mapping($this->key(), $entity, $id);
        $query = DB::connection('target_'.$this->key())->table($table)->where($owner)
            ->where(fn ($q) => $q->whereNull($toField)->orWhereDate($toField, '>=', $from));
        if ($to !== null) $query->whereDate($fromField, '<=', $to);
        if ($mapped !== null) $query->where('id', '!=', (int) $mapped);
        if ($query->exists()) throw new \DomainException('Period overlaps another target row; explicit reconciliation required');
        $this->intervals[$key][] = [$from,$to,$id];
    }

    protected function enum(mixed $value, array $map, ?string $field = null): string
    {
        return $this->need($map[mb_strtolower(trim((string) $value))] ?? null, 'Unknown legacy enum'.($field ? ' ('.$field.')' : '').': '.(string) $value);
    }

    protected function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function employee(array $row, string $uuidField = 'employee_id'): int
    {
        if ($this->key() === 'vacations') {
            $override = EmployeeUserOverrides::resolve($row);
            if ($override !== null) return $override;
        }
        $email = mb_strtolower(trim($row['email'] ?? ''));
        $login = str_contains($email, '@') ? strstr($email, '@', true) : '';
        $this->need($login, 'Corporate email/login is missing');
        $matches = DB::connection('target_employees')->table('employees')->whereRaw('LOWER(TRIM(login)) = ?', [$login])->pluck('id');
        if ($matches->isEmpty()) throw new \DomainException('В Employees не найден сотрудник с логином: '.$login);
        if ($matches->count() > 1) throw new \DomainException('В Employees найдено '.$matches->count().' сотрудников с логином: '.$login.'; ID: '.$matches->implode(', '));
        $id = (int) $matches->first();
        if (!empty($row[$uuidField])) {
            $mapped = $this->store->mapping('employees', 'employee', $row[$uuidField]);
            if ($mapped !== null && (int) $mapped !== $id) throw new \DomainException('Login and legacy employee UUID disagree');
        }
        return $id;
    }

    protected function write(string $entity, string $legacyId, string $table, array $payload, array $row, array $unique = []): int
    {
        $target = DB::connection('target_'.$this->key());
        $mapped = $this->store->mapping($this->key(), $entity, $legacyId);
        $existing = $mapped === null ? null : $target->table($table)->find((int) $mapped);
        if ($mapped !== null && !$existing) throw new \DomainException('Mapping points to a missing target row; restore/reconcile metadata');
        if ($unique) {
            $key = $table.':'.json_encode($unique);
            if (isset($this->naturalKeys[$key]) && $this->naturalKeys[$key] !== $legacyId) throw new \DomainException('Duplicate source natural key; refusing overwrite or merge');
            $collision = $target->table($table)->where($unique)->first();
            if ($collision && (!$existing || $collision->id != $existing->id)) throw new \DomainException('Target natural key already exists without this legacy mapping; explicit reconciliation required');
            $this->naturalKeys[$key] = $legacyId;
        }
        if ($this->dryRun) $id = $existing ? (int) $existing->id : $this->virtualId--;
        else {
            $id = $target->transaction(function () use ($target, $table, $payload, $existing, $row): int {
                if ($existing) { $target->table($table)->where('id', $existing->id)->update($payload + ['updated_at' => $row['updated_at'] ?? now()]); return (int) $existing->id; }
                return (int) $target->table($table)->insertGetId($payload + ['created_at' => $row['created_at'] ?? now(), 'updated_at' => $row['updated_at'] ?? now()]);
            });
            $this->store->saveMapping($this->runId, $this->key(), $entity, $legacyId, $id, ['source' => $row, 'target_table' => $table, 'target_payload' => $payload]);
        }
        $this->ids[$entity][$legacyId] = $id;
        $this->summary['tables'][$entity] = ($this->summary['tables'][$entity] ?? 0) + 1;
        return $id;
    }

    protected function map(string $table, string $id, int $targetId, array $row): void
    {
        $this->ids[$table][$id] = $targetId;
        if (!$this->dryRun) $this->store->saveMapping($this->runId, $this->key(), $table, $id, $targetId, ['source' => $row]);
    }

    protected function preserve(string $table, array $row, string $id, string $reason): void
    {
        if ($this->preflight) return;
        // Preservation is explicitly a warning, never a successful domain import.
        $this->store->conflict($this->runId, $this->key(), $table, $id, 'LEGACY_METADATA_ONLY', $reason, ['source' => $row], 'warning');
        $this->summary['warnings']++;
    }

    public function validate(int $runId): array
    {
        $rows = $this->extract(); $result = []; $ok = true;
        foreach (config('migration.imported_tables.'.$this->key(), []) as $entity => $targetTable) {
            $sourceIds = array_map(fn ($row) => (string) $row['id'], $rows[$entity]);
            $mapped = DB::table('migration_mappings')->where('service', $this->key())->where('entity_type', $entity)->whereIn('legacy_id', $sourceIds)->get();
            $present = DB::connection('target_'.$this->key())->table($targetTable)->whereIn('id', $mapped->pluck('target_id'))->count();
            $changed = 0;
            $byId = array_column($rows[$entity], null, 'id');
            foreach ($mapped as $mapping) {
                $metadata = json_decode($mapping->metadata, true, 512, JSON_THROW_ON_ERROR);
                $target = DB::connection('target_'.$this->key())->table($targetTable)->find((int) $mapping->target_id);
                if (($metadata['source'] ?? null) != ($byId[$mapping->legacy_id] ?? null)) { $changed++; continue; }
                foreach ($metadata['target_payload'] ?? [] as $field => $expected) {
                    $actual = $target?->$field;
                    if ($expected === null ? $actual !== null : ($actual === null || (string) $actual !== (string) $expected)) {
                        // PostgreSQL decimal output has trailing zeros and booleans differ across drivers.
                        if ($actual !== null && is_numeric($actual) && is_numeric($expected) && abs((float) $actual - (float) $expected) < 0.000001) continue;
                        if (is_bool($expected) && filter_var($actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === $expected) continue;
                        $changed++; break;
                    }
                }
            }
            $result[$entity] = ['source' => count($sourceIds), 'mapped' => $mapped->count(), 'target_present' => $present, 'changed' => $changed];
            $ok = $ok && count($sourceIds) === $mapped->count() && $mapped->count() === $present && $changed === 0;
        }
        return ['ok' => $ok, 'tables' => $result];
    }
}
