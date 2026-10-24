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
        $clients = DB::connection('target_clients');
        $rows = $clients->table('client_members as cm')
            ->join('clients as c', 'c.id', '=', 'cm.client_id')->where('cm.employee_id', $employee)
            ->orderBy('cm.id')->get(['cm.id as target_id','cm.specialist_name','c.id as client_id','c.name as client_name']);
        $projects = $clients->table('projects')->whereIn('client_id', $rows->pluck('client_id'))
            ->whereNotNull('name')->get(['client_id','name'])->groupBy('client_id');
        return TimesheetsMemberMatching::withTerms(array_map(static fn ($r): array => [
            'target_id'=>(int)$r->target_id,
            'specialist_name'=>(string)$r->specialist_name,
            'project_names'=>($projects[$r->client_id] ?? collect())->pluck('name')->all(),
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
        if ($previous !== null) {
            $metadata = DB::table('migration_mappings')->where('service','timesheets')->where('entity_type','members')
                ->where('legacy_id',$id)->value('metadata');
            $lineage = json_decode($metadata ?? '{}',true)['resolution'] ?? null;
            if (($lineage['version'] ?? null) !== 3
                || (int)($lineage['employee_id'] ?? 0) !== $employee
                || (int)($lineage['client_member_id'] ?? 0) !== $candidate['target_id'])
                throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION','Сохранённая lineage member отличается от текущего employee/client.');
        }
        return $candidate + ['employee_id'=>$employee];
    }

    /**
     * Read-only proof across legacy databases. Numeric IDs are NEVER joined
     * between sources: resolve a source subcontract by personal-name evidence
     * plus legacy Clients' explicit project/client association, then follow
     * the already verified Clients migration mappings to target ClientMember.
     */
    private static function provenPartnerFromLegacy(array $source, MigrationStore $store): ?int
    {
        $reader = new LegacyReader('clients');
        try { $reader->assertSafe(); }
        catch (\RuntimeException $e) {
            // Unavailable read-only Clients evidence must never be treated as a match.
            // Explicit operator overrides remain functional in isolated fixtures.
            return null;
        }
        $normalize = static fn (string $value): string =>
            trim(preg_replace('/\\s+/u', ' ', mb_strtolower($value)) ?? '');
        $sourceName = $normalize((string)($source['name'] ?? ''));
        $sourceProject = $normalize((string)($source['project'] ?? ''));
        if ($sourceName === '' || $sourceProject === '') return null;
        $sourceParts = explode(' ', $sourceName);
        if (count($sourceParts) < 2) return null;
        $subcontracts = $reader->select('SELECT id,surname,name,patronymic FROM public.subcontracts', [], false);
        $clients = $reader->select('SELECT id,title FROM public.clients', [], false);
        $projects = $reader->select('SELECT id,title FROM public.projects', [], false);
        $clientNames = []; $projectNames = [];
        foreach ($clients as $row) $clientNames[(string)$row->id] = $normalize((string)$row->title);
        foreach ($projects as $row) $projectNames[(string)$row->id] = $normalize((string)$row->title);
        $candidates = [];
        foreach ($subcontracts as $person) {
            $full = $normalize(implode(' ', array_filter([(string)$person->surname,
                (string)$person->name, (string)$person->patronymic])));
            $parts = explode(' ', $full);
            if (count($parts) < 2 || $parts[0] !== $sourceParts[0]
                || mb_substr($parts[1], 0, 1) !== mb_substr($sourceParts[1], 0, 1)) continue;
            $legacySubcontractId = (string)$person->id;
            $targetPartner = $store->mapping('clients', 'subcontracts', $legacySubcontractId);
            if ($targetPartner === null) continue;
            $members = $reader->select(
                'SELECT id,client_id,project_id FROM public.members WHERE memberable_type = ? AND memberable_id = ?',
                ['subcontract', $person->id], false);
            foreach ($members as $member) {
                $isProject = $sourceProject === ($projectNames[(string)$member->project_id] ?? null);
                $isClient = $sourceProject === ($clientNames[(string)$member->client_id] ?? null);
                if (!$isProject && !$isClient) continue;
                $targetMember = $store->mapping('clients','members',(string)$member->id);
                if ($targetMember === null) continue;
                $valid = DB::connection('target_clients')->table('client_members')
                    ->where('id', (int)$targetMember)
                    ->where('partner_specialist_id',(int)$targetPartner)
                    ->exists();
                if ($valid) $candidates[(int)$targetMember] = true;
            }
        }
        // Fail closed on ambiguous or missing evidence. Human confirmation is
        // still possible through the explicit override.
        return count($candidates) === 1 ? (int)array_key_first($candidates) : null;
    }

    /** Legacy Timesheets has no stable partner identifier shared with Clients. */
    public static function partner(array $source, MigrationStore $store): array
    {
        $legacyId = (string) $source['id'];
        $previous = $store->mapping('timesheets', 'members', $legacyId);
        $selected = $store->override('timesheets', 'members', $legacyId)
            ?? $previous
            ?? self::provenPartnerFromLegacy($source, $store);
        if ($selected === null) throw new SourceRowConflict('PARTNER_IDENTITY_UNRESOLVED',
            'Для партнёрского участника не доказано единственное подключение Clients; требуется подтверждение.');
        if ($previous !== null && (int) $previous !== (int) $selected) throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
            'Сохранённое сопоставление партнёра отличается от подтверждённого оператором.');
        $row = DB::connection('target_clients')->table('client_members as cm')
            ->join('clients as c', 'c.id', '=', 'cm.client_id')
            ->where('cm.id', (int) $selected)->whereNotNull('cm.partner_specialist_id')
            ->first(['cm.id as target_id', 'cm.client_id', 'cm.partner_specialist_id', 'c.name as client_name']);
        if (!$row) throw new SourceRowConflict('PARTNER_IDENTITY_UNRESOLVED',
            'Выбранное подключение отсутствует или принадлежит штатному сотруднику.');
        $candidate = TimesheetsMemberMatching::withTerms([[
            'target_id'=>(int) $row->target_id, 'client_id'=>(int) $row->client_id,
            'partner_specialist_id'=>(int) $row->partner_specialist_id,
            'client_name'=>(string) $row->client_name, 'employee_id'=>null,
        ]])[0];
        if ($previous !== null) {
            $metadata = DB::table('migration_mappings')->where('service', 'timesheets')
                ->where('entity_type', 'members')->where('legacy_id', $legacyId)->value('metadata');
            $lineage = json_decode($metadata ?? '{}', true)['resolution'] ?? [];
            if (($lineage['version'] ?? null) !== 3
                || (int) ($lineage['client_member_id'] ?? 0) !== (int) $selected
                || (int) ($lineage['partner_specialist_id'] ?? 0) !== (int) $row->partner_specialist_id) {
                throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION', 'Сохранённая lineage партнёра отличается от подтверждённой.');
            }
        }
        return $candidate;
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
        $outOfTermDates = [];
        foreach ($dates as $date) {
            try { $term = self::termForDate($member['terms'], $date); $used[(int)$term['id']] = $term; }
            catch (SourceRowConflict $e) {
                if ($e->reason !== 'TARGET_TERM_GAP') throw $e;
                $outOfTermDates[] = $date;
            }
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
        return ['version'=>3,'employee_id'=>$member['employee_id'] ?? null,
            'partner_specialist_id'=>$member['partner_specialist_id'] ?? null,
            'client_member_id'=>$member['target_id'],
            'term_ids'=>array_keys($used),'terms'=>array_values($used),
            'historical_gap_dates'=>$outOfTermDates,
            'anchor_id'=>$anchor];
    }
}
