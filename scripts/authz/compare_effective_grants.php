<?php

declare(strict_types=1);

/**
 * Read-only cutover guard for two complete, private *effective* access
 * snapshots (not the raw role assignments). Never prints subjects or grants.
 * A discrepancy, incomplete coverage, or invalid input blocks activation.
 */
function parseEffectiveSnapshot(mixed $snapshot): array
{
    if (!is_array($snapshot) || !array_is_list($snapshot)) {
        throw new InvalidArgumentException('Invalid snapshot');
    }
    $result = [];
    foreach ($snapshot as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Invalid row');
        foreach (['subject','service','permission','allowed','scope'] as $key) {
            if (!array_key_exists($key, $row)) throw new InvalidArgumentException('Missing field');
        }
        foreach (['subject','service','permission'] as $key) {
            if (!is_string($row[$key]) || trim($row[$key]) === '' || strlen($row[$key]) > 256) {
                throw new InvalidArgumentException('Invalid key');
            }
        }
        if (!is_bool($row['allowed']) || !in_array($row['scope'], ['none','own','team','all'], true)
            || ($row['allowed'] && $row['scope'] === 'none')
            || (!$row['allowed'] && $row['scope'] !== 'none')) {
            throw new InvalidArgumentException('Invalid permission or scope');
        }
        // Optional opaque resource identity makes object-level parity
        // possible. Do not put raw employee IDs or document names into a
        // GitHub artifact: compute stable keyed HMAC references inside the
        // protected read-only environment. Missing resource means aggregate
        // action permission, deliberately a DIFFERENT key from an object.
        $resource = '__aggregate__';
        if (array_key_exists('resource', $row)) {
            $resource = $row['resource'];
            if (!is_string($resource) || trim($resource) === '' || strlen($resource) > 256
                || $resource === '__aggregate__') {
                throw new InvalidArgumentException('Invalid opaque resource');
            }
        }
        $key = implode("\0", [$row['subject'], $row['service'], $row['permission'], $resource]);
        if (array_key_exists($key, $result)) throw new InvalidArgumentException('Duplicate effective entry');
        $result[$key] = [$row['allowed'], $row['scope']];
    }
    return $result;
}

function compareEffectiveGrants(mixed $legacy, mixed $candidate): array
{
    $before = parseEffectiveSnapshot($legacy);
    $after = parseEffectiveSnapshot($candidate);
    if (!$before || !$after) throw new InvalidArgumentException('Incomplete snapshots');
    $missing = 0; $unexpected = 0; $different = 0;
    foreach ($before as $key => $grant) {
        if (!array_key_exists($key, $after)) $missing++;
        elseif ($grant !== $after[$key]) $different++;
    }
    foreach ($after as $key => $grant) {
        if (!array_key_exists($key, $before)) $unexpected++;
    }
    return [
        'legacy_entries' => count($before),
        'candidate_entries' => count($after),
        'missing_entries' => $missing,
        'unexpected_entries' => $unexpected,
        'different_decisions' => $different,
        'parity' => $missing === 0 && $unexpected === 0 && $different === 0,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if ($argc !== 2 || !is_file($argv[1])) throw new InvalidArgumentException('Private snapshot path required');
        $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new InvalidArgumentException('Invalid payload');
        $report = compareEffectiveGrants($data['legacy'] ?? null, $data['candidate'] ?? null);
        echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
        exit($report['parity'] ? 0 : 2);
    } catch (Throwable) {
        fwrite(STDERR, "Authorization parity not established. Cutover blocked.\n");
        exit(1);
    }
}
