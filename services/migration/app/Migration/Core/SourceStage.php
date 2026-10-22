<?php

namespace App\Migration\Core;

use RuntimeException;

/**
 * Immutable run-scoped, private source snapshot for a high-volume legacy table.
 * A single read-only pass creates the file; subsequent dry-run/write/validate
 * work uses the same bytes. Contains sensitive data: /data is a private volume,
 * directories are 0700, files 0600, and no stage content reaches operator APIs.
 */
final class SourceStage
{
    private string $dir;
    private string $data;
    private string $manifestPath;
    private ?array $manifest = null;

    public function __construct(
        private readonly string $service,
        private readonly string $table,
        private readonly int $runId,
    ) {
        if ($runId < 1 || !preg_match('/^[a-z_]+$/D', $service)
            || !preg_match('/^[a-z_]+$/D', $table)
            || !in_array($table, config('migration.legacy.'.$service.'.required_tables', []), true)) {
            throw new RuntimeException('Unapproved source stage identity.');
        }
        $root = (string) config('migration.source_stage_directory', '/data/migration-stages');
        $this->dir = rtrim($root, '/').'/'.$service.'/'.$runId;
        $this->data = $this->dir.'/'.$table.'.jsonl';
        $this->manifestPath = $this->dir.'/'.$table.'.manifest.json';
    }

    public function prepare(): void
    {
        if ($this->manifest !== null) return;
        if (is_file($this->manifestPath) || is_file($this->data)) {
            // A partially published stage is never trusted and is never
            // silently rebuilt over a possibly half-written migration.
            if (!is_file($this->data) || !is_file($this->manifestPath)
                || is_link($this->data) || is_link($this->manifestPath)) {
                throw new RuntimeException('Incomplete source stage; operator reconciliation required.');
            }
            $this->manifest = $this->verify();
            return;
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Unable to allocate private source stage.');
        }
        chmod($this->dir, 0700);
        $tmp = $this->dir.'/'.$this->table.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = fopen($tmp, 'xb');
        if ($handle === false) throw new RuntimeException('Cannot write private source stage.');
        chmod($tmp, 0600);
        $digest = hash_init('sha256');
        $count = 0;
        $firstId = null;
        $lastId = null;
        try {
            foreach ((new LegacyReader($this->service))->batches($this->table, 1000) as $page) {
                foreach ($page as $row) {
                    $id = (string) ($row['id'] ?? '');
                    $firstId ??= $id;
                    $lastId = $id;
                    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                    hash_update($digest, $line);
                    $length = strlen($line);
                    for ($offset = 0; $offset < $length; $offset += $written) {
                        $written = fwrite($handle, substr($line, $offset));
                        if ($written === false || $written === 0) throw new RuntimeException('Source stage write incomplete.');
                    }
                    $count++;
                }
            }
            fflush($handle);
            if (function_exists('fsync')) fsync($handle);
            fclose($handle);
            $handle = null;
            $manifest = ['schema'=>1, 'service'=>$this->service, 'table'=>$this->table,
                'run_id'=>$this->runId, 'count'=>$count, 'first_id'=>$firstId, 'last_id'=>$lastId,
                'sha256'=>hash_final($digest), 'bytes'=>filesize($tmp)];
            $manifestTmp = $tmp.'.manifest';
            file_put_contents($manifestTmp, json_encode($manifest, JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($manifestTmp, 0600);
            // Both files must be present and matching; an interrupted rename
            // fails closed on retry instead of silently accepting mixed data.
            if (!rename($tmp, $this->data) || !rename($manifestTmp, $this->manifestPath)) {
                throw new RuntimeException('Failed to publish private source stage.');
            }
            $this->manifest = $this->verify();
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($tmp)) unlink($tmp);
            if (isset($manifestTmp) && is_file($manifestTmp)) unlink($manifestTmp);
        }
    }

    /** @return \Generator<int, array<int, array<string, mixed>>> */
    public function pages(int $size = 1000): \Generator
    {
        if ($size < 1 || $size > 1000) throw new RuntimeException('Invalid source stage page size.');
        $this->prepare();
        $handle = fopen($this->data, 'rb');
        if ($handle === false) throw new RuntimeException('Cannot read source stage.');
        try {
            $page = [];
            $count = 0;
            while (($line = fgets($handle)) !== false) {
                if (strlen($line) > 1048576) throw new RuntimeException('Oversize staged source row.');
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($row) || !array_key_exists('id', $row)) {
                    throw new RuntimeException('Invalid staged source row.');
                }
                $page[] = $row;
                $count++;
                if (count($page) === $size) {
                    yield $page;
                    $page = [];
                }
            }
            if ($page) yield $page;
            if ($count !== $this->manifest['count']) {
                throw new RuntimeException('Source stage row count changed while reading.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** Remove sensitive staged input when its run has a terminal result. */
    public static function cleanup(string $service, int $runId, string $table = 'timesheets'): void
    {
        if ($runId < 1 || !preg_match('/^[a-z_]+$/D', $service)
            || !preg_match('/^[a-z_]+$/D', $table)) return;
        $root = (string) config('migration.source_stage_directory', '/data/migration-stages');
        $dir = rtrim($root,'/').'/'.$service.'/'.$runId;
        if (is_link($dir) || !is_dir($dir)) return;
        foreach ([$table.'.jsonl', $table.'.manifest.json'] as $file) {
            $path = $dir.'/'.$file;
            if (is_file($path) && !is_link($path)) @unlink($path);
        }
        // Best-effort cleanup of incomplete private staging files in this
        // run's own directory. Never traverse user-controlled paths.
        foreach (glob($dir.'/'.$table.'.*.tmp') ?: [] as $tmp) {
            if (is_file($tmp) && !is_link($tmp)) @unlink($tmp);
        }
        @rmdir($dir);
        @rmdir(dirname($dir));
    }

    public function count(): int
    {
        $this->prepare();
        return $this->manifest['count'];
    }

    private function verify(): array
    {
        $data = json_decode((string) file_get_contents($this->manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || $data['schema'] !== 1
            || $data['service'] !== $this->service || $data['table'] !== $this->table
            || $data['run_id'] !== $this->runId
            || !is_int($data['count']) || $data['count'] < 0
            || !preg_match('/^[0-9a-f]{64}$/D', (string) ($data['sha256'] ?? ''))
            || (int) filesize($this->data) !== $data['bytes']
            || !hash_equals($data['sha256'], (string) hash_file('sha256', $this->data))) {
            throw new RuntimeException('Source stage fingerprint mismatch; restart blocked.');
        }
        return $data;
    }
}
