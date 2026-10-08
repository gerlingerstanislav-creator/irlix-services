<?php

namespace App\Migration\Services;

use App\Migration\Core\SchemaMigration;
use App\Migration\Core\SourceRowConflict;
use Illuminate\Support\Facades\DB;

class ClientsMigration extends SchemaMigration
{
    public function key(): string { return 'clients'; }
    private function lookup(string $table, mixed $id, ?string $field = null): array
    {
        foreach ($this->source[$table] as $row) if ((string) $row['id'] === (string) $id) return $row;
        throw new SourceRowConflict('MISSING_REFERENCE', 'Не найдена запись в исходном справочнике '.$table.'.', ['field' => $field ?? $table, 'reference_table' => $table, 'reference_id' => $id]);
    }
    private function amount(mixed $value, string $field, float $max): float
    {
        if ($value === null || $value === '') throw new SourceRowConflict('MISSING_AMOUNT', 'Не заполнено числовое поле '.$field.'.', ['field' => $field]);
        if (!is_numeric($value)) throw new SourceRowConflict('NON_NUMERIC_AMOUNT', 'Поле '.$field.' не является числом; проверьте формат или шифрование в источнике.', ['field' => $field]);
        try { return $this->decimal($value, $max); }
        catch (\DomainException $e) { throw new SourceRowConflict('INVALID_AMOUNT', 'Поле '.$field.': '.$e->getMessage(), ['field' => $field]); }
    }
    private function closedOutcome(array $results): array
    {
        $failures = ['Запрос закрыт','Заведомо не подходил по уровню','Интервью: отрицательная ОС','Отказ специалиста',
            'CV: Недостаточно отраслевого опыта','Специалист уволился','Интервью: Положительная ОС без подключения',
            'CV: Нет ОС','Интервью: отменено','CV: Недостаточно ком. опыта','CV: Не пройдено',
            'CV: Недостаточно информации для положительного ответа','CV: Нет опыта в требующейся технологии',
            'Подключение не состоялось','Интервью: не прошел тестовое'];
        $reasons = []; $outcomes = [];
        foreach (array_unique(array_column($results, 'title')) as $title) {
            $key = mb_strtolower(trim($title));
            $match = array_search($key, array_map('mb_strtolower', $failures), true);
            if ($match !== false) { $outcomes['failure'] = true; $reasons[] = $failures[$match]; }
            elseif (in_array($key, ['закрыт: неудача','неудача'], true)) $outcomes['failure'] = true;
            elseif (in_array($key, ['закрыт: успех','успех'], true)) $outcomes['success'] = true;
            else throw new SourceRowConflict('UNKNOWN_ATTEMPT_RESULT', 'Неизвестный результат закрытой попытки: '.$title, ['field' => 'results.title']);
        }
        if (count($outcomes) !== 1) throw new SourceRowConflict('AMBIGUOUS_ATTEMPT_RESULT', 'Результат закрытой попытки отсутствует или противоречив.', ['field' => 'attempt_result']);
        return [isset($outcomes['success']) ? 'Закрыт: успех' : 'Закрыт: неудача', $reasons ? json_encode($reasons, JSON_UNESCAPED_UNICODE) : null];
    }
    protected function import(): void
    {
        $this->rows('users', function ($r, $id) { $this->map('users', $id, $this->employee($r, 'external_key'), $r); });
        $this->rows('clients', function ($r, $id) {
            $this->write('clients', $id, 'clients', [
                'name' => $this->need(trim($r['title']), 'Client title is required'), 'description' => $r['description'],
                'type' => $r['type'], 'sector' => $r['sector_id'] ? $this->lookup('sectors', $r['sector_id'])['name'] : null,
                'sales_employee_id' => $r['sales_manager_id'] ? $this->ref('users', $r['sales_manager_id']) : null,
                'account_employee_id' => $r['account_manager_id'] ? $this->ref('users', $r['account_manager_id']) : null,
                'act_approval_days' => (int) $this->number($r['approving_term'], 65535), 'payment_days' => (int) $this->number($r['payment_term'], 65535),
            ], $r);
        });
        $this->rows('projects', function ($r, $id) {
            $this->write('projects', $id, 'projects', ['client_id' => $this->ref('clients', $r['client_id']), 'name' => $r['title'], 'is_default' => false], $r);
        });
        $this->rows('members', function ($r, $id) {
            if ($r['memberable_type'] !== 'employee') throw new \DomainException('External subcontractor identity cannot be guessed from memberable_id');
            $employee = $this->ref('users', $r['memberable_id']);
            $user = $this->lookup('users', $r['memberable_id']);
            if ($r['project_id']) {
                $project = $this->ref('projects', $r['project_id']);
                if ($r['client_id'] && (string) $this->lookup('projects', $r['project_id'])['client_id'] !== (string) $r['client_id']) throw new \DomainException('Member client and project disagree');
            }
            else {
                $client = $this->ref('clients', $r['client_id']);
                $project = $this->ids['default_projects'][(string) $r['client_id']] ?? null;
                if (!$project) $project = $this->write('default_projects', (string) $r['client_id'], 'projects',
                    ['client_id' => $client, 'name' => null, 'is_default' => true], $r, ['client_id' => $client, 'is_default' => true]);
            }
            $this->write('members', $id, 'project_members', ['project_id' => $project, 'specialist_id' => $employee,
                'specialist_name' => trim(($user['surname'] ?? '').' '.$user['name'])], $r, ['project_id' => $project, 'specialist_id' => $employee]);
        });
        $this->rows('rates', function ($r, $id) {
            [$from, $to] = $this->dates($r['start_date'], $r['end_date']);
            $member = $this->ref('members', $r['member_id']);
            $technology = $this->lookup('technologies', $r['technology_id'], 'rates.technology_id');
            if ($r['grade'] === null || trim((string) $r['grade']) === '') throw new SourceRowConflict('MISSING_GRADE', 'Не заполнен грейд условий подключения.', ['field' => 'rates.grade']);
            $this->noOverlap('rates', $id, 'member_terms', ['project_member_id' => $member], 'valid_from', 'valid_to', $from, $to);
            $this->write('rates', $id, 'member_terms', ['project_member_id' => $member, 'technology' => $technology['title'],
                'level' => $r['grade'], 'hourly_rate' => $this->amount($r['rate'], 'rates.rate', 9999999999.99),
                'hours_per_day' => $this->amount($r['workload'], 'rates.workload', 24), 'valid_from' => $from, 'valid_to' => $to], $r);
        });
        $this->rows('leads', function ($r, $id) {
            $statuses = ['Новый лид','Первичный контакт','Уточнение потребностей','КП отправлено','Активные переговоры','Клиент в игноре','Сделка закрыта - Успех','Сделка закрыта - Отказ'];
            $this->write('leads', $id, 'leads', ['name' => $r['title'], 'source' => $r['source'],
                'responsible_employee_id' => $this->ref('users', $r['responsible_id']),
                'status' => $this->enum($r['status'], array_combine(array_map('mb_strtolower', $statuses), $statuses) + ['new' => 'Новый лид', 'initial' => 'Первичный контакт', 'clarification' => 'Уточнение потребностей', 'ignore' => 'Клиент в игноре', 'failed' => 'Сделка закрыта - Отказ'], 'leads.status'),
                'converted_client_id' => $r['client_id'] ? $this->ref('clients', $r['client_id']) : null], $r);
        });
        $this->rows('contacts', fn ($r, $id) => $this->write('contacts', $id, 'contact_people', ['full_name' => $r['name']], $r));
        foreach (['client_contact' => ['clients','client'], 'lead_contact' => ['leads','lead']] as $table => [$parent,$type]) {
            $this->rows($table, function ($r, $id) use ($parent,$type,$table) {
                $person = $this->ref('contacts', $r['contact_id']); $entity = $this->ref($parent, $r[$type.'_id']);
                $this->write($table, $id, 'contact_relations', ['contact_person_id' => $person, 'entity_type' => $type, 'entity_id' => $entity,
                    'relation_role' => $r['position'] ?? null, 'active' => true], $r,
                    ['contact_person_id' => $person, 'entity_type' => $type, 'entity_id' => $entity, 'active' => true]);
            });
        }
        $this->rows('legal_entities', function ($r, $id) {
            $this->write('legal_entities', $id, 'client_legal_entities', ['client_id' => $this->ref('clients', $r['client_id']),
                'name' => $r['title'], 'full_name' => $r['fullname'], 'inn' => $r['inn'], 'ogrn' => $r['ogrn'], 'kpp' => $r['kpp'],
                'registration_date' => $r['registration_date'], 'okpo' => $r['okpo'], 'oktmo' => $r['oktmo'], 'address' => $r['address']], $r);
        });
        $this->rows('client_requests', function ($r, $id) {
            [$from, $to] = $this->dates($r['date'], $r['expired_at']);
            $this->write('client_requests', $id, 'client_requests', ['client_id' => $this->ref('clients', $r['client_id']),
                'title' => $r['title'], 'description' => $r['description'], 'responsible_employee_id' => $this->ref('users', $r['responsible_id']),
                'request_date' => $from, 'deadline' => $to, 'lifetime_weeks' => max(1,min(4,(int) ceil((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days / 7))),
                'status' => $r['closed_at'] ? 'Закрыт' : 'Открыт'], $r);
        });
        $this->rows('positions', function ($r, $id) {
            $technology = $this->lookup('technologies', $r['technology_id']);
            $department = $r['department_id'] ? $this->store->mapping('employees', 'department', $r['department_id']) : null;
            if ($r['department_id'] && !$department) throw new \DomainException('Production direction is unresolved in Employees');
            if ($r['count'] < 1 || (int) $r['count'] != $r['count']) throw new \DomainException('Position quantity must be a positive integer');
            $this->write('positions', $id, 'positions', ['client_request_id' => $this->ref('client_requests', $r['client_request_id']),
                'technology' => $technology['title'], 'level' => $r['grade'], 'description' => $r['description'],
                'direction_department_id' => $department, 'quantity' => (int) $this->need($this->number($r['count'], 65535), 'Quantity must be positive'),
                'status' => $r['closed_at'] ? 'Закрыт' : $this->enum($r['status'], ['open' => 'Открыт', 'closed' => 'Закрыт', 'открыт' => 'Открыт', 'закрыт' => 'Закрыт'])], $r);
        });
        $this->rows('attempts', function ($r, $id) {
            // Closed attempts have no result column; the result relation is authoritative.
            $results = [];
            foreach ($this->source['attempt_result'] as $link) if ((string) $link['attempt_id'] === $id) $results[] = $this->lookup('results', $link['result_id']);
            $closed = $r['closed_at'] !== null;
            $reasons = null;
            if ($closed) [$status, $reasons] = $this->closedOutcome($results);
            else $status = $r['started_at'] ? 'Ожидает подключения' : ($r['interviewed_at'] ? 'Интервью пройдено' : ($r['cv_sent_at'] ? 'CV отправлено' : 'Новая'));
            $employee = $r['user_id'] ? $this->ref('users', $r['user_id']) : null;
            $name = $r['user_name'] ?: ($r['user_id'] ? trim(($this->lookup('users',$r['user_id'])['surname'] ?? '').' '.$this->lookup('users',$r['user_id'])['name']) : null);
            $this->write('attempts', $id, 'connection_attempts', ['position_id' => $this->ref('positions', $r['position_id']),
                'specialist_id' => $employee, 'specialist_name' => $this->need($name, 'Attempt specialist name is missing'),
                'is_external' => $employee === null, 'status' => $status, 'failure_reasons' => $reasons, 'description' => $r['result_comment'],
                'cv_sent_at' => $r['cv_sent_at'], 'connection_date' => $r['started_at'], 'closed_at' => $r['closed_at'],
                'control_date' => $r['control_date'], 'proposed_rate' => $r['rate'] === null ? null : $this->amount($r['rate'], 'attempts.rate', 9999999999.99)], $r);
        });
        $this->rows('reporting_periods', function ($r, $id) {
            [$from, $to] = $this->dates($r['from'], $r['to']); $client = $this->ref('clients', $r['client_id']);
            $this->need($to, 'Reporting period end is required');
            $this->noOverlap('reporting_periods', $id, 'reporting_periods', ['client_id' => $client], 'period_start', 'period_end', $from, $to);
            $status = $r['paid_at'] ? 'Счет оплачен' : ($r['act_approved_at'] ? 'Акт согласован' : ($r['act_approval_at'] ? 'Акт на согласовании' : ($r['approved_at'] ? 'ТШ согласованы' : ($r['approval_at'] ? 'ТШ на согласовании' : 'Новый'))));
            $this->write('reporting_periods', $id, 'reporting_periods', ['client_id' => $client, 'period_start' => $from, 'period_end' => $to,
                'status' => $status, 'confirmed_hours' => $r['approved_hours'] === null ? null : $this->decimal($r['approved_hours'], 9999999999.99),
                'timesheets_sent_at' => $r['approval_at'], 'timesheets_approved_at' => $r['approved_at'],
                'act_sent_at' => $r['act_approval_at'], 'act_approved_at' => $r['act_approved_at'], 'paid_at' => $r['paid_at']], $r,
                ['client_id' => $client, 'period_start' => $from, 'period_end' => $to]);
        });
        foreach (['contacts','technologies','sectors','grades','grade_rates','departments','results','attempt_result','reporting_period_rate','feedback','interviews','notes','reviews','subcontracts','attachments','legal_documents','activity_log','settings','client_technology','user_technology'] as $table) {
            $this->rows($table, fn ($r, $id) => $this->preserve($table, $r, $id, 'Original auxiliary attributes retained in private metadata; not fabricated into new domain history or downloaded files.'));
        }
    }
}
