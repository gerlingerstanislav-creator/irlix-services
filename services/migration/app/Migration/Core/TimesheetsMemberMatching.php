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

    /**
     * Resolve only an exact, unambiguous period match. Every legacy rate must
     * correspond to exactly one Clients member_term (inclusive dates and hours/day).
     * Overlap alone is unsafe: two project members can work on the same dates.
     *
     * All arguments are plain arrays to keep this decision independently testable.
     */
    public static function resolveByPeriods(array $legacyRates, array $candidates): array
    {
        $valid = count($legacyRates) > 0;
        $normalized = [];
        foreach ($legacyRates as $rate) {
            $from = (string) ($rate['from'] ?? '');
            $to = $rate['to'] ?? null;
            $hours = trim((string) ($rate['workload'] ?? ''));
            if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from)
                || ($to !== null && ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $to)))
                || ($to !== null && $to < $from)
                || !is_numeric($hours) || (float) $hours < 0 || (float) $hours > 24) {
                $valid = false;
                break;
            }
            $normalized[] = ['from' => $from, 'to' => $to, 'workload' => (float) $hours];
        }
        $evaluated = [];
        foreach ($candidates as $candidate) {
            $matched = 0;
            $termEvidence = [];
            foreach ($normalized as $rate) {
                $matchingTerms = array_filter($candidate['terms'] ?? [], static fn (array $term): bool =>
                    ($term['valid_from'] ?? null) === $rate['from']
                    && ($term['valid_to'] ?? null) === $rate['to']
                    && isset($term['hours_per_day'])
                    && abs((float) $term['hours_per_day'] - $rate['workload']) < 0.000001
                );
                $count = count($matchingTerms);
                if ($count === 1) ++$matched;
                $termEvidence[] = ['from' => $rate['from'], 'to' => $rate['to'],
                    'workload' => $rate['workload'], 'matching_term_ids' => array_values(array_map(
                        static fn (array $term) => (int) $term['id'], $matchingTerms))];
            }
            $candidate['period_matches'] = $valid && $matched === count($normalized);
            $candidate['matched_rates'] = $valid ? $matched : 0;
            $candidate['rate_count'] = count($legacyRates);
            $candidate['period_evidence'] = $termEvidence;
            $evaluated[] = $candidate;
        }
        $matches = $valid ? array_values(array_filter($evaluated,
            static fn (array $candidate): bool => $candidate['period_matches'])) : [];
        return ['periods_usable' => $valid, 'matching_ids' => array_values(array_map(
            static fn (array $candidate) => (int) $candidate['target_id'], $matches)),
            'candidates' => $evaluated];
    }

    /** Keep Clients terms attached to the real member ID, never to a name. */
    public static function withTerms(array $candidates): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $candidate) => (int) $candidate['target_id'], $candidates)));
        if (!$ids) return $candidates;
        $rows = DB::connection('target_clients')->table('member_terms')
            ->whereIn('project_member_id', $ids)
            ->orderBy('valid_from')->orderBy('id')
            ->get(['id', 'project_member_id', 'valid_from', 'valid_to', 'hours_per_day']);
        $terms = [];
        foreach ($rows as $row) {
            $terms[(int) $row->project_member_id][] = [
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
            return mb_strtolower(trim((string) ($candidate['project_name'] ?? ''))) === $name
                || (!empty($candidate['is_default'])
                    && mb_strtolower(trim((string) ($candidate['client_name'] ?? ''))) === $name);
        }));
        $evaluation = self::resolveByPeriods($rates, $byName);
        // One unambiguous name is sufficient only when the rate dictionary
        // is empty. With known periods the candidate must also pass the
        // downstream exact rate/term identity test.
        $selected = null;
        if (count($byName) === 1 && !$rates) $selected = (int) $byName[0]['target_id'];
        elseif ($evaluation['periods_usable'] && count($evaluation['matching_ids']) === 1) {
            $selected = $evaluation['matching_ids'][0];
        }
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
        $rateRows = $reader->select('SELECT id, member_id, "from", "to", workload '
            .'FROM public.rates WHERE member_id = ? ORDER BY id', [(int) $legacyId], false);
        $sourceRates = array_map(static fn ($rate): array => [
            'id' => (string) $rate->id, 'from' => $rate->from,
            'to' => $rate->to, 'workload' => (string) $rate->workload,
        ], $rateRows);
        $examples = [];
        if ($sourceRates) {
            $ids = array_column($sourceRates, 'id');
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $examples = array_map(static fn ($entry): array => [
                'legacy_entry_id' => (string) $entry->id,
                'legacy_rate_id' => (string) $entry->rate_id,
                'date' => (string) $entry->date, 'hours' => (string) $entry->hours,
            ], $reader->select('SELECT id, rate_id, date, hours FROM public.timesheets '
                .'WHERE rate_id IN ('.$placeholders.') ORDER BY date, id LIMIT 12', $ids, false));
        }
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
        $candidates = self::withTerms($candidates);
        $selection = self::choose($project, $sourceRates, $candidates);
        $periods = self::resolveByPeriods($sourceRates, $candidates);
        $candidates = $periods['candidates'];
        $diagnosis = self::diagnose($project, $candidates);
        if ($selection['selected_id'] !== null) {
            $diagnosis = ['code' => 'unique_verified', 'message' =>
                'Есть однозначное совпадение проекта и периодов в Clients member_terms; '
                .'подключение #'.$selection['selected_id'].' можно подтвердить автоматически при следующем Dry run.'];
        } elseif ($selection['name_count'] > 1) {
            $diagnosis = ['code' => 'ambiguous_periods', 'message' =>
                'Совпадают названия '. $selection['name_count'].' подключений, а полное совпадение '
                .'всех периодов и ставок обнаружено для '.$selection['period_count']
                .'. Без единственного точного соответствия автоматический выбор запрещён.'];
        } elseif ($selection['name_count'] === 1 && $sourceRates && $selection['period_count'] === 0) {
            $diagnosis = ['code' => 'period_mismatch', 'message' =>
                'Подключение по проекту найдено, но его периоды/часы в Clients не совпадают '
                .'с исходными rates. Сначала исправьте условия подключения.'];
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
                ['member' => $source, 'rates' => $sourceRates, 'candidates' => $candidates],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'source_rates' => $sourceRates,
            'source_entry_examples' => $examples,
            'candidates' => $candidates,
            'suggested_target_id' => $selection['selected_id'],
            'diagnosis' => $diagnosis,
        ];
    }
}
