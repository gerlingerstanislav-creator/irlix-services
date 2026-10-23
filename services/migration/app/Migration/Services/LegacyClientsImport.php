<?php

namespace App\Migration\Services;

// Compatibility only: old-schema import/validate while service databases are
// upgraded. Never use as canonical importer after client_members migrations.
trait LegacyClientsImport
{
    private function validateLegacy(int $runId): array
    {
        $this->source = $this->extract();
        $result = $this->validateRows($this->source);
        $target = DB::connection('target_clients');
        $defaults = ['source' => count($this->source['clients']), 'valid' => 0, 'changed' => 0];
        foreach ($this->source['clients'] as $client) {
            $clientId = $this->store->mapping('clients', 'clients', $client['id']);
            $projectId = $this->store->mapping('clients', 'default_projects', $client['id']);
            $projects = $clientId === null ? collect() : $target->table('projects')->where('client_id', $clientId)->where('is_default', true)->get();
            if ($projectId !== null && $projects->count() === 1 && (int) $projects->first()->id === (int) $projectId && $projects->first()->name === null) $defaults['valid']++;
            else $defaults['changed']++;
        }
        $origins = ['source' => count($this->source['leads']) + count($this->source['client_requests']), 'valid' => 0, 'changed' => 0];
        foreach ($this->source['leads'] as $row) {
            $id = $this->store->mapping('clients', 'leads', $row['id']);
            $lead = $id === null ? null : $target->table('leads')->find((int) $id);
            $converted = empty($row['client_id']) ? null : $this->store->mapping('clients', 'clients', $row['client_id']);
            if ($lead && ($converted === null ? $lead->converted_client_id === null
                : ((int) $lead->converted_client_id === (int) $converted && $lead->status === 'Сделка закрыта - Успех'))) $origins['valid']++;
            else $origins['changed']++;
        }
        foreach ($this->source['client_requests'] as $row) {
            $id = $this->store->mapping('clients', 'client_requests', $row['id']);
            $request = $id === null ? null : $target->table('client_requests')->find((int) $id);
            try { $origin = $this->requestOrigin($row); }
            catch (\DomainException) { $origins['changed']++; continue; }
            $client = $origin['client_id'] === null ? null : $this->store->mapping('clients', 'clients', $origin['client_id']);
            $lead = $origin['lead_id'] === null ? null : $this->store->mapping('clients', 'leads', $origin['lead_id']);
            if ($request && ($origin['client_id'] === null || $client !== null) && ($origin['lead_id'] === null || $lead !== null)
                && ($client === null ? $request->client_id === null : (int) $request->client_id === (int) $client)
                && ($lead === null ? $request->lead_id === null : (int) $request->lead_id === (int) $lead)) $origins['valid']++;
            else $origins['changed']++;
        }
        $result['tables']['default_projects'] = $defaults;
        $result['tables']['lead_request_origins'] = $origins;
        $result['ok'] = $result['ok'] && $defaults['changed'] === 0 && $origins['changed'] === 0;
        return $result;
    }


    protected function importLegacy(): void
    {
        $this->rows('users', function ($r, $id) {
            $employee = $this->employee($r, 'external_key');
            $imported = $this->store->mapping('clients', 'users', $id);
            if ($imported !== null && (int) $imported !== $employee) throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION', 'Пользователь уже переносился к другому сотруднику. Требуется проверка и откат Clients перед перепривязкой.', ['imported_employee_id' => (int) $imported, 'resolved_employee_id' => $employee]);
            $this->map('users', $id, $employee, $r);
        });
        $this->rows('clients', function ($r, $id) {
            $client = $this->write('clients', $id, 'clients', [
                'name' => $this->need(trim($r['title']), 'Client title is required'), 'description' => $r['description'],
                'type' => $r['type'], 'sector' => $r['sector_id'] ? $this->lookup('sectors', $r['sector_id'])['name'] : null,
                'sales_employee_id' => $r['sales_manager_id'] ? $this->ref('users', $r['sales_manager_id']) : null,
                'account_employee_id' => $r['account_manager_id'] ? $this->ref('users', $r['account_manager_id']) : null,
                'act_approval_days' => (int) $this->number($r['approving_term'], 65535), 'payment_days' => (int) $this->number($r['payment_term'], 65535),
            ], $r);
            // Required for every client, including clients with no members or only named projects.
            // Keep the existing entity/key so earlier imports reuse their default-project mappings.
            $this->write('default_projects', $id, 'projects',
                ['client_id' => $client, 'name' => null, 'is_default' => true], $r,
                ['client_id' => $client, 'is_default' => true]);
        });
        $this->rows('projects', function ($r, $id) {
            $this->write('projects', $id, 'projects', ['client_id' => $this->ref('clients', $r['client_id']), 'name' => $r['title'], 'is_default' => false], $r);
        });
        $this->rows('subcontracts', function ($r, $id) {
            $name = trim((string) ($r['full_name'] ?? implode(' ', array_filter([$r['surname'] ?? null, $r['name'] ?? null, $r['patronymic'] ?? null], fn ($v) => $v !== null && $v !== ''))));
            if ($name === '' || mb_strlen($name) > 255) throw new SourceRowConflict('PARTNER_NAME_UNRESOLVED', 'В старом справочнике не заполнено допустимое ФИО партнерского специалиста.', ['field' => 'subcontracts.full_name/name']);
            $this->write('subcontracts', $id, 'partner_specialists', ['full_name' => $name], $r);
        });
        $this->rows('members', function ($r, $id) {
            $partner = null; $employee = null;
            $type = mb_strtolower((string) $r['memberable_type']);
            if ($type === 'employee') {
                $employee = $this->ref('users', $r['memberable_id']);
                $user = $this->lookup('users', $r['memberable_id']);
                $name = $this->need($this->fullName($user), 'Member specialist name is missing');
            } elseif ($type === 'subcontract') {
                $partner = $this->ref('subcontracts', $r['memberable_id']);
                $person = $this->lookup('subcontracts', $r['memberable_id']);
                $name = trim((string) ($person['full_name'] ?? implode(' ', array_filter([$person['surname'] ?? null, $person['name'] ?? null, $person['patronymic'] ?? null], fn ($v) => $v !== null && $v !== ''))));
            } else throw new SourceRowConflict('EXTERNAL_MEMBER_UNRESOLVED', 'Неизвестный тип участника: нужен подтверждённый справочник партнерского специалиста.', ['field' => 'members.memberable_type', 'memberable_type' => $r['memberable_type'], 'memberable_id' => $r['memberable_id']]);
            if ($r['project_id']) {
                $project = $this->ref('projects', $r['project_id']);
                if ($r['client_id'] && (string) $this->lookup('projects', $r['project_id'])['client_id'] !== (string) $r['client_id']) throw new \DomainException('Member client and project disagree');
            }
            else {
                $this->ref('clients', $r['client_id']);
                $project = $this->ref('default_projects', $r['client_id']);
            }
            $this->write('members', $id, 'project_members', ['project_id' => $project, 'specialist_id' => $employee,
                'partner_specialist_id' => $partner, 'specialist_name' => $name], $r, ['project_id' => $project, $partner !== null ? 'partner_specialist_id' : 'specialist_id' => $partner ?? $employee]);
            if (!empty($r['attached_by'])) $this->preserve('members', ['id' => $id, 'attached_by' => $r['attached_by']], $id,
                'Автор добавления подключения сохранён в приватной metadata: в ProjectMember нет соответствующего поля.');
        });
        $this->rows('rates', function ($r, $id) {
            [$from, $to] = $this->dates($r['start_date'], $r['end_date']);
            $member = $this->ref('members', $r['member_id']);
            $missingTechnology = $r['technology_id'] === null || $r['technology_id'] === '';
            $technology = $missingTechnology ? null : $this->lookup('technologies', $r['technology_id'], 'rates.technology_id', $id)['title'];
            $level = $r['grade'] === null || trim((string) $r['grade']) === '' ? null : $r['grade'];
            $this->noOverlap('rates', $id, 'member_terms', ['project_member_id' => $member], 'valid_from', 'valid_to', $from, $to);
            $this->write('rates', $id, 'member_terms', ['project_member_id' => $member, 'technology' => $technology,
                'level' => $level, 'hours_per_day' => $this->amount($r['workload'], 'rates.workload', 24), 'valid_from' => $from, 'valid_to' => $to]
                + (config('migration.clients_defer_rate_amounts') ? [] : ['hourly_rate' => $this->amount($r['rate'], 'rates.rate', 9999999999.99)]), $r);
            if ($missingTechnology) $this->warning('rates', $id, 'MISSING_TERM_TECHNOLOGY', 'Технология в старых условиях не указана; сохранено NULL.', ['field' => 'rates.technology_id']);
            if ($level === null) $this->warning('rates', $id, 'MISSING_TERM_GRADE', 'Грейд в старых условиях не указан; сохранено NULL.', ['field' => 'rates.grade']);
            if (config('migration.clients_defer_rate_amounts')) $this->warning('rates', $id, 'RATE_AMOUNT_DEFERRED',
                'Условия подключения перенесены; сумма ставки отложена. Новая ставка неизвестна, существующая сумма не изменяется.',
                ['field' => 'rates.rate']);
            if (!empty($r['is_revision']) || !empty($r['reason'])) $this->preserve('rates',
                ['id' => $id, 'is_revision' => $r['is_revision'] ?? null, 'reason' => $r['reason'] ?? null], $id,
                'Признак и причина пересмотра сохранены в приватной metadata; история новых действий не выдумывается.');
        });
        $this->rows('leads', function ($r, $id) {
            $convertedClient = $r['client_id'] ? $this->ref('clients', $r['client_id']) : null;
            $this->write('leads', $id, 'leads', ['name' => $r['title'], 'source' => $r['source'],
                'responsible_employee_id' => $this->ref('users', $r['responsible_id']),
                'status' => $this->leadStatus($r, $id), 'converted_client_id' => $convertedClient], $r);
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
            $origin = $this->requestOrigin($r);
            $lead = $origin['lead_id'] !== null ? $this->ref('leads', $origin['lead_id']) : null;
            $client = $origin['client_id'] !== null ? $this->ref('clients', $origin['client_id']) : null;
            $mapped = $this->store->mapping('clients', 'client_requests', $id);
            $existing = $mapped === null ? null : DB::connection('target_clients')->table('client_requests')->find((int) $mapped);
            if ($existing?->lead_id !== null && (int) $existing->lead_id !== $lead) {
                throw new SourceRowConflict('REQUEST_ORIGIN_COLLISION',
                    'Повторный перенос не может удалить или заменить исходный лид запроса; требуется сверка связей.', []);
            }
            $this->write('client_requests', $id, 'client_requests', ['client_id' => $client, 'lead_id' => $lead,
                'title' => $r['title'], 'description' => $r['description'], 'responsible_employee_id' => $this->ref('users', $r['responsible_id']),
                'request_date' => $from, 'deadline' => $to, 'lifetime_weeks' => $to === null ? null : max(1,min(4,(int) ceil((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days / 7))),
                'status' => $r['closed_at'] ? 'Закрыт' : 'Открыт'], $r);
        });
        $this->rows('positions', function ($r, $id) {
            $missingTechnology = $r['technology_id'] === null || $r['technology_id'] === '';
            $technology = $missingTechnology ? null : $this->lookup('technologies', $r['technology_id'], 'positions.technology_id', $id)['title'];
            $department = $r['department_id'] ? $this->store->mapping('employees', 'department', $r['department_id']) : null;
            if ($r['department_id'] && !$department) throw new \DomainException('Production direction is unresolved in Employees');
            if ($r['count'] < 1 || (int) $r['count'] != $r['count']) throw new \DomainException('Position quantity must be a positive integer');
            $this->write('positions', $id, 'positions', ['client_request_id' => $this->ref('client_requests', $r['client_request_id']),
                'technology' => $technology, 'level' => $r['grade'], 'description' => $r['description'],
                'direction_department_id' => $department, 'quantity' => (int) $this->need($this->number($r['count'], 65535), 'Quantity must be positive'),
                'status' => $r['closed_at'] ? 'Закрыт' : $this->enum($r['status'], ['open' => 'Открыт', 'closed' => 'Закрыт', 'открыт' => 'Открыт', 'закрыт' => 'Закрыт', 'under_consideration' => 'Открыт', 'waiting' => 'Открыт', 'no_candidates' => 'Открыт'], 'positions.status')], $r);
            if ($missingTechnology) $this->warning('positions', $id, 'MISSING_POSITION_TECHNOLOGY',
                'Технология в старой позиции не указана; позиция перенесена без технологии.',
                ['field' => 'positions.technology_id', 'reference_id' => $r['technology_id'], 'source_id' => $id]);
        });
        $this->rows('attempts', function ($r, $id) {
            // Explicit terminal status supplies the outcome; result relations supply optional reasons.
            $results = [];
            foreach ($this->source['attempt_result'] as $link) if ((string) $link['attempt_id'] === $id) $results[] = $this->lookup('results', $link['result_id']);
            $closed = !empty($r['closed_at']) || $this->explicitOutcome($r) !== null;
            $reasons = null;
            if ($closed) {
                try { [$status, $reasons] = $this->closedOutcome($results, $r); }
                catch (SourceRowConflict $e) {
                    throw new SourceRowConflict($e->reason, $e->getMessage(), $e->details + [
                        'attempt_fields' => self::attemptFields($r),
                        'result_titles' => array_values(array_unique(array_column($results, 'title'))),
                        'result_count' => count($results),
                    ]);
                }
            }
            else $status = $this->attemptStage($r);
            $failureStage = $status === 'Закрыт: неудача' ? $this->attemptStage($r) : null;
            $employee = $r['user_id'] ? $this->ref('users', $r['user_id']) : null;
            $name = $r['user_name'] ?: ($r['user_id'] ? $this->fullName($this->lookup('users',$r['user_id'])) : null);
            $this->write('attempts', $id, 'connection_attempts', ['position_id' => $this->ref('positions', $r['position_id']),
                'specialist_id' => $employee, 'specialist_name' => $this->need($name, 'Attempt specialist name is missing'),
                'is_external' => $employee === null, 'status' => $status, 'closed_from_status' => $failureStage, 'failure_reasons' => $reasons, 'description' => $r['result_comment'],
                'cv_sent_at' => $r['cv_sent_at'], 'connection_date' => $r['started_at'], 'closed_at' => $r['closed_at'],
                'control_date' => $r['control_date']] + (config('migration.clients_defer_rate_amounts') ? [] :
                    ['proposed_rate' => $r['rate'] === null ? null : $this->amount($r['rate'], 'attempts.rate', 9999999999.99)]), $r);
            if ($closed && !$results && $this->explicitOutcome($r) === null) {
                $this->warning('attempts', $id, 'INFERRED_ATTEMPT_OUTCOME',
                    'Исход восстановлен по согласованному правилу дат: '.$status.($failureStage ? ', этап «'.$failureStage.'».' : '.'),
                    ['status' => $status, 'closed_from_status' => $failureStage, 'attempt_fields' => self::attemptFields($r), 'result_count' => 0]);
            }
            if (config('migration.clients_defer_rate_amounts') && $r['rate'] !== null) $this->preserve('attempts', ['id' => $id, 'rate' => $r['rate']], $id, 'Предлагаемая ставка попытки отложена до появления APP_KEY старого Clients.');
        });
        // Canonical rows must be available before duplicate references, regardless of source order.
        $preferred = array_map('strval', config('migration.clients_preferred_reporting_period_ids', []));
        usort($this->source['reporting_periods'], fn ($a, $b) =>
            (int) in_array((string) $b['id'], $preferred, true) <=> (int) in_array((string) $a['id'], $preferred, true));
        $this->rows('reporting_periods', function ($r, $id) {
            $canonical = $this->preferredReportingPeriod($r);
            if ($canonical && (string) $canonical['id'] !== $id) {
                $canonicalId = (string) $canonical['id'];
                $targetId = $this->ref('reporting_periods', $canonicalId);
                $existing = $this->store->mapping('clients', 'reporting_periods', $id);
                if ($existing !== null && (int) $existing !== $targetId) throw new SourceRowConflict('REPORTING_PERIOD_ALIAS_COLLISION',
                    'Дублирующий период уже перенесён отдельно; требуется явное согласование существующих записей.', ['canonical_legacy_id' => $canonicalId]);
                $this->map('reporting_periods', $id, $targetId, $r);
                if (!$this->dryRun) $this->store->saveMapping($this->runId, 'clients', 'reporting_periods', $id, $targetId,
                    ['source' => $r, 'target_table' => 'reporting_periods', 'alias_of' => $canonicalId]);
                $this->warning('reporting_periods', $id, 'REPORTING_PERIOD_ALIAS',
                    'Период сопоставлен с выбранной оператором исходной записью ID '.$canonicalId.'; отдельный период не создаётся.',
                    ['canonical_legacy_id' => $canonicalId, 'period_records' => [$this->reportingFields($id), $this->reportingFields($canonicalId)], 'legacy_client_id' => $r['client_id']]);
                return;
            }
            [$from, $to] = $this->reportingDates($r, $id); $client = $this->ref('clients', $r['client_id']);
            try { $this->noOverlap('reporting_periods', $id, 'reporting_periods', ['client_id' => $client], 'period_start', 'period_end', $from, $to); }
            catch (SourceRowConflict $e) {
                if ($e->reason !== 'SOURCE_PERIOD_OVERLAP') throw $e;
                throw new SourceRowConflict($e->reason, $e->getMessage(), $e->details + [
                    'period_records' => [$this->reportingFields($id), $this->reportingFields((string) $e->details['overlapping_period']['legacy_id'])],
                    'legacy_client_id' => $r['client_id'],
                ]);
            }
            $status = $r['paid_at'] ? 'Счет оплачен' : ($r['act_approved_at'] ? 'Акт согласован' : ($r['act_approval_at'] ? 'Акт на согласовании' : ($r['approved_at'] ? 'ТШ согласованы' : ($r['approval_at'] ? 'ТШ на согласовании' : 'Новый'))));
            $this->write('reporting_periods', $id, 'reporting_periods', ['client_id' => $client, 'period_start' => $from, 'period_end' => $to,
                'status' => $status, 'confirmed_hours' => $r['approved_hours'] === null ? null : $this->decimal($r['approved_hours'], 9999999999.99),
                'timesheets_sent_at' => $r['approval_at'], 'timesheets_approved_at' => $r['approved_at'],
                'act_sent_at' => $r['act_approval_at'], 'act_approved_at' => $r['act_approved_at'], 'paid_at' => $r['paid_at']], $r,
                ['client_id' => $client, 'period_start' => $from, 'period_end' => $to]);
        });
        foreach (['contacts','technologies','sectors','grades','grade_rates','departments','results','attempt_result','reporting_period_rate','feedback','interviews','notes','reviews','subcontracts','attachments','legal_documents','activity_log','settings','client_technology','user_technology'] as $table) {
            $this->rows($table, fn ($r, $id) => $this->preserve($table, $r, $id, config('migration.deferred_tables.clients.'.$table) ?: 'Original auxiliary attributes retained in private metadata; not fabricated into new domain history or downloaded files.'));
        }
    }
}
