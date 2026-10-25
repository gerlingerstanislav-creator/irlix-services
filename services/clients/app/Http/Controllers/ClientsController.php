<?php

namespace App\Http\Controllers;

use App\Support\ProductionDirection;
use App\Support\RequestLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $clientMembersByClient = app(\App\Services\ClientMemberOverviewService::class)->groupedByClient();
        $clients = DB::table('clients')->orderBy('name')->get()->map(function ($client) use ($clientMembersByClient) {
            $projects = DB::table('projects')
                ->where('client_id', $client->id)
                ->orderByDesc('is_default')
                ->orderByRaw('name nulls first')
                ->get()
                ->map(function ($project) {
                    $members = DB::table('project_members')
                        ->where('project_id', $project->id)
                        ->orderBy('specialist_name')
                        ->get()
                        ->map(function ($member) {
                            $terms = DB::table('member_terms')
                                ->where('project_member_id', $member->id)
                                ->orderBy('valid_from')
                                ->get();
                            return [...(array) $member, 'terms' => $terms];
                        });
                    return [...(array) $project, 'members' => $members];
                });
            return [...ClientLogoController::serialize($client), 'projects' => $projects,
                'client_members' => $clientMembersByClient[$client->id] ?? []];
        });

        $leads = DB::table('leads')->orderByDesc('created_at')->get()->map(function ($lead) {
            $events = DB::table('lead_events')->where('lead_id', $lead->id)->orderBy('created_at')->orderBy('id')->get();
            $legalEntities = DB::table('lead_legal_entities')->where('lead_id', $lead->id)->orderBy('name')->get();
            $contacts = DB::table('contact_relations as relation')
                ->join('contact_people as contact', 'contact.id', '=', 'relation.contact_person_id')
                ->where('relation.entity_type', 'lead')->where('relation.entity_id', $lead->id)->where('relation.active', true)
                ->select(['contact.id', 'contact.full_name', 'contact.position', 'contact.phone', 'contact.email', 'relation.relation_role'])
                ->orderBy('contact.full_name')->get();
            return [...(array) $lead,
                'request_count' => DB::table('client_requests')->where('lead_id', $lead->id)->count(),
                'events' => $events, 'legal_entities' => $legalEntities, 'contacts' => $contacts,
            ];
        });
        $contacts = DB::table('contact_people')->orderBy('full_name')->get()->map(function ($contact) {
            $relations = DB::table('contact_relations')->where('contact_person_id', $contact->id)->get();
            return [...(array) $contact, 'relations' => $relations];
        });
        $requests = DB::table('client_requests')->orderByDesc('created_at')->orderByDesc('id')->get()->map(function ($request) use ($httpRequest) {
            $positions = DB::table('positions')->where('client_request_id', $request->id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(function ($position) use ($httpRequest) {
                $attempts = DB::table('connection_attempts')->where('position_id', $position->id)->orderBy('id')->get()->map(function ($attempt) {
                    $data = (array) $attempt;
                    unset($data['cv_storage_path']);
                    $data['failure_reasons'] = json_decode((string) ($data['failure_reasons'] ?? '[]'), true) ?: [];
                    $data['interviews'] = DB::table('attempt_interviews')->where('connection_attempt_id', $attempt->id)->orderBy('sequence')->get();
                    $memberId=$attempt->client_member_id??null;
                    $data['has_connection'] = $memberId!==null;
                    $data['connection_started_at'] = $memberId ? DB::table('member_terms')->where('client_member_id',$memberId)->min('valid_from') : null;
                    return $data;
                });
                $active = $attempts->contains(fn ($attempt) => !in_array($attempt['status'], RequestLifecycle::CLOSED_ATTEMPTS, true));
                return [...(array) $position, 'responsible_rn_employee_id' => $position->responsible_rn_employee_id ?? ProductionDirection::responsibleId($httpRequest, (array) $position), 'display_status' => $position->status === 'Закрыт' ? 'Закрыт' : ($active ? 'В работе' : 'Открыт'), 'attempts' => $attempts];
            });
            $targetName = $request->client_id
                ? DB::table('clients')->where('id', $request->client_id)->value('name')
                : DB::table('leads')->where('id', $request->lead_id)->value('name');
            return [...(array) $request, 'target_name' => $targetName, 'target_type' => $request->client_id ? 'client' : 'lead', 'display_status' => RequestLifecycle::requestDisplayStatus($request->status, $positions), 'positions' => $positions];
        });
        $reportingPeriods = DB::table('reporting_periods')->orderByDesc('period_start')->get();

        return response()->json(['data' => compact('clients', 'leads', 'contacts', 'requests', 'reportingPeriods')]);
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
        $projectRow = DB::table('projects')->find($project);
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
        $memberId = DB::transaction(function () use ($data, $external, $project, $projectRow) {
            DB::table('clients')->where('id',$projectRow->client_id)->lockForUpdate()->first();
            \App\Support\MemberIdentity::assertPeriodEditable((int)$projectRow->client_id,$data['valid_from'],$data['valid_to']??null);
            $attempt = !empty($data['source_attempt_id']) ? DB::table('connection_attempts')->where('id', $data['source_attempt_id'])->lockForUpdate()->first() : null;
            if (!empty($data['source_attempt_id'])) {
                abort_unless($attempt, 422, 'Попытка подключения не найдена.');
                abort_unless((bool) $attempt->is_external === $external && ($external || (int) $attempt->specialist_id === (int) $data['specialist_id']), 422, 'Специалист не соответствует попытке подключения.');
                $position = DB::table('positions')->find($attempt->position_id);
                $clientRequest = $position ? DB::table('client_requests')->find($position->client_request_id) : null;
                abort_unless($clientRequest && (int) $clientRequest->client_id === (int) $projectRow->client_id, 422, 'Попытка относится к другому клиенту.');
            }
            $member = $external ? ($attempt ? DB::table('project_members')->where('source_attempt_id', $attempt->id)->first() : null)
                : DB::table('project_members')->where(['project_id' => $project, 'specialist_id' => $data['specialist_id']])->first();

            $catalogPartner = $external && !empty($data['partner_specialist_id'])
                ? DB::table('partner_specialists')->where('id', $data['partner_specialist_id'])->where('active', true)->first() : null;
            if ($external && !empty($data['partner_specialist_id']))
                abort_unless($catalogPartner && $catalogPartner->partner_id && DB::table('partners')->where('id',$catalogPartner->partner_id)->where('active',true)->exists(), 422, 'Партнёрский специалист не найден или неактивен.');
            if($external) abort_unless($catalogPartner || $member,422,'Выберите зарегистрированного партнёрского специалиста.');
            $partnerId=$external?($catalogPartner?->id??$member?->partner_specialist_id):null;
            if ($external && $member && $catalogPartner)
                abort_unless((int) $member->partner_specialist_id === (int) $catalogPartner->id, 422, 'Попытка привязана к другой личности.');
            if ($external && !$member)
                $member = DB::table('project_members')->where(['project_id' => $project, 'partner_specialist_id' => $partnerId])->first();
            $employeeId = $external ? null : (int) $data['specialist_id'];
            if (!$member) {
                $memberId = DB::table('project_members')->insertGetId([
                    'project_id' => $project,
                    'specialist_id' => $employeeId,
                    'partner_specialist_id' => $partnerId,
                    'specialist_name' => $catalogPartner?->full_name ?? ($external && $attempt ? $attempt->specialist_name : $data['specialist_name']),
                    'source_attempt_id' => $data['source_attempt_id'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $memberId = $member->id;
            }

            $this->assertConditionsAvailable(
                (int) $projectRow->client_id,
                $employeeId ?? 0,
                $data['valid_from'],
                $data['valid_to'] ?? null,
                null, $partnerId,
            );

            // Keep the legacy ProjectMember as an adapter, but the enduring
            // client membership is canonical and independent of the project.
            $identity=['client_id'=>(int)$projectRow->client_id,
                $partnerId!==null?'partner_specialist_id':'employee_id'=>$partnerId??$employeeId];
            $clientMember=DB::table('client_members')->where($identity)->lockForUpdate()->first();
            $clientMemberId=$clientMember?->id??DB::table('client_members')->insertGetId([
                'client_id'=>(int)$projectRow->client_id,'employee_id'=>$employeeId,
                'partner_specialist_id'=>$partnerId,
                'specialist_name'=>$catalogPartner?->full_name??$data['specialist_name'],
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            if (DB::getSchemaBuilder()->hasTable('project_assignments')) {
                $exists=DB::table('project_assignments')->where('client_member_id',$clientMemberId)->where('project_id',$project)
                    ->whereDate('valid_from','<=',$data['valid_to']??'9999-12-31')
                    ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$data['valid_from']))->exists();
                if (!$exists) DB::table('project_assignments')->insert(['client_member_id'=>$clientMemberId,'project_id'=>$project,
                    'valid_from'=>$data['valid_from'],'valid_to'=>$data['valid_to']??null,'created_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('member_terms')->insert([
                'client_member_id'=>$clientMemberId,
                'project_member_id' => $memberId,
                'technology' => $data['technology'],
                'level' => $data['level'],
                'hourly_rate' => $data['hourly_rate'],
                'hours_per_day' => $data['hours_per_day'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!empty($data['source_attempt_id'])) {
                DB::table('connection_attempts')->where('id', $data['source_attempt_id'])->update([
                    'client_member_id'=>$clientMemberId,
                    'status' => 'Закрыт: успех',
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return $memberId;
        });

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
        abort_unless(DB::table('client_requests')->where('id', $clientRequest)->exists(), 404, 'Request not found');
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
