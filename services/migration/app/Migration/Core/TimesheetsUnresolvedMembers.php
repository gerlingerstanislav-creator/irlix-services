<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

/**
 * Admin-only, read-only live view. Nothing from this endpoint is included in
 * downloadable migration control summaries or immutable audit archives.
 */
final class TimesheetsUnresolvedMembers
{
    public static function isExternal(string $type): bool
    {
        return in_array(mb_strtolower(trim($type)), ['subcontract', 'contractor', 'external', 'partner'], true);
    }

    public static function fullName(array $row): ?string
    {
        foreach (['full_name', 'fio', 'specialist_name'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') return mb_substr($value, 0, 255);
        }
        $parts = array_filter(array_map(
            fn ($key) => trim((string) ($row[$key] ?? '')),
            ['surname', 'name', 'patronymic']
        ), fn ($part) => $part !== '');
        return $parts ? mb_substr(implode(' ', $parts), 0, 255) : null;
    }

    public static function page(int $runId, int $page = 1): array
    {
        $reader = new LegacyReader('timesheets');
        $reader->assertSafe();
        // members is a small dependency dictionary; the large fact table is
        // deliberately never read by this endpoint.
        $source = array_map(fn ($r) => (array) $r,
            $reader->select('SELECT * FROM public.members ORDER BY id', [], false));

        $mapped = array_fill_keys(DB::table('migration_mappings')
            ->where('service', 'timesheets')->where('entity_type', 'members')
            ->pluck('legacy_id')->map(fn ($id) => (string) $id)->all(), true);
        $missing = array_values(array_filter($source,
            fn ($row) => isset($row['id']) && !isset($mapped[(string) $row['id']])));
        $total = count($missing);
        $limit = 100;
        $offset = ($page - 1) * $limit;
        $slice = array_slice($missing, $offset, $limit);
        $ids = array_map(fn ($row) => (string) $row['id'], $slice);

        // Use only the requested run's evidence. A missing diagnostic is not
        // evidence of a successful import, especially for interrupted runs.
        $problems = DB::table('migration_conflicts')->where('migration_run_id', $runId)
            ->where('service', 'timesheets')->where('entity_type', 'members')
            ->whereIn('legacy_id', $ids)->orderByDesc('id')
            ->get(['legacy_id', 'code', 'message', 'severity']);
        $byId = [];
        foreach ($problems as $problem) $byId[(string) $problem->legacy_id] ??= $problem;

        // Staff names can be shown only after a confirmed legacy UUID -> user
        // mapping. Never join numeric member IDs across legacy databases.
        $uuidToUserIds = [];
        $uuidToNames = [];
        foreach ($reader->select('SELECT * FROM public.users', [], false) as $user) {
            $sourceUser = (array) $user;
            $uuid = trim((string) ($sourceUser['employee_id'] ?? ''));
            if ($uuid === '') continue;
            $uuidToUserIds[$uuid][] = (string) $sourceUser['id'];
            $name = self::fullName($sourceUser);
            if ($name !== null) $uuidToNames[$uuid][$name] = true;
        }
        $mappedUsers = DB::table('migration_mappings')
            ->where('service', 'timesheets')->where('entity_type', 'users')
            ->pluck('target_id', 'legacy_id')->all();
        $employeeIds = [];
        foreach ($slice as $row) {
            if (self::isExternal((string) ($row['memberable_type'] ?? ''))) continue;
            foreach ($uuidToUserIds[trim((string) ($row['employee_id'] ?? ''))] ?? [] as $userId) {
                if (isset($mappedUsers[$userId])) $employeeIds[(string) $mappedUsers[$userId]] = true;
            }
        }
        $names = $employeeIds ? DB::connection('target_employees')->table('employees')
            ->whereIn('id', array_keys($employeeIds))->pluck('full_name', 'id')->all() : [];

        $items = [];
        foreach ($slice as $row) {
            $type = (string) ($row['memberable_type'] ?? '');
            $external = self::isExternal($type);
            $linked = [];
            if (!$external) {
                foreach ($uuidToUserIds[trim((string) ($row['employee_id'] ?? ''))] ?? [] as $userId) {
                    if (isset($mappedUsers[$userId])) $linked[(string) $mappedUsers[$userId]] = true;
                }
            }
            $confirmedName = count($linked) === 1
                ? ($names[array_key_first($linked)] ?? null) : null;
            $legacyNameCandidates = $external ? [] : array_keys($uuidToNames[trim((string) ($row['employee_id'] ?? ''))] ?? []);
            $legacyName = count($legacyNameCandidates) === 1 ? $legacyNameCandidates[0] : null;
            $legacyId = (string) $row['id'];
            $problem = $byId[$legacyId] ?? null;
            $items[] = [
                'legacy_id' => $legacyId,
                'name' => self::fullName($row) ?? $confirmedName ?? $legacyName,
                'memberable_type' => $type,
                'memberable_id' => isset($row['memberable_id']) ? (string) $row['memberable_id'] : null,
                'project' => isset($row['project']) ? mb_substr((string) $row['project'], 0, 255) : null,
                'status' => $external ? 'deferred' : 'unresolved',
                'reason' => $problem ? (string) $problem->message
                    : ($external
                        ? 'Отложено до поддержки внешних специалистов в Timesheets'
                        : 'Не перенесён: требуется проверка сопоставления или повторный запуск'),
            ];
        }
        return ['items' => $items, 'total' => $total, 'page' => $page,
            'more' => $offset + count($slice) < $total, 'basis' => 'current',
            'note' => 'Текущее read-only состояние источника и mappings. Оно может отличаться от исторического отчёта.'];
    }
}
