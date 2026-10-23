<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

/** Shared live identity, project and effective-term proof. Never writes mappings. */
final class TimesheetsResolution
{
    public static function employee(array $row, MigrationStore $store): int
    {
        // Timesheets numeric legacy user IDs have a different namespace
        // from Clients/Vacations. Resolve only confirmed corporate UUIDs
        // and persistent operator-approved login aliases.
        $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
        $explicit = str_contains($email, '@')
            ? EmployeeUserOverrides::resolve($row, 'timesheets') : null;
        $legacyUuid = trim((string) ($row['employee_id'] ?? ''));
        $employeesMapping = $legacyUuid !== ''
            ? $store->mapping('employees', 'employee', $legacyUuid) : null;
        $earlierMapping = EmployeeUserOverrides::previouslyImportedUuid($row);
        $ids = array_unique(array_map('intval', array_filter(
            [$explicit, $employeesMapping, $earlierMapping], fn ($id) => $id !== null)));
        if (count($ids) > 1) {
            throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                'UUID Employees, подтверждённый логин и сопоставления предыдущих сервисов указывают на разных сотрудников. Автоматическая перепривязка запрещена.',
                ['source_field'=>'users.employee_id/email', 'target_id_count'=>count($ids)]);
        }
        if ($ids) {
            $id = (int) reset($ids);
            if (!DB::connection('target_employees')->table('employees')->where('id', $id)->exists()) {
                throw new SourceRowConflict('MAPPING_TARGET_MISSING',
                    'Сохранённое сопоставление указывает на отсутствующего сотрудника Employees.');
            }
            // If the old email is now assigned to another active employee,
            // UUID and login signals disagree: do not silently take either.
            $oldLogin = str_contains($email, '@') ? strstr($email, '@', true) : '';
            if ($oldLogin !== '') {
                $other = DB::connection('target_employees')->table('employees')
                    ->whereRaw('LOWER(TRIM(login)) = ?', [$oldLogin])
                    ->where('id', '<>', $id)->exists();
                if ($other) throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                    'Исходный email теперь совпадает с логином другого сотрудника; требуется ручная сверка.');
            }
            $previous = isset($row['id']) ? $store->mapping('timesheets', 'users', (string)$row['id']) : null;
            if ($previous !== null && (int)$previous !== $id) throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                'Существующее сопоставление users указывает на другого сотрудника.');
            return $id;
        }

        $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
        $login = str_contains($email, '@') ? strstr($email, '@', true) : '';
        $ids = $login === '' ? [] : DB::connection('target_employees')->table('employees')
            ->whereRaw('LOWER(TRIM(login)) = ?', [$login])->pluck('id')->all();
        if (count($ids) !== 1) throw new SourceRowConflict('EMPLOYEE_IDENTITY_UNRESOLVED',
            'users → Employees: корпоративный UUID/логин не определяет единственного сотрудника.');
        $id = (int) $ids[0];
        $previous = isset($row['id']) ? $store->mapping('timesheets', 'users', (string) $row['id']) : null;
        if ($previous !== null && (int) $previous !== $id) throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
            'Существующее сопоставление users указывает на другого сотрудника.');
        return $id;
    }

    public static function candidates(int $employee): array
    {
        $rows = DB::connection('target_clients')->table('project_members as pm')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->join('clients as c', 'c.id', '=', 'p.client_id')->where('pm.specialist_id', $employee)
            ->orderBy('pm.id')->get(['pm.id as target_id','p.id as project_id','p.name as project_name',
                'p.is_default','c.id as client_id','c.name as client_name']);
        return TimesheetsMemberMatching::withTerms(array_map(static fn ($r): array => [
            'target_id'=>(int)$r->target_id,'project_id'=>(int)$r->project_id,
            'project_name'=>(string)$r->project_name,'is_default'=>(bool)$r->is_default,
            'client_id'=>(int)$r->client_id,'client_name'=>(string)$r->client_name,
        ], $rows->all()));
    }

    public static function member(array $source, int $employee, array $rates, MigrationStore $store): array
    {
        $id = (string) $source['id'];
        $candidates = self::candidates($employee);
        $choice = TimesheetsMemberMatching::choose((string)($source['project'] ?? ''), $rates, $candidates);
        $override = $store->override('timesheets','members',$id);
        $previous = $store->mapping('timesheets','members',$id);
        $selected = $override ?? $previous ?? $choice['selected_id'];
        if ($selected === null) throw new SourceRowConflict('MEMBER_IDENTITY_UNRESOLVED',
            'members → Clients: проект и фактические даты не определяют единственное подключение; требуется явное сопоставление.');
        if ($previous !== null && (int)$previous !== (int)$selected) throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
            'Существующее сопоставление members указывает на другое подключение.');
        $found = array_values(array_filter($candidates, static fn ($c) => (int)$c['target_id'] === (int)$selected));
        if (count($found) !== 1) throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
            'Подключение отсутствует или принадлежит другому сотруднику.');
        $candidate = $found[0];
        if (count(array_filter($candidates, static fn ($c) => $c['project_id'] === $candidate['project_id'])) !== 1)
            throw new SourceRowConflict('MEMBER_IDENTITY_UNRESOLVED', 'Для сотрудника и проекта найдено несколько project_members.');
        if ($previous !== null) {
            $metadata = DB::table('migration_mappings')->where('service','timesheets')->where('entity_type','members')
                ->where('legacy_id',$id)->value('metadata');
            $lineage = json_decode($metadata ?? '{}',true)['resolution'] ?? null;
            if ($lineage !== null && ((int)($lineage['employee_id'] ?? 0) !== $employee
                || (int)($lineage['project_id'] ?? 0) !== $candidate['project_id']
                || (int)($lineage['project_member_id'] ?? 0) !== $candidate['target_id']))
                throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION','Сохранённая lineage member отличается от текущего employee/project.');
        }
        return $candidate + ['employee_id'=>$employee];
    }

    public static function termForDate(array $terms, string $date): array
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new SourceRowConflict('INVALID_FACT_DATE','Некорректная фактическая дата.');
        $matches = array_values(array_filter($terms, static fn ($term) =>
            TimesheetsConflictEvidence::withinPeriod($date, $term['valid_from'], $term['valid_to'])));
        if (count($matches) !== 1) throw new SourceRowConflict(count($matches) ? 'TARGET_TERM_OVERLAP' : 'TARGET_TERM_GAP',
            'На фактическую дату '.$date.' требуется ровно одно условие выбранного подключения Clients (найдено '.count($matches).').',
            ['date'=>$date,'matching_term_ids'=>array_column($matches,'id')]);
        return $matches[0];
    }

    public static function rate(array $source, array $member, array $dates, MigrationStore $store): array
    {
        $used = [];
        foreach ($dates as $date) {
            $term = self::termForDate($member['terms'], $date);
            $used[(int)$term['id']] = $term;
        }
        ksort($used, SORT_NUMERIC);
        $previous = $store->mapping('timesheets','rates',(string)$source['id']);
        $all = array_column($member['terms'], null, 'id');
        if ($previous !== null && !isset($all[(int)$previous])) throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
            'Существующий rate anchor не принадлежит подтверждённому подключению.');
        // A rate without facts is provenance only; choose an existing member term deterministically.
        $anchorTerms = $used ?: $all;
        ksort($anchorTerms, SORT_NUMERIC);
        $anchor = $previous !== null ? (int)$previous : (int)(array_key_first($anchorTerms) ?? 0);
        if (!$anchor) throw new SourceRowConflict('TARGET_TERM_GAP', 'У подтверждённого подключения отсутствуют условия Clients.');
        return ['version'=>2,'employee_id'=>$member['employee_id'],'project_id'=>$member['project_id'],
            'project_member_id'=>$member['target_id'],'term_ids'=>array_keys($used),'terms'=>array_values($used),
            'anchor_id'=>$anchor];
    }
}
