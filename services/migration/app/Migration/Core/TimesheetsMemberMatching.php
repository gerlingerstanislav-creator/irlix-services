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
            $client = mb_strtolower(trim((string) ($candidate['client_name'] ?? '')));
            $projects = array_map(static fn ($name) => mb_strtolower(trim((string) $name)), $candidate['project_names'] ?? []);
            return $client === $project || in_array($project, $projects, true);
        });
        if (count($matches) === 0) return ['code' => 'no_project_match',
            'message' => 'У сотрудника есть подключения, но нет точного совпадения исходного проекта. Требуется ручное сопоставление.'];
        if (count($matches) > 1) return ['code' => 'ambiguous',
            'message' => 'Найдено несколько подходящих подключений ('.count($matches).'). Требуется ручное подтверждение.'];
        return ['code' => 'exact_candidate',
            'message' => 'Одно точное совпадение найдено, но результат переноса ещё не подтверждён. Проверьте зависимости и повторите Dry run.'];
    }

    /**
     * Enumerate unique effective terms on every actual fact date. Empty portions
     * of a legacy rate range and differing daily workloads do not reject facts.
     *
     * All arguments are plain arrays to keep this decision independently testable.
     */
    public static function resolveByPeriods(array $legacyRates, array $candidates): array
    {
        $evaluated = [];
        $hasFacts = false;
        foreach ($legacyRates as $rate) if (!empty($rate['fact_dates'])) $hasFacts = true;
        foreach ($candidates as $candidate) {
            $matched = 0; $evidence = [];
            foreach ($legacyRates as $rate) {
                $termIds = []; $dateTerms = []; $ok = true;
                foreach ($rate['fact_dates'] ?? [] as $date) {
                    try { $term = TimesheetsResolution::termForDate($candidate['terms'] ?? [], $date); $termIds[] = (int)$term['id']; $dateTerms[] = ['date'=>$date,'term_id'=>(int)$term['id']]; }
                    catch (\DomainException $e) { $ok = false; }
                }
                if ($ok) ++$matched;
                $evidence[] = ['from'=>$rate['from'] ?? null,'to'=>$rate['to'] ?? null,
                    'workload'=>$rate['workload'] ?? null,'fact_dates'=>$rate['fact_dates'] ?? [],
                    'matching_term_ids'=>array_values(array_unique($termIds)), 'date_term_matches'=>$dateTerms];
            }
            $candidate['period_matches'] = $matched === count($legacyRates);
            $candidate['matched_rates'] = $matched;
            $candidate['rate_count'] = count($legacyRates);
            $candidate['period_evidence'] = $evidence;
            $evaluated[] = $candidate;
        }
        $matches = array_values(array_filter($evaluated, static fn ($c) => $c['period_matches']));
        return ['periods_usable'=>$hasFacts,'matching_ids'=>array_column($matches,'target_id'),'candidates'=>$evaluated];
    }

    /** Keep Clients terms attached to the real member ID, never to a name. */
    public static function withTerms(array $candidates): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $candidate) => (int) $candidate['target_id'], $candidates)));
        if (!$ids) return $candidates;
        $rows = DB::connection('target_clients')->table('member_terms')
            ->whereIn('client_member_id', $ids)
            ->orderBy('valid_from')->orderBy('id')
            ->get(['id', 'client_member_id', 'valid_from', 'valid_to', 'hours_per_day']);
        $terms = [];
        foreach ($rows as $row) {
            $terms[(int) $row->client_member_id][] = [
                'id' => (int) $row->id, 'valid_from' => (string) $row->valid_from,
                'valid_to' => $row->valid_to === null ? null : (string) $row->valid_to,
                'hours_per_day' => (string) $row->hours_per_day,
            ];
        }
        foreach ($candidates as &$candidate) {
            $candidate['terms'] = $terms[(int) $candidate['target_id']] ?? [];
        }
        unset($candidate);
        return $candidates;
    }

    public static function choose(string $legacyProject, array $rates, array $candidates): array
    {
        $name = mb_strtolower(trim($legacyProject));
        $byName = array_values(array_filter($candidates, static function (array $candidate) use ($name): bool {
            if ($name === '') return false;
            $projects = array_map(static fn ($project) => mb_strtolower(trim((string) $project)), $candidate['project_names'] ?? []);
            return mb_strtolower(trim((string) ($candidate['client_name'] ?? ''))) === $name
                || in_array($name, $projects, true);
        }));
        $evaluation = self::resolveByPeriods($rates, $byName);
        $selected = null;
        if (count($byName) === 1) $selected = (int)$byName[0]['target_id'];
        elseif ($evaluation['periods_usable'] && count($evaluation['matching_ids']) === 1)
            $selected = (int)$evaluation['matching_ids'][0];
        return ['selected_id' => $selected, 'name_count' => count($byName),
            'period_count' => count($evaluation['matching_ids']),
            'periods_usable' => $evaluation['periods_usable'],
            'evaluated' => $evaluation['candidates']];
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
        $sourceUsers = $reader->select('SELECT * FROM public.users WHERE employee_id = ?', [$uuid], false);
        $store = new MigrationStore();
        $employees = [];
        foreach ($sourceUsers as $user) $employees[] = TimesheetsResolution::employee((array)$user,$store);
        $employees = array_values(array_unique($employees));
        if (count($employees) !== 1) throw new DomainException('users → Employees: UUID не определяет одного сотрудника.');
        $employeeId = $employees[0];
        $employee = DB::connection('target_employees')->table('employees')->find($employeeId);
        $project = trim((string) ($source['project'] ?? ''));
        $rateRows = $reader->select('SELECT id, member_id, "from", "to", workload '
            .'FROM public.rates WHERE member_id = ? ORDER BY id', [(int) $legacyId], false);
        $sourceRates = array_map(static fn ($rate): array => [
            'id' => (string) $rate->id, 'from' => $rate->from,
            'to' => $rate->to, 'workload' => (string) $rate->workload,
        ], $rateRows);
        $examples = []; $factDates = [];
        if ($sourceRates) {
            $ids = array_column($sourceRates,'id');
            $placeholders = implode(', ',array_fill(0,count($ids),'?'));
            foreach ($reader->select('SELECT DISTINCT rate_id, date FROM public.timesheets WHERE rate_id IN ('
                .$placeholders.') ORDER BY rate_id, date',$ids,false) as $entry)
                $factDates[(string)$entry->rate_id][(string)$entry->date] = true;
            foreach ($reader->select('SELECT id, rate_id, date, hours FROM public.timesheets WHERE rate_id IN ('
                .$placeholders.') ORDER BY date, id LIMIT 12',$ids,false) as $entry)
                $examples[] = ['legacy_entry_id'=>(string)$entry->id,
                    'legacy_rate_id'=>(string)$entry->rate_id,'date'=>(string)$entry->date,'hours'=>(string)$entry->hours];
        }
        foreach ($sourceRates as &$rate) $rate['fact_dates'] = array_keys($factDates[$rate['id']] ?? []);
        unset($rate);
        $candidates = TimesheetsResolution::candidates($employeeId);
        $periods = self::resolveByPeriods($sourceRates,$candidates);
        $candidates = $periods['candidates'];
        $selection = self::choose($project,$sourceRates,$candidates);
        $resolution = null; $rootCause = null;
        $rootTable = 'members'; $rootId = $legacyId;
        try {
            $member = TimesheetsResolution::member($source,$employeeId,$sourceRates,$store);
            $termIds = [];
            foreach ($sourceRates as $rate) {
                $rootTable = 'rates'; $rootId = (string)$rate['id'];
                $plan = TimesheetsResolution::rate($rate,$member,$rate['fact_dates'],$store);
                $termIds = array_merge($termIds,$plan['term_ids']);
            }
            $selection['selected_id'] = (int)$member['target_id'];
            $resolution = ['version'=>3,'employee_id'=>$employeeId,
                'client_member_id'=>$member['target_id'],'term_ids'=>array_values(array_unique($termIds))];
            $diagnosis = ['code'=>'unique_verified','message'=>'Подключение подтверждено; все фактические даты покрыты ровно одним условием Clients.'];
        } catch (DomainException $e) {
            $selection['selected_id'] = null;
            $diagnosis = ['code'=>$e instanceof SourceRowConflict ? $e->reason : 'unresolved','message'=>$e->getMessage()];
            $rootCause = ['table'=>$rootTable,'legacy_id'=>$rootId,'code'=>$diagnosis['code'],'message'=>$diagnosis['message']]
                + ($e instanceof SourceRowConflict ? $e->details : []);
        }
        ksort($source);
        return [
            'source' => [
                'legacy_id' => $legacyId,
                'project' => $project,
                'memberable_type' => $source['memberable_type'],
                'employee_id' => $employeeId,
                'employee_name' => (string) ($employee->full_name ?? ''),
            ],
            'source_fingerprint' => hash('sha256', json_encode(
                ['member' => $source, 'rates' => $sourceRates, 'candidates' => $candidates,
                    'employee_login'=>$employee->login ?? null,'resolution'=>$resolution,
                    'override'=>$store->override('timesheets','members',$legacyId),
                    'mapping'=>$store->mapping('timesheets','members',$legacyId)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'source_rates' => $sourceRates,
            'source_entry_examples' => $examples,
            'candidates' => $candidates,
            'suggested_target_id' => $selection['selected_id'],
            'diagnosis' => $diagnosis,
            'resolution' => $resolution, 'root_cause' => $rootCause,
        ];
    }
}
