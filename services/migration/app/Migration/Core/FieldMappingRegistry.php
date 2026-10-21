<?php
namespace App\Migration\Core;

/** Reviewed adapter field rules. No inference from matching column names. */
final class FieldMappingRegistry
{
    public static function resolve(string $service, string $schema, string $table, string $field): array
    {
        $unknown = ['fate'=>'unmapped', 'target'=>null, 'rule'=>null, 'reason'=>'Для этого столбца нет проверенного правила переноса.'];
        if (in_array($field,['password','password_hash','remember_token','api_token','access_token','refresh_token','two_factor_secret','two_factor_recovery_codes'],true)) return ['fate'=>'excluded','target'=>null,'rule'=>null,'reason'=>'Учётные данные, пароли и токены не переносятся по правилам безопасности.'];
        if ($service === 'employees' && in_array($table,['salary','salaries','salary_history'],true)) return ['fate'=>'excluded','target'=>null,'rule'=>null,'reason'=>'Перенос зарплат исключён из согласованного объёма.'];
        if ($schema !== 'public') return $unknown;
        if ($service === 'clients' && in_array($table,['rates','attempts'],true) && $field === 'rate' && config('migration.clients_defer_rate_amounts')) return ['fate'=>'deferred','target'=>null,'rule'=>null,'reason'=>'Перенос денежных сумм отключён; существующие суммы в целевом сервисе сохраняются.'];
        $rules = self::rules()[$service][$table] ?? [];
        if (isset($rules[$field])) {
            [$fate, $target, $rule] = $rules[$field];
            return compact('fate', 'target', 'rule') + ['reason'=>null];
        }
        $metadata = [
            'vacations'=>['changes','comments','business_dates','activity_log','departments'],
            'timesheets'=>['month_confirmations','time_records','vacations','business_dates','departments'],
            'clients'=>['feedback','interviews','notes','reviews','attachments','legal_documents','activity_log','settings','client_technology','user_technology'],
        ];
        if (in_array($table, $metadata[$service] ?? [], true)) return ['fate'=>'metadata_only','target'=>null,'rule'=>'Только приватные метаданные переноса, без записи бизнес-сущности.','reason'=>'Таблица сохранена в метаданных; бизнес-действия по ней не восстанавливаются.'];
        if (($service === 'clients' && $table === 'reporting_period_rate') || ($service === 'clients' && in_array($table,['rates','attempts'],true) && $field === 'rate' && config('migration.clients_defer_rate_amounts')) || ($service === 'vacations' && $table === 'attachments' && !config('migration.vacation_documents_enabled', false))) {
            return ['fate'=>'deferred','target'=>null,'rule'=>null,'reason'=>'Отложено за пределами текущего переноса; требуется отдельное согласованное изменение.'];
        }
        return $unknown;
    }

    /**
     * Describe the reviewed table-level transfer intent from explicit field rules only.
     *
     * This is deliberately conservative: counters and observed row totals never turn into
     * a business rule. "expected" means that the adapter has reviewed domain mappings for
     * at least one field; it does not mean every source row must become a target row.
     */
    public static function tableExpectation(string $service, string $schema, string $table, array $columns): array
    {
        $counts = [];
        foreach ($columns as $column) {
            $mapping = $column['mapping'] ?? self::resolve($service, $schema, $table, (string) ($column['name'] ?? ''));
            $fate = $mapping['fate'] ?? 'unmapped';
            $counts[$fate] = ($counts[$fate] ?? 0) + 1;
        }

        $domain = array_sum(array_map(fn ($key) => $counts[$key] ?? 0, ['direct','transformed','reference']));
        $unmapped = $counts['unmapped'] ?? 0;
        if ($domain > 0) {
            return [
                'status'=>'expected',
                'domain_write_expected'=>true,
                'decision_complete'=>$unmapped === 0,
                'reason'=>$unmapped === 0
                    ? 'Для таблицы есть проверенные правила доменного переноса. Это не доказывает, что каждая исходная строка обязана стать отдельной целевой записью: строковые проверки, зависимости и конфликты учитываются отдельно.'
                    : 'Для части полей есть проверенные правила доменного переноса, но '.$unmapped.' полей остаются без утверждённого решения. Таблицу нельзя считать полностью описанной.',
                'fates'=>$counts,
            ];
        }

        if ($unmapped > 0 || !$columns) {
            return [
                'status'=>'undocumented',
                'domain_write_expected'=>null,
                'decision_complete'=>false,
                'reason'=>$unmapped > 0
                    ? 'Для таблицы нет полного утверждённого решения: '.$unmapped.' полей остаются неописанными. Нулевые счётчики не считаются доказательством исключения.'
                    : 'Решение о переносе этой таблицы не подтверждено инвентаризацией.',
                'fates'=>$counts,
            ];
        }

        $nonDomain = array_sum(array_map(fn ($key) => $counts[$key] ?? 0, ['metadata_only','deferred','excluded']));
        if ($nonDomain > 0) {
            $kinds = array_values(array_filter(['metadata_only','deferred','excluded'], fn ($key) => ($counts[$key] ?? 0) > 0));
            return [
                'status'=>'excluded',
                'domain_write_expected'=>false,
                'decision_complete'=>true,
                'reason'=>'Для таблицы нет проверенных правил записи доменной сущности; все описанные поля сознательно отнесены к: '.implode(', ', $kinds).'. Причины по каждому полю доступны в деталях.',
                'fates'=>$counts,
            ];
        }

        return [
            'status'=>'undocumented',
            'domain_write_expected'=>null,
            'decision_complete'=>false,
            'reason'=>'Решение о переносе этой таблицы не описано.',
            'fates'=>$counts,
        ];
    }

    public static function value(string $service, string $schema, string $table, string $field, ?string $value): array
    {
        $key = mb_strtolower(trim((string)$value));
        $map = null;
        if ($schema === 'public') {
            if ($service === 'vacations' && $table === 'vacations' && in_array($field,['status','type'],true)) $map = config('migration.vacation_'.($field === 'status' ? 'statuses' : 'types'),[]);
            if ($service === 'employees' && $table === 'employees' && $field === 'status') {
                $map = array_fill_keys(['dismissed','fired','terminated','inactive','уволен','увольнение'],'Уволен')
                    + array_fill_keys(['pending','waiting','new','not_hired','candidate','ожидает трудоустройства'],'Ожидает трудоустройства')
                    + array_fill_keys(['active','employed','hired','working','трудоустроен','работает'],'Трудоустроен');
            }
            if ($service === 'clients' && $table === 'leads' && $field === 'status') {
                $labels = ['Новый лид','Первичный контакт','Уточнение потребностей','КП отправлено','Активные переговоры','Клиент в игноре','Сделка закрыта - Успех','Сделка закрыта - Отказ'];
                $map = array_combine(array_map('mb_strtolower',$labels),$labels) + ['new'=>'Новый лид','initial'=>'Первичный контакт','clarification'=>'Уточнение потребностей','ignore'=>'Клиент в игноре','failed'=>'Сделка закрыта - Отказ','active'=>'Активные переговоры','proposal_sent'=>'КП отправлено','success'=>'Сделка закрыта - Успех'];
            }
            if ($service === 'clients' && $table === 'attempts' && $field === 'status') {
                $terminal = array_fill_keys(['success','успех','закрыт: успех'],'Закрыт: успех') + array_fill_keys(['failure','failed','fail','неудача','неуспех','закрыт: неудача','закрыт: неуспех'],'Закрыт: неудача');
                if (isset($terminal[$key])) return ['status'=>'known','known'=>true,'target'=>$terminal[$key],'rule'=>'Явный конечный исход попытки. Другие значения требуют проверки дат и результатов строки.'];
            }
            if ($service === 'clients' && $table === 'positions' && $field === 'status') $map = ['open'=>'Открыт','closed'=>'Закрыт','открыт'=>'Открыт','закрыт'=>'Закрыт','under_consideration'=>'Открыт','waiting'=>'Открыт','no_candidates'=>'Открыт'];
        }
        if ($map !== null) return ['status'=>array_key_exists($key,$map) ? 'known' : 'unknown','known'=>array_key_exists($key,$map),'target'=>$map[$key] ?? null,'rule'=>'Явное правило адаптера. Зависимости и даты проверяются отдельно для каждой строки.'];
        return ['status'=>'not_reviewed','known'=>null,'target'=>null,'rule'=>'Значение требует проверки: отдельное правило для этого поля не утверждено.'];
    }

    public static function rules(): array
    {
        $rules = [];
        $add = static function ($service, $table, $target, $pairs, $fate='direct', $rule='Прямое копирование адаптером после проверки строки.') use (&$rules) {
            foreach ($pairs as $source=>$field) $rules[$service][$table][$source] = [$fate, $target.'.'.$field, $rule];
        };
        $add('employees','departments','employees.departments',['title'=>'name','alias'=>'alias','yandex_id'=>'yandex_id','ldap_title'=>'ldap_group','is_production'=>'is_production']);
        $add('employees','departments','employees.departments',['head_id'=>'manager_id','hr_id'=>'hr_id','parent_id'=>'parent_id'],'reference','Ссылка через сопоставления Employees после переноса сотрудников.');
        $add('employees','employees','employees.employees',['name'=>'first_name','surname'=>'last_name','patronymic'=>'middle_name','username'=>'login','email'=>'work_email','personal_email'=>'personal_email','city'=>'city','phone'=>'phone','telegram'=>'telegram','skype'=>'skype','specialization'=>'specialization','position'=>'position','gender'=>'gender','employment_type'=>'cooperation_type']);
        $add('employees','employees','employees.employees',['birthdate'=>'birth_date','hired_at'=>'hired_at','dismissed_at'=>'fired_at'],'transformed','Проверка даты; служебные даты заменяются на NULL с предупреждением.');
        $add('employees','employees','employees.employees',['status'=>'employment_status'],'transformed','Явное соответствие статусов; неизвестные значения отклоняются в режиме контроля.');
        $add('employees','employees','employees.employees',['is_remote'=>'work_format'],'transformed','Булевый признак определяет офисный или удалённый формат.');
        $add('employees','employees','employees.employees',['department_id'=>'department_id'],'reference','Сопоставление подразделения.');
        $add('vacations','vacations','vacations.absences',['from'=>'starts_on','to'=>'ends_on']);
        $add('vacations','vacations','vacations.absences',['type'=>'type','status'=>'status'],'transformed','Явный справочник значений; неизвестные значения отклоняются.');
        $add('vacations','vacations','vacations.absences',['user_id'=>'employee_id'],'reference','Сопоставление с Employees по уникальному корпоративному логину.');
        $add('vacations','approvers','vacations.absence_approvals',['vacation_id'=>'absence_id','employee_id'=>'approver_employee_id'],'reference','Проверенные ссылки на отсутствие и сотрудника.');
        $add('vacations','approvers','vacations.absence_approvals',['order'=>'sequence','is_approved'=>'status'],'transformed','Положительный порядок; булево одобрение преобразуется в approved/waiting.');
        foreach (['vacations','clients','timesheets'] as $service) {
            $add($service,'users','employees.employees',['email'=>'login','employee_id'=>'id','external_key'=>'id'],'reference','Точный уникальный корпоративный логин; при наличии UUID проверяется его согласованность.');
        }
        $add('clients','clients','clients.clients',['title'=>'name','description'=>'description','type'=>'type']);
        $add('clients','clients','clients.clients',['sales_manager_id'=>'sales_employee_id','account_manager_id'=>'account_employee_id','sector_id'=>'sector'],'reference','Resolve imported user or source sector dictionary.');
        $add('clients','clients','clients.clients',['approving_term'=>'act_approval_days','payment_term'=>'payment_days'],'transformed','Validated nonnegative integer.');
        $add('clients','projects','clients.projects',['title'=>'name']);
        $add('clients','projects','clients.projects',['client_id'=>'client_id'],'reference','Imported client mapping.');
        $add('clients','rates','clients.member_terms',['grade'=>'level','start_date'=>'valid_from','end_date'=>'valid_to']);
        $add('clients','rates','clients.member_terms',['member_id'=>'project_member_id','technology_id'=>'technology'],'reference','Imported member mapping and reviewed technology dictionary.');
        $add('clients','rates','clients.member_terms',['workload'=>'hours_per_day'],'transformed','Numeric workload in 0..24.');
        $add('clients','leads','clients.leads',['title'=>'name','source'=>'source']);
        $add('clients','leads','clients.leads',['status'=>'status'],'transformed','Explicit lead status registry with converted-client consistency.');
        $add('clients','leads','clients.leads',['responsible_id'=>'responsible_employee_id','client_id'=>'converted_client_id'],'reference','Imported employee/client mappings.');
        $add('clients','contacts','clients.contact_people',['name'=>'full_name']);
        $add('clients','legal_entities','clients.client_legal_entities',['title'=>'name','fullname'=>'full_name','inn'=>'inn','ogrn'=>'ogrn','kpp'=>'kpp','registration_date'=>'registration_date','okpo'=>'okpo','oktmo'=>'oktmo','address'=>'address']);
        $add('clients','legal_entities','clients.client_legal_entities',['client_id'=>'client_id'],'reference','Imported client mapping.');
        $add('timesheets','timesheets','timesheets.timesheet_entries',['date'=>'work_date','description'=>'description']);
        $add('timesheets','timesheets','timesheets.timesheet_entries',['hours'=>'hours'],'transformed','Exact two decimal places; total employee/day <= 24.');
        $add('timesheets','timesheets','timesheets.timesheet_entries',['rate_id'=>'employee_id/client_id/project_id/account_employee_id'],'reference','Exactly one Clients term with matching employee, period and daily hours.');
        $add('timesheets','members','clients.project_members',['employee_id'=>'specialist_id','project'=>'project_id'],'reference','Unique employee and project match or explicit verified override.');
        $add('timesheets','rates','clients.member_terms',['member_id'=>'project_member_id','from'=>'valid_from','to'=>'valid_to','workload'=>'hours_per_day'],'reference','Exact match to existing imported Clients term.');
        $add('clients','rates','clients.member_terms',['rate'=>'hourly_rate'],'transformed','При включённом финансовом переносе: расшифровка ключом источника, проверка суммы и точности.');
        $add('clients','attempts','clients.connection_attempts',['rate'=>'proposed_rate'],'transformed','При включённом финансовом переносе: необязательная расшифрованная сумма.');
        $add('clients','client_requests','clients.client_requests',['title'=>'title','description'=>'description','date'=>'request_date','expired_at'=>'deadline']);
        $add('clients','client_requests','clients.client_requests',['client_id'=>'client_id','lead_id'=>'lead_id','responsible_id'=>'responsible_employee_id'],'reference','Validated client/lead origin and employee mappings.');
        $add('clients','client_requests','clients.client_requests',['closed_at'=>'status'],'transformed','Presence of closure date determines closed/open.');
        $add('clients','positions','clients.positions',['grade'=>'level','description'=>'description']);
        $add('clients','positions','clients.positions',['client_request_id'=>'client_request_id','technology_id'=>'technology','department_id'=>'direction_department_id'],'reference','Validated request, source technology and Employees department mappings.');
        $add('clients','positions','clients.positions',['count'=>'quantity','status'=>'status','closed_at'=>'status'],'transformed','Positive integer quantity; explicit enum and closure rule.');
        $add('clients','attempts','clients.connection_attempts',['user_name'=>'specialist_name','result_comment'=>'description','cv_sent_at'=>'cv_sent_at','started_at'=>'connection_date','closed_at'=>'closed_at','control_date'=>'control_date']);
        $add('clients','attempts','clients.connection_attempts',['position_id'=>'position_id','user_id'=>'specialist_id'],'reference','Imported position and optional employee mappings.');
        $add('clients','attempts','clients.connection_attempts',['status'=>'status','interviewed_at'=>'status'],'transformed','Explicit terminal outcome or reviewed stage/date rule; ambiguous result rejected.');
        $add('clients','results','clients.connection_attempts',['title'=>'failure_reasons/status'],'reference','Referenced result dictionary used for explicit attempt outcomes.');
        $add('clients','attempt_result','clients.connection_attempts',['attempt_id'=>'id','result_id'=>'failure_reasons/status'],'reference','Attempt/result relation provides validated outcome information.');
        $add('clients','reporting_periods','clients.reporting_periods',['from'=>'period_start','to'=>'period_end','approval_at'=>'timesheets_sent_at','approved_at'=>'timesheets_approved_at','act_approval_at'=>'act_sent_at','act_approved_at'=>'act_approved_at','paid_at'=>'paid_at']);
        $add('clients','reporting_periods','clients.reporting_periods',['client_id'=>'client_id'],'reference','Imported client; explicit canonical alias only when approved ID and exact dates/client agree.');
        $add('clients','reporting_periods','clients.reporting_periods',['approved_hours'=>'confirmed_hours'],'transformed','Nullable nonnegative exact decimal.');
        $add('employees','employments','employees.employment_periods',['employee_id'=>'employee_id'],'reference','Ссылка на перенесённого сотрудника; отклонённые сотрудники блокируют зависимые строки.');
        $add('employees','employments','employees.employment_periods',['type'=>'cooperation_type','start_date'=>'started_at','end_date'=>'ended_at'],'transformed','Проверка дат и однозначного соответствия исходного и целевого трудовых периодов.');
        $add('employees','employee_roles','employees.employee_access_roles',['employee_id'=>'employee_id'],'reference','Imported employee mapping.');
        $add('employees','employee_roles','employees.employee_access_roles',['role'=>'role']);
        foreach (['client_contact'=>'client','lead_contact'=>'lead'] as $table=>$kind) {
            $add('clients',$table,'clients.contact_relations',['contact_id'=>'contact_person_id',$kind.'_id'=>'entity_id'],'reference','Imported contact and client/lead mapping.');
            $add('clients',$table,'clients.contact_relations',['position'=>'relation_role']);
        }
        $add('clients','members','clients.project_members',['project_id'=>'project_id','client_id'=>'project_id','memberable_id'=>'specialist_id/partner_specialist_id'],'reference','Explicit employee/subcontract type and imported project/default project mapping.');
        $add('clients','members','clients.project_members',['memberable_type'=>'specialist_id/partner_specialist_id'],'transformed','Only employee/subcontract; unknown member types rejected.');
        $add('clients','subcontracts','clients.partner_specialists',['full_name'=>'full_name','surname'=>'full_name','name'=>'full_name','patronymic'=>'full_name'],'transformed','Nonempty full_name or joined source name parts within target length.');
        return $rules;
    }
}
