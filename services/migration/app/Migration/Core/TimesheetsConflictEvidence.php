<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

/**
 * Private operator-only read-through of a recorded Timesheets conflict.
 * Historical diagnostic is immutable; source and candidate snapshots here
 * are CURRENT data (not claimed to be the state at the failed run).
 */
final class TimesheetsConflictEvidence
{
    private static function row(LegacyReader $reader, string $table, string $fields, string $id): ?array
    {
        if (!ctype_digit($id)) return null;
        $found = $reader->select("SELECT {$fields} FROM public.{$table} WHERE id = ? LIMIT 1", [(int) $id], false);
        return isset($found[0]) ? (array) $found[0] : null;
    }

    public static function withinPeriod(string $date, ?string $from, ?string $to): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ||
            !$from || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from)) return false;
        return $date >= $from && ($to === null || $date <= $to);
    }

    public static function inspect(object $conflict): array
    {
        $entity = (string) $conflict->entity_type;
        $id = (string) $conflict->legacy_id;
        if ($conflict->service !== 'timesheets' || !in_array($entity, ['timesheets','rates','members'], true)) {
            throw new \DomainException('Построчная сверка доступна для Timesheets, Rates и Members.');
        }
        if (!ctype_digit($id)) throw new \DomainException('Нет допустимого ID исходной записи.');

        $reader = new LegacyReader('timesheets');
        $reader->assertSafe();
        $record = $entity === 'timesheets'
            ? self::row($reader, 'timesheets', 'id, rate_id, date, hours, description, is_submitted', $id)
            : ($entity === 'rates'
                ? self::row($reader, 'rates', 'id, member_id, "from", "to", workload', $id)
                : self::row($reader, 'members', 'id, employee_id, project, memberable_type', $id));
        $out = [
            'conflict_id' => (int) $conflict->id, 'source_type' => $entity,
            'source_id' => $id, 'code' => (string) $conflict->code,
            'error' => (string) $conflict->message,
            'basis' => 'current_read_only', 'source' => $record,
            'rate' => null, 'member' => null, 'employee' => null,
            'candidates' => [], 'checks' => [], 'suggested_member_id' => null,
            'action' => '', 'source_changed' => false,
        ];
        if ($record === null) {
            $out['action'] = 'Исходная запись больше не найдена: сравните снимок операции и текущий legacy-источник.';
            $out['source_changed'] = true;
            return $out;
        }
        $rate = $entity === 'timesheets'
            ? self::row($reader, 'rates', 'id, member_id, "from", "to", workload', (string) ($record['rate_id'] ?? ''))
            : ($entity === 'rates' ? $record : null);
        $member = $entity === 'members' ? $record : (
            $rate ? self::row($reader, 'members', 'id, employee_id, project, memberable_type',
                (string) ($rate['member_id'] ?? '')) : null
        );
        $out['rate'] = $rate;
        $out['member'] = $member;
        if ($entity === 'timesheets') {
            $date = substr((string) ($record['date'] ?? ''), 0, 10);
            $check = $rate && self::withinPeriod(
                $date, substr((string) ($rate['from'] ?? ''), 0, 10),
                $rate['to'] === null ? null : substr((string) $rate['to'], 0, 10)
            );
            $out['checks'][] = [
                'name' => 'Дата записи входит в исходную ставку',
                'ok' => (bool) $check,
                'detail' => $rate ? $date.' / '.(string)$rate['from'].' — '.(string)($rate['to'] ?? '∞')
                    : 'Исходная ставка не найдена',
            ];
        }
        if (!$member || ($member['memberable_type'] ?? '') !== 'employee') {
            $out['action'] = !$member
                ? 'Не найден исходный member для ставки. Проверьте внешние ключи legacy.'
                : 'Участник не является корпоративным сотрудником; проверьте правила отложенного импорта.';
            return $out;
        }

        try {
            $match = TimesheetsMemberMatching::inspect((string) $member['id']);
            $out['employee'] = $match['source'];
            $out['candidates'] = $match['candidates'];
            $out['suggested_member_id'] = $match['suggested_target_id'];
            $out['member_diagnosis'] = $match['diagnosis'];
            $out['legacy_rates'] = $match['source_rates'];
        } catch (\DomainException $e) {
            $out['action'] = 'Проверьте идентичность сотрудника и связь users → Employees: '.$e->getMessage();
            return $out;
        }

        $mappedMember = DB::table('migration_mappings')->where('service', 'timesheets')
            ->where('entity_type', 'members')->where('legacy_id', (string) $member['id'])->value('target_id');
        $override = DB::table('migration_overrides')->where('service', 'timesheets')
            ->where('entity_type', 'members')->where('legacy_id', (string) $member['id'])->value('target_id');
        $mappedRate = $rate ? DB::table('migration_mappings')->where('service', 'timesheets')
            ->where('entity_type', 'rates')->where('legacy_id', (string) $rate['id'])->value('target_id') : null;
        $out['mapped_member_id'] = $mappedMember;
        $out['override_member_id'] = $override;
        $out['mapped_term_id'] = $mappedRate;
        if ($entity === 'timesheets') {
            $date = substr((string) ($record['date'] ?? ''), 0, 10);
            foreach ($out['candidates'] as &$candidate) {
                $candidate['day_matches'] = [];
                foreach ($candidate['terms'] ?? [] as $term) {
                    $ok = self::withinPeriod($date,
                        substr((string)$term['valid_from'],0,10),
                        $term['valid_to']===null ? null : substr((string)$term['valid_to'],0,10));
                    $candidate['day_matches'][] = ['term_id'=>$term['id'], 'ok'=>$ok];
                }
            }
            unset($candidate);
        }
        $out['action'] = match (true) {
            isset($out['checks'][0]) && !$out['checks'][0]['ok'] =>
                'Дата записи часов не входит в исходный период rates. Не меняйте дату автоматически: проверьте legacy-данные и согласуйте исключение.',
            !$out['candidates'] =>
                'Подключения для подтверждённого сотрудника отсутствуют в Clients. Проверьте проект и создайте недостающую связь.',
            $out['suggested_member_id'] === null =>
                'Нельзя выбрать единственное подключение на основе имеющихся правил. Сверьте проект, периоды и ставки кандидатов.',
            default =>
                'Есть кандидат. Проверьте его периоды и отображённые сопоставления, затем повторите Dry run.',
        };
        return $out;
    }
}
