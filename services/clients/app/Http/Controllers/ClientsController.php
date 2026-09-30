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
        $clients = DB::table('clients')->orderBy('name')->get()->map(function ($client) {
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
            return [...ClientLogoController::serialize($client), 'projects' => $projects];
        });

        $leads = DB::table('leads')->orderByDesc('created_at')->get();
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
                    $member = DB::table('project_members')->where('source_attempt_id', $attempt->id)->first();
                    $data['has_connection'] = (bool) $member;
                    $data['connection_started_at'] = $member ? DB::table('member_terms')->where('project_member_id', $member->id)->min('valid_from') : null;
                    return $data;
                });
                $active = $attempts->contains(fn ($attempt) => !in_array($attempt['status'], RequestLifecycle::CLOSED_ATTEMPTS, true));
                return [...(array) $position, 'responsible_rn_employee_id' => $position->responsible_rn_employee_id ?? ProductionDirection::responsibleId($httpRequest, (array) $position), 'display_status' => $position->status === 'Закрыт' ? 'Закрыт' : ($active ? 'В работе' : 'Открыт'), 'attempts' => $attempts];
            });
            return [...(array) $request, 'display_status' => RequestLifecycle::requestDisplayStatus($request->status, $positions), 'positions' => $positions];
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
            'account_employee_id' => ['required', 'integer', 'min:1'],
        ]);

        $id = DB::transaction(function () use ($data) {
            $id = DB::table('clients')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('projects')->insert([
                'client_id' => $id,
                'name' => null,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $id;
        });

        return response()->json(['data' => DB::table('clients')->find($id)], 201);
    }

    public function updateClient(Request $request, int $client)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sector' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sales_employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'account_employee_id' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);
        abort_unless(DB::table('clients')->where('id', $client)->exists(), 404, 'Client not found');
        DB::table('clients')->where('id', $client)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('clients')->find($client)]);
    }

    public function storeProject(Request $request, int $client)
    {
        abort_unless(DB::table('clients')->where('id', $client)->exists(), 404, 'Client not found');
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $id = DB::table('projects')->insertGetId([
            'client_id' => $client,
            'name' => trim($data['name']),
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['data' => DB::table('projects')->find($id)], 201);
    }

    public function storeLead(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'responsible_employee_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(self::LEAD_STATUSES)],
        ]);
        $id = DB::table('leads')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('leads')->find($id)], 201);
    }

    public function updateLead(Request $request, int $lead)
    {
        abort_unless(DB::table('leads')->where('id', $lead)->exists(), 404, 'Lead not found');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'responsible_employee_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', Rule::in(self::LEAD_STATUSES)],
        ]);
        DB::table('leads')->where('id', $lead)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('leads')->find($lead)]);
    }

    public function convertLead(Request $request, int $lead)
    {
        $leadRow = DB::table('leads')->find($lead);
        abort_unless($leadRow, 404, 'Lead not found');
        abort_if($leadRow->converted_client_id, 422, 'Lead is already converted');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:150'],
            'account_employee_id' => ['required', 'integer', 'min:1'],
        ]);

        $clientId = DB::transaction(function () use ($leadRow, $data, $lead) {
            $clientId = DB::table('clients')->insertGetId([
                'name' => $data['name'] ?? $leadRow->name,
                'type' => $data['type'] ?? null,
                'sector' => $data['sector'] ?? null,
                'sales_employee_id' => $leadRow->responsible_employee_id,
                'account_employee_id' => $data['account_employee_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('projects')->insert([
                'client_id' => $clientId,
                'name' => null,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $contactIds = DB::table('contact_relations')
                ->where('entity_type', 'lead')
                ->where('entity_id', $lead)
                ->where('active', true)
                ->pluck('contact_person_id');
            foreach ($contactIds as $contactId) {
                DB::table('contact_relations')->insert([
                    'contact_person_id' => $contactId,
                    'entity_type' => 'client',
                    'entity_id' => $clientId,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('leads')->where('id', $lead)->update([
                'converted_client_id' => $clientId,
                'updated_at' => now(),
            ]);
            return $clientId;
        });

        return response()->json(['data' => DB::table('clients')->find($clientId)], 201);
    }

    public function storeContact(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $id = DB::table('contact_people')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('contact_people')->find($id)], 201);
    }

    public function attachContact(Request $request, int $contact)
    {
        abort_unless(DB::table('contact_people')->where('id', $contact)->exists(), 404, 'Contact not found');
        $data = $request->validate([
            'entity_type' => ['required', Rule::in(['client', 'lead'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'relation_role' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
        ]);
        $table = $data['entity_type'] === 'client' ? 'clients' : 'leads';
        abort_unless(DB::table($table)->where('id', $data['entity_id'])->exists(), 404, 'Related entity not found');
        $id = DB::table('contact_relations')->insertGetId([
            'contact_person_id' => $contact,
            ...$data,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['data' => DB::table('contact_relations')->find($id)], 201);
    }

    public function storeMember(Request $request, int $project)
    {
        $projectRow = DB::table('projects')->find($project);
        abort_unless($projectRow, 404, 'Project not found');
        $data = $request->validate([
            'specialist_id' => ['required', 'integer', 'min:1'],
            'specialist_name' => ['required', 'string', 'max:255'],
            'source_attempt_id' => ['nullable', 'integer', 'min:1'],
            'technology' => ['required', 'string', 'max:100'],
            'level' => ['required', 'string', 'max:100'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        $memberId = DB::transaction(function () use ($data, $project, $projectRow) {
            $member = DB::table('project_members')->where([
                'project_id' => $project,
                'specialist_id' => $data['specialist_id'],
            ])->first();
            if (!$member) {
                $memberId = DB::table('project_members')->insertGetId([
                    'project_id' => $project,
                    'specialist_id' => $data['specialist_id'],
                    'specialist_name' => $data['specialist_name'],
                    'source_attempt_id' => $data['source_attempt_id'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $memberId = $member->id;
            }

            $this->assertConditionsAvailable(
                (int) $projectRow->client_id,
                (int) $data['specialist_id'],
                $data['valid_from'],
                $data['valid_to'] ?? null,
            );

            DB::table('member_terms')->insert([
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
        $memberRow = DB::table('project_members')->find($member);
        abort_unless($memberRow, 404, 'Project member not found');
        $project = DB::table('projects')->find($memberRow->project_id);
        $data = $request->validate([
            'technology' => ['required', 'string', 'max:100'],
            'level' => ['required', 'string', 'max:100'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        $this->assertConditionsAvailable((int) $project->client_id, (int) $memberRow->specialist_id, $data['valid_from'], $data['valid_to'] ?? null);
        $id = DB::table('member_terms')->insertGetId([
            'project_member_id' => $member,
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['data' => DB::table('member_terms')->find($id)], 201);
    }

    public function updateTerms(Request $request, int $term)
    {
        $termRow = DB::table('member_terms')->find($term);
        abort_unless($termRow, 404, 'Member terms not found');
        $member = DB::table('project_members')->find($termRow->project_member_id);
        $project = DB::table('projects')->find($member->project_id);
        $this->assertTermNotLocked((int) $project->client_id, $termRow->valid_from, $termRow->valid_to);
        $data = $request->validate([
            'technology' => ['sometimes', 'required', 'string', 'max:100'],
            'level' => ['sometimes', 'required', 'string', 'max:100'],
            'hourly_rate' => ['sometimes', 'required', 'numeric', 'min:0'],
            'hours_per_day' => ['sometimes', 'required', 'numeric', 'min:0', 'max:24'],
            'valid_from' => ['sometimes', 'required', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
        ]);
        $from = $data['valid_from'] ?? $termRow->valid_from;
        $to = array_key_exists('valid_to', $data) ? $data['valid_to'] : $termRow->valid_to;
        if ($to && $to < $from) abort(422, 'Дата окончания не может быть раньше даты начала.');
        $this->assertConditionsAvailable((int) $project->client_id, (int) $member->specialist_id, $from, $to, $term);
        DB::table('member_terms')->where('id', $term)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('member_terms')->find($term)]);
    }

    public function moveMember(Request $request, int $member)
    {
        $memberRow = DB::table('project_members')->find($member);
        abort_unless($memberRow, 404, 'Project member not found');
        $sourceProject = DB::table('projects')->find($memberRow->project_id);
        $data = $request->validate(['project_id' => ['required', 'integer', 'exists:projects,id']]);
        $targetProject = DB::table('projects')->find($data['project_id']);
        abort_if((int) $targetProject->client_id !== (int) $sourceProject->client_id, 422, 'ProjectMember можно перепривязать только внутри одного клиента.');
        abort_if(DB::table('project_members')->where('project_id', $targetProject->id)->where('specialist_id', $memberRow->specialist_id)->where('id', '<>', $member)->exists(), 422, 'На целевом проекте уже есть этот специалист.');
        DB::table('project_members')->where('id', $member)->update(['project_id' => $targetProject->id, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('project_members')->find($member)]);
    }

    public function storeRequest(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
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
        $access = (array) $request->attributes->get('client_contour_access', []);
        $responsibleId = (int) ($data['responsible_employee_id'] ?? $access['employee']['id'] ?? 0);
        abort_unless(in_array($responsibleId, $access['employee_ids'] ?? [], true), 422, 'Выберите ответственного сотрудника.');
        $data['positions'] = array_map(fn ($position) => ProductionDirection::resolve($request, $position), $data['positions']);

        $id = DB::transaction(function () use ($data, $responsibleId): int {
            $createdAt = now();
            $requestDate = \Carbon\CarbonImmutable::parse($data['request_date'])->startOfDay();
            $id = DB::table('client_requests')->insertGetId([
                'client_id' => $data['client_id'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'responsible_employee_id' => $responsibleId,
                'request_date' => $requestDate->toDateString(),
                'lifetime_weeks' => $data['lifetime_weeks'],
                'deadline' => $requestDate->addWeeks($data['lifetime_weeks'])->toDateString(),
                'status' => $data['status'] ?? 'Открыт',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            DB::table('positions')->insert(array_map(fn (array $position): array => [
                'client_request_id' => $id,
                'technology' => $position['technology'],
                'direction' => $position['direction'],
                'direction_department_id' => $position['direction_department_id'],
                'responsible_rn_employee_id' => $position['responsible_rn_employee_id'],
                'level' => $position['level'],
                'quantity' => $position['quantity'],
                'expected_connection_time' => $position['expected_connection_time'] ?? 'Неизвестно',
                'acceptable_tu_format' => $position['acceptable_tu_format'] ?? 'Не важно',
                'description' => $position['description'] ?? null,
                'status' => $data['status'] ?? 'Открыт',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ], $data['positions']));
            return $id;
        });
        return response()->json(['data' => DB::table('client_requests')->find($id)], 201);
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
        $id = DB::transaction(function () use ($clientRequest, $data): int {
            $parent = DB::table('client_requests')->where('id', $clientRequest)->lockForUpdate()->first();
            abort_unless($parent, 404, 'Запрос не найден.');
            abort_if($parent->status === 'Закрыт', 422, 'Запрос закрыт.');
            return DB::table('positions')->insertGetId([
                'client_request_id' => $clientRequest,
                'direction' => $data['direction'],
                'direction_department_id' => $data['direction_department_id'],
                'responsible_rn_employee_id' => $data['responsible_rn_employee_id'],
                'technology' => $data['technology'],
                'level' => $data['level'],
                'quantity' => $data['quantity'],
                'expected_connection_time' => $data['expected_connection_time'] ?? 'Неизвестно',
                'acceptable_tu_format' => $data['acceptable_tu_format'] ?? 'Не важно',
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'Открыт',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        return response()->json(['data' => DB::table('positions')->find($id)], 201);
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
        $id = DB::table('reporting_periods')->insertGetId([
            ...$data,
            'status' => $data['status'] ?? 'Новый',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['data' => DB::table('reporting_periods')->find($id)], 201);
    }

    public function updateReportingPeriod(Request $request, int $period)
    {
        abort_unless(DB::table('reporting_periods')->where('id', $period)->exists(), 404, 'Reporting period not found');
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(self::REPORT_STATUSES)],
            'confirmed_hours' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'confirmed_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
        DB::table('reporting_periods')->where('id', $period)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('reporting_periods')->find($period)]);
    }

    private function assertConditionsAvailable(int $clientId, int $specialistId, string $from, ?string $to, ?int $excludeTermId = null): void
    {
        $end = $to ?? '9999-12-31';
        $query = DB::table('member_terms as mt')
            ->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->where('p.client_id', $clientId)
            ->where('pm.specialist_id', $specialistId)
            ->whereRaw("daterange(mt.valid_from, COALESCE(mt.valid_to, DATE '9999-12-31'), '[]') && daterange(?::date, ?::date, '[]')", [$from, $end]);
        if ($excludeTermId) $query->where('mt.id', '<>', $excludeTermId);
        if ($query->exists()) abort(422, 'У специалиста уже есть пересекающиеся условия работы у этого клиента.');
    }

    private function assertTermNotLocked(int $clientId, string $from, ?string $to): void
    {
        $end = $to ?? '9999-12-31';
        $locked = DB::table('reporting_periods')
            ->where('client_id', $clientId)
            ->where('status', 'Счет оплачен')
            ->whereDate('period_start', '<=', $end)
            ->whereDate('period_end', '>=', $from)
            ->exists();
        if ($locked) abort(422, 'Условия нельзя изменить: период уже попал в закрытый отчётный период.');
    }
}
