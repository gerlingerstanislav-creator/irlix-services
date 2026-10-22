<?php

namespace App\Migration\Core;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Read-only identity proof and candidate enumeration for unresolved staff members.
 * It never guesses a mapping from similar names or legacy numeric identifiers.
 */
final class TimesheetsMemberMatching
{
    /** Classify matching evidence without selecting a target on the operator's behalf. */
    public static function diagnose(string $legacyProject, array $candidates): array
    {
        $project = mb_strtolower(trim($legacyProject));
        if (!$candidates) return ['code' => 'no_connection',
            'message' => 'В Clients нет ни одного подключения подтверждённого сотрудника.'];
        if ($project === '') return ['code' => 'missing_project',
            'message' => 'В источнике не указан проект. Требуется ручное подтверждение подключения.'];
        $matches = array_filter($candidates, static function (array $candidate) use ($project): bool {
            $name = mb_strtolower(trim((string) ($candidate['project_name'] ?? '')));
            $client = mb_strtolower(trim((string) ($candidate['client_name'] ?? '')));
            return $name === $project || (!empty($candidate['is_default']) && $client === $project);
        });
        if (count($matches) === 0) return ['code' => 'no_project_match',
            'message' => 'У сотрудника есть подключения, но нет точного совпадения исходного проекта. Требуется ручное сопоставление.'];
        if (count($matches) > 1) return ['code' => 'ambiguous',
            'message' => 'Найдено несколько подходящих подключений ('.count($matches).'). Требуется ручное подтверждение.'];
        return ['code' => 'exact_candidate',
            'message' => 'Одно точное совпадение найдено, но результат переноса ещё не подтверждён. Проверьте зависимости и повторите Dry run.'];
    }

    public static function inspect(string $legacyId): array
    {
        if (!ctype_digit($legacyId) || (int) $legacyId < 1) {
            throw new DomainException('Некорректный ID участника источника.');
        }
        $reader = new LegacyReader('timesheets');
        $reader->assertSafe();
        $rows = $reader->select('SELECT * FROM public.members WHERE id = ? LIMIT 1', [(int) $legacyId], false);
        $source = isset($rows[0]) ? (array) $rows[0] : null;
        if (!$source) throw new DomainException('Исходный member не найден.');
        if (($source['memberable_type'] ?? null) !== 'employee') {
            throw new DomainException('Внешние специалисты отложены; сопоставление доступно только корпоративным сотрудникам.');
        }
        $uuid = trim((string) ($source['employee_id'] ?? ''));
        if ($uuid === '') throw new DomainException('У member отсутствует корпоративный UUID сотрудника.');
        $sourceUsers = $reader->select('SELECT id FROM public.users WHERE employee_id = ?', [$uuid], false);
        $userIds = array_values(array_unique(array_map(
            fn ($item) => (string) ((array) $item)['id'], $sourceUsers
        )));
        if (!$userIds) throw new DomainException('Нет исходного пользователя для UUID; сначала устраните проблему переноса users.');
        $mappedEmployees = DB::table('migration_mappings')
            ->where('service', 'timesheets')->where('entity_type', 'users')
            ->whereIn('legacy_id', $userIds)->pluck('target_id')->unique()->values();
        if ($mappedEmployees->count() !== 1) {
            throw new DomainException('UUID не соответствует ровно одному Employees. Сначала сопоставьте пользователя.');
        }
        $employeeId = (int) $mappedEmployees->first();
        $employee = DB::connection('target_employees')->table('employees')->find($employeeId);
        if (!$employee) throw new DomainException('Целевой сотрудник отсутствует в Employees.');
        $project = trim((string) ($source['project'] ?? ''));
        $candidates = DB::connection('target_clients')->table('project_members as pm')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->join('clients as c', 'c.id', '=', 'p.client_id')
            ->where('pm.specialist_id', $employeeId)
            ->orderBy('c.name')->orderBy('p.name')->orderBy('pm.id')
            ->get(['pm.id as target_id', 'p.id as project_id', 'p.name as project_name',
                'p.is_default', 'c.id as client_id', 'c.name as client_name'])
            ->map(static function ($row) use ($project): array {
                $name = trim((string) ($row->project_name ?: $row->client_name));
                return [
                    'target_id' => (int) $row->target_id,
                    'project_id' => (int) $row->project_id,
                    'project_name' => $name,
                    'is_default' => (bool) $row->is_default,
                    'client_id' => (int) $row->client_id,
                    'client_name' => (string) $row->client_name,
                    'name_matches' => $project !== '' && mb_strtolower($name) === mb_strtolower($project),
                ];
            })->all();
        ksort($source);
        return [
            'source' => [
                'legacy_id' => $legacyId,
                'project' => $project,
                'memberable_type' => $source['memberable_type'],
                'employee_id' => $employeeId,
                'employee_name' => (string) ($employee->full_name ?? ''),
            ],
            'source_fingerprint' => hash('sha256', json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'candidates' => $candidates,
            'diagnosis' => self::diagnose($project, $candidates),
        ];
    }
}
