<?php

namespace App\Resources;

use PDO;

final class ResourceStore
{
    public function __construct(private readonly string $directory = '/resources') {}

    public function snapshot(): ?array
    {
        $path = $this->directory.'/current.json';
        if (!is_file($path)) return null;
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || !isset($data['collected_at'], $data['host'], $data['services'])) return null;
        $data['age_seconds'] = max(0, time() - (int) $data['collected_at']);
        $data['stale'] = $data['age_seconds'] > 45;
        return $data;
    }

    public function history(string $period, string $service): array
    {
        $windows = ['1h' => [3600, 60], '24h' => [86400, 300], '7d' => [604800, 1800]];
        if (!isset($windows[$period])) throw new \InvalidArgumentException('Недопустимый период');
        if (!preg_match('/^(?:__host__|[a-z0-9][a-z0-9-]{0,79})$/D', $service)) {
            throw new \InvalidArgumentException('Недопустимый сервис');
        }
        $path = $this->directory.'/history.sqlite';
        if (!is_file($path)) return [];
        // The collector owns the file. Platform Core has a read-only volume and SQLite connection.
        $db = new PDO('sqlite:file:'.$path.'?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');
        $db->exec('PRAGMA busy_timeout=3000');
        [$duration, $bucket] = $windows[$period];
        $statement = $db->prepare('SELECT CAST(ts / :bucket AS INTEGER) * :bucket AS ts, '
            .'AVG(memory) AS memory_bytes, MAX(memory) AS memory_peak, AVG(working) AS working_bytes, '
            .'AVG(cpu) AS cpu, MAX(cpu) AS cpu_peak FROM samples '
            .'WHERE service = :service AND ts >= :since GROUP BY CAST(ts / :bucket AS INTEGER) ORDER BY ts');
        $statement->bindValue(':bucket', $bucket, PDO::PARAM_INT);
        $statement->bindValue(':service', $service, PDO::PARAM_STR);
        $statement->bindValue(':since', time() - $duration, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
