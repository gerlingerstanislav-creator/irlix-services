<?php

namespace App\Http\Controllers;

use App\Support\ProductionDirection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientsController extends Controller
{
    private const LEAD_STATUSES = [
        'Новый лид',
        'Первичный контакт',
        'Уточнение потребностей',
        'КП отправлено',
        'Активные переговоры',
        'Клиент в игноре',
        'Сделка закрыта - Успех',
        'Сделка закрыта - Отказ',
    ];

    private const REQUEST_STATUSES = ['Открыт', 'Закрыт'];
    private const POSITION_STATUSES = ['Открыт', 'Закрыт'];
    private const EXPECTED_CONNECTION_TIMES = ['Неизвестно', 'Месяц', 'Квартал', 'Пол года', 'Год'];
    private const ACCEPTABLE_TU_FORMATS = ['Не важно', 'Штат', 'Штат / ГПХ'];
    private const ATTEMPT_STATUSES = ['Новая', 'CV отправлено', 'Интервью назначено', 'Интервью пройдено', 'Ожидает подключения', 'Закрыт: успех', 'Закрыт: неудача'];
    private const REPORT_STATUSES = ['Новый', 'ТШ на согласовании', 'ТШ согласованы', 'Акт на согласовании', 'Акт согласован', 'Счет оплачен'];

    public function overview(Request $httpRequest)
    {
        $access = (array) $httpRequest->attributes->get('client_contour_access', []);
        if ($section = $httpRequest->query('section')) {
            $page = max(1, min(100000, (int) $httpRequest->query('page', 1)));
            $perPage = max(1, min(120, (int) $httpRequest->query('per_page', 80)));
            return response()->json(app(\App\Services\ClientsOverviewPageService::class)->page(
                $access,
                (string) $section,
                $page,
                $perPage,
                $httpRequest->integer('lead_id') ?: null,
                $httpRequest->only(['query', 'responsible', 'department_employee_ids', 'manager_employee_ids', 'technologies', 'activity', 'date', 'client_id', 'request_status', 'request_target']),
                $httpRequest->attributes->get('clients_overview_client_ids'),
                $httpRequest->attributes->get('clients_overview_lead_employee_ids'),
                $httpRequest->attributes->get('clients_overview_contact_ids'),
            ));
        }
        return response()->json(['data' => app(\App\Services\ClientsOverviewService::class)->overview($access)]);
    }

    public function storeClient(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:150'],
            'sales_employee_id' => ['nullable', 'integer', 'min:1'],
            'account_employee_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->assertClientServiceAssignees($request, $data['sales_employee_id'] ?? null, $data['account_employee_id'] ?? null);
        return response()->json(['data' => app(\App\Services\ClientRegistryService::class)->create($data)], 201);
    }

    public function updateClient(Request $request, int $client)
    {
        abort_unless(app(\App\Services\ClientRegistryService::class)->client($client), 404, 'Client not found');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sector' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sales_employee_id' => ['prohibited'],
            'account_employee_id' => ['prohibited'],
        ]);
        return response()->json(['data' => app(\App\Services\ClientRegistryService::class)->update($client, $data)]);
    }

    public function transferClient(Request $request, int $client)
    {
        $current = app(\App\Services\ClientRegistryService::class)->client($client);
        abort_unless($current, 404, 'Client not found');
        abort_if($current->account_employee_id, 422, 'Клиент уже передан аккаунт-менеджеру.');
        $data = $request->validate(['account_employee_id' => ['required', 'integer', 'min:1']]);
        $this->assertAssignableClientServiceEmployee($request, (int) $data['account_employee_id']);
        $username = $request->attributes->get('identity', [])['preferred_username'] ?? null;
        return response()->json(['data' => app(\App\Services\ClientRegistryService::class)
            ->transfer($client, (int) $data['account_employee_id'], $username)]);
    }

    public function storeProject(Request $request, int $client)
    {
        abort_unless(app(\App\Services\ClientRegistryService::class)->client($client), 404, 'Client not found');
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        return response()->json(['data' => app(\App\Services\ClientRegistryService::class)
            ->createProject($client, $data['name'])], 201);
    }

    private function assertLeadEditable(int $lead): void
    {
        app(\App\Services\LeadManagementService::class)->assertEditable($lead);
    }

    public function storeLead(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'responsible_employee_id' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);
        $responsibleId = (int) ($data['responsible_employee_id'] ?? $this->access($request)['employee']['id'] ?? 0);
        $this->assertAssignableClientServiceEmployee($request, $responsibleId);
        return response()->json(['data' => app(\App\Services\LeadManagementService::class)
            ->create($data, $responsibleId)], 201);
    }

    public function updateLead(Request $request, int $lead)
    {
        $leads = app(\App\Services\LeadManagementService::class);
        abort_unless($leads->lead($lead), 404, 'Lead not found');
        $this->assertLeadEditable($lead);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'responsible_employee_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', Rule::in(self::LEAD_STATUSES)],
        ]);
        if (array_key_exists('responsible_employee_id', $data))
            $this->assertAssignableClientServiceEmployee($request, (int) $data['responsible_employee_id']);
        return response()->json(['data' => $leads->update($lead, $data, $this->actorId($request))]);
    }

    public function storeLeadComment(Request $request, int $lead)
    {
        $leads = app(\App\Services\LeadManagementService::class);
        abort_unless($leads->lead($lead), 404, 'Лид не найден.');
        $this->assertLeadEditable($lead);
        $data = $request->validate(['comment' => ['required', 'string', 'max:5000']]);
        return response()->json(['data' => $leads->comment($lead, $data['comment'], $this->actorId($request))], 201);
    }

    public function storeLeadLegalEntity(Request $request, int $lead)
    {
        $leads = app(\App\Services\LeadManagementService::class);
        abort_unless($leads->lead($lead), 404, 'Лид не найден.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'inn' => ['required', 'string', 'max:32'],
            'full_name' => ['required', 'string', 'max:500'], 'ogrn' => ['nullable', 'string', 'max:32'],
            'kpp' => ['nullable', 'string', 'max:32'], 'registration_date' => ['nullable', 'date'],
            'okpo' => ['nullable', 'string', 'max:32'], 'oktmo' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);
        return response()->json(['data' => $leads->legalEntity($lead, $data)], 201);
    }

    public function storeLeadContact(Request $request, int $lead)
    {
        $leads = app(\App\Services\LeadManagementService::class);
        abort_unless($leads->lead($lead), 404, 'Лид не найден.');
        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', 'exists:contact_people,id'],
            'full_name' => [Rule::requiredIf(!$request->filled('contact_id')), 'nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        return response()->json(['data' => $leads->attachContact($lead, $data)], 201);
    }

    public function convertLead(Request $request, int $lead)
    {
        $leads = app(\App\Services\LeadManagementService::class);
        $leadRow = $leads->lead($lead);
        abort_unless($leadRow, 404, 'Lead not found');
        abort_if($leadRow->converted_client_id, 422, 'Lead is already converted');
        abort_unless($leadRow->status === 'Сделка закрыта - Успех', 422,
            'Создать клиента можно только из успешного лида.');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:150'],
            'account_employee_id' => ['nullable', 'integer', 'min:1'],
            'sales_employee_id' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if(array_key_exists('sales_employee_id', $data)
            && (int) $data['sales_employee_id'] !== (int) $leadRow->responsible_employee_id,
            422, 'Sales-менеджер закрепляется по ответственному лиду.');
        $this->assertClientServiceAssignees($request, (int) $leadRow->responsible_employee_id,
            $data['account_employee_id'] ?? null);
        return response()->json(['data' => $leads->convert($lead, $leadRow, $data)], 201);
    }

    public function storeContact(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        return response()->json(['data' => app(\App\Services\LegacyContactRelationService::class)->create($data)], 201);
    }

    public function attachContact(Request $request, int $contact)
    {
        $contacts = app(\App\Services\LegacyContactRelationService::class);
        abort_unless($contacts->contactExists($contact), 404, 'Contact not found');
        $data = $request->validate([
            'entity_type' => ['required', Rule::in(['client', 'lead'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'relation_role' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
        ]);
        if ($data['entity_type'] === 'lead') $this->assertLeadEditable((int) $data['entity_id']);
        abort_unless($contacts->relatedEntityExists($data['entity_type'], (int) $data['entity_id']),
            404, 'Related entity not found');
        return response()->json(['data' => $contacts->attach($contact, $data)], 201);
    }

    public function storeMember(Request $request, int $project)
    {
        $projectRow = app(\App\Services\LegacyProjectMembershipService::class)->project($project);
        abort_unless($projectRow, 404, 'Project not found');
        $data = $request->validate([
            'is_external' => ['sometimes', 'boolean'],
            'partner_specialist_id' => ['nullable', 'integer', Rule::exists('partner_specialists', 'id')],
            'specialist_id' => [Rule::requiredIf(!$request->boolean('is_external')), 'nullable', 'integer', 'min:1'],
            'specialist_name' => ['required', 'string', 'max:255'],
            'source_attempt_id' => ['nullable', 'integer', 'min:1'],
            'technology' => ['required', 'string', 'max:100'],
            'level' => ['required', 'string', 'max:100'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        $external = $request->boolean('is_external');
        $data['specialist_name'] = trim($data['specialist_name']);
        abort_if($data['specialist_name'] === '', 422, 'Укажите ФИО специалиста.');
        $memberId = app(\App\Services\LegacyProjectMembershipService::class)
            ->attach($project, $projectRow, $data, $external);

        return response()->json(['data' => ['member_id' => $memberId]], 201);
    }

    public function storeTerms(Request $request, int $member)
    {
        return app(MemberTermsController::class)->store($request,$member);
    }

    public function updateTerms(Request $request, int $term)
    {
        return app(ClientMembersController::class)->updateTerm($request,$term);
    }

    public function moveMember(Request $request, int $member)
    {
        $m=\App\Support\MemberIdentity::legacyMember($member);
        $request->merge(['valid_from'=>$request->input('valid_from',today()->toDateString())]);
        return app(ClientMembersController::class)->addProjectAssignment($request,(int)$m->id);
    }

    public function storeRequest(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'responsible_employee_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'request_date' => ['required', 'date'],
            'lifetime_weeks' => ['required', 'integer', Rule::in([1, 2, 3, 4])],
            'status' => ['nullable', Rule::in(self::REQUEST_STATUSES)],
            'positions' => ['required', 'array', 'min:1'],
            'positions.*.technology' => ['required', 'string', 'max:100'],
            'positions.*.direction' => ['required', 'string', 'max:100'],
            'positions.*.direction_department_id' => ['nullable', 'integer', 'min:1'],
            'positions.*.responsible_rn_employee_id' => ['nullable', 'integer', 'min:1'],
            'positions.*.level' => ['required', 'string', Rule::in(['TechLead', 'TeamLead', 'Senior', 'Middle+', 'Middle', 'Junior+', 'Junior'])],
            'positions.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'positions.*.expected_connection_time' => ['nullable', Rule::in(self::EXPECTED_CONNECTION_TIMES)],
            'positions.*.acceptable_tu_format' => ['nullable', Rule::in(self::ACCEPTABLE_TU_FORMATS)],
            'positions.*.description' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_unless((isset($data['client_id']) xor isset($data['lead_id'])), 422, 'Запрос должен быть прикреплён либо к клиенту, либо к лиду.');
        if (!empty($data['lead_id'])) $this->assertLeadEditable((int) $data['lead_id']);
        $access = (array) $request->attributes->get('client_contour_access', []);
        $responsibleId = (int) ($data['responsible_employee_id'] ?? $access['employee']['id'] ?? 0);
        $this->assertPermissionEmployee($access, 'requests.manage', $responsibleId);
        $data['positions'] = array_map(fn ($position) => ProductionDirection::resolve($request, $position), $data['positions']);

        return response()->json(['data' => app(\App\Services\RequestWorkflowService::class)->createRequest($data, $responsibleId)], 201);
    }

    private function access(Request $request): array
    {
        return (array) $request->attributes->get('client_contour_access', []);
    }

    private function actorId(Request $request): int
    {
        return (int) ($this->access($request)['employee']['id'] ?? 0);
    }

    private function assertAssignableClientServiceEmployee(Request $request, int $employeeId): void
    {
        abort_unless(in_array($employeeId, array_map('intval', $this->access($request)['assignable_client_service_employee_ids'] ?? []), true), 422, 'Выберите доступного сотрудника клиентской службы.');
    }

    private function assertClientServiceAssignees(Request $request, ?int $salesId, ?int $accountId): void
    {
        if ($salesId) $this->assertAssignableClientServiceEmployee($request, $salesId);
        if ($accountId !== null) $this->assertAssignableClientServiceEmployee($request, $accountId);
    }

    private function assertPermissionEmployee(array $access, string $permission, int $employeeId): void
    {
        $scope = (string) ($access['permissions'][$permission]['scope'] ?? 'none');
        $allowed = match ($scope) {
            'own' => [(int) ($access['employee']['id'] ?? 0)],
            'team' => array_map('intval', $access['team_employee_ids'] ?? []),
            'all' => array_map('intval', $access['employee_ids'] ?? []),
            default => [],
        };
        abort_unless(($access['platform_admin'] ?? false) || in_array($employeeId, $allowed, true), 422, 'Выберите ответственного сотрудника в доступной области.');
    }

    public function storePosition(Request $request, int $clientRequest)
    {
        app(\App\Services\RequestWorkflowService::class)->assertEntity('client_requests', $clientRequest, 'Request not found');
        $data = $request->validate([
            'direction' => ['required', 'string', 'max:100'],
            'direction_department_id' => ['nullable', 'integer', 'min:1'],
            'responsible_rn_employee_id' => ['nullable', 'integer', 'min:1'],
            'technology' => ['required', 'string', 'max:100'],
            'level' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'expected_connection_time' => ['nullable', Rule::in(self::EXPECTED_CONNECTION_TIMES)],
            'acceptable_tu_format' => ['nullable', Rule::in(self::ACCEPTABLE_TU_FORMATS)],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(self::POSITION_STATUSES)],
        ]);
        $data = ProductionDirection::resolve($request, $data);
        return response()->json(['data' => app(\App\Services\RequestWorkflowService::class)->createPosition($clientRequest, $data)], 201);
    }

    public function storeReportingPeriod(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'status' => ['nullable', Rule::in(self::REPORT_STATUSES)],
            'confirmed_hours' => ['nullable', 'numeric', 'min:0'],
            'confirmed_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        return response()->json(['data' => app(\App\Services\LegacyReportingPeriodService::class)->create($data)], 201);
    }

    public function updateReportingPeriod(Request $request, int $period)
    {
        $legacy = app(\App\Services\LegacyReportingPeriodService::class);
        abort_unless($legacy->period($period), 404, 'Reporting period not found');
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(self::REPORT_STATUSES)],
            'confirmed_hours' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'confirmed_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
        return response()->json(['data' => $legacy->update($period, $data)]);
    }

    private function assertConditionsAvailable(int $clientId, int $specialistId, string $from, ?string $to, ?int $excludeTermId = null, ?int $partnerId = null): void
    {
        \App\Support\MemberIdentity::assertConditionsAvailable($clientId, $specialistId ?: null, $partnerId, $from, $to, $excludeTermId);
    }

    private function assertTermNotLocked(int $clientId, string $from, ?string $to): void
    {
        app(\App\Services\LegacyReportingPeriodService::class)->assertTermsNotPaid($clientId, $from, $to);
    }

}
