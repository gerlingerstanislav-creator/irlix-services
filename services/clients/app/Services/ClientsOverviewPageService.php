<?php

namespace App\Services;

use App\Models\AttemptInterview;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ConnectionAttempt;
use App\Models\ContactPerson;
use App\Models\ContactRelation;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadLegalEntity;
use App\Models\MemberTerm;
use App\Models\Position;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Support\ProductionDirection;
use App\Support\RequestLifecycle;
use Illuminate\Support\Collection;

/**
 * Page only the requested Clients registry; batch-load children only for the current page.
 * The legacy /overview response is deliberately retained for older API consumers.
 */
final class ClientsOverviewPageService
{
    private const PERMISSIONS = [
        'clients' => ['clients.view'],
        'catalog' => ['clients.view', 'members.view', 'requests.view', 'positions.view', 'attempts.view', 'leads.view', 'contacts.view', 'reports.view'],
        'lead-catalog' => ['leads.view', 'requests.view', 'requests.manage'],
        'leads' => ['leads.view'],
        'lead-context' => ['leads.view'],
        'contacts' => ['contacts.view'],
        'requests' => ['requests.view', 'positions.view', 'attempts.view'],
        'reports' => ['reports.view'],
    ];

    public function page(array $access, string $section, int $page, int $perPage, ?int $leadId = null, array $filters = [], ?array $visibleClientIds = null, ?array $visibleLeadEmployeeIds = null, ?array $visibleContactIds = null): array
    {
        abort_unless(isset(self::PERMISSIONS[$section]), 422, 'Unsupported overview section');
        $granted = (bool) ($access['platform_admin'] ?? false);
        foreach (self::PERMISSIONS[$section] as $permission) {
            $granted = $granted || (bool) ($access['permissions'][$permission]['allowed'] ?? false);
        }
        abort_unless($granted, 403, 'Недостаточно прав для загрузки раздела.');

        if ($section === 'lead-context') {
            abort_unless($leadId && $leadId > 0, 422, 'Lead ID is required');
            $lead = Lead::query()->findOrFail($leadId);
            return [
                'data' => [
                    'lead' => $this->leads(collect([$lead]))[0],
                    'requests' => $this->requests(
                        ClientRequest::query()->where('lead_id', $leadId)->orderByDesc('created_at')->orderByDesc('id')->get(),
                        $access,
                    ),
                    'contacts' => $this->leadContactOptions($access, $visibleClientIds, $visibleLeadEmployeeIds),
                ],
                'meta' => ['page' => 1, 'has_more' => false],
            ];
        }

        if ($section === 'catalog' || $section === 'lead-catalog') {
            return [
                'data' => $section === 'catalog'
                    ? ['clients' => $this->catalog($visibleClientIds)]
                    : ['leads' => Lead::query()->select(['id', 'name', 'converted_client_id'])
                        ->when($visibleLeadEmployeeIds !== null, fn ($query) => $query->whereIn('responsible_employee_id', $visibleLeadEmployeeIds))
                        ->orderBy('name')->get()->toArray()],
                'meta' => ['page' => 1, 'per_page' => 0, 'total' => null, 'has_more' => false],
            ];
        }

        $query = match ($section) {
            'clients' => Client::query()->orderBy('name')->orderBy('id'),
            'leads' => Lead::query()->orderByDesc('created_at')->orderByDesc('id'),
            'contacts' => ContactPerson::query()->orderBy('full_name')->orderBy('id'),
            'requests' => ClientRequest::query()->orderByDesc('created_at')->orderByDesc('id'),
            'reports' => ReportingPeriod::query()->orderByDesc('period_start')->orderByDesc('id'),
        };
        if (in_array($section, ['clients', 'reports'], true) && $visibleClientIds !== null) {
            $query->whereIn($section === 'clients' ? 'id' : 'client_id', $visibleClientIds);
        }
        if ($section === 'leads' && $visibleLeadEmployeeIds !== null) {
            $query->whereIn('responsible_employee_id', $visibleLeadEmployeeIds);
        }
        if ($section === 'contacts' && $visibleContactIds !== null) {
            $query->whereIn('id', $visibleContactIds);
        }
        if ($section === 'clients') {
            $this->applyClientFilters($query, $filters);
        }
        if ($section === 'requests') {
            $this->applyRequestFilters($query, $filters);
        }
        if ($section === 'contacts' && isset($filters['query']) && is_string($filters['query']) && trim($filters['query']) !== '') {
            $query->where('full_name', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['query'])).'%');
        }
        if ($section === 'contacts' && isset($filters['client_id']) && ctype_digit((string) $filters['client_id']) && (int) $filters['client_id'] > 0) {
            $id = (int) $filters['client_id'];
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('contact_relations as relation')
                ->whereColumn('relation.contact_person_id', 'contact_people.id')
                ->where('relation.entity_type', 'client')->where('relation.entity_id', $id)
                ->where('relation.active', true));
        }
        $total = (clone $query)->count();
        $batch = $query->forPage($page, $perPage)->get();
        $rows = match ($section) {
            'clients' => $this->clients($batch),
            'leads' => $this->leads($batch),
            'contacts' => $this->contacts($batch),
            'requests' => $this->requests($batch, $access),
            'reports' => $batch->map(fn ($model) => $model->getRawOriginal())->all(),
        };
        $key = $section === 'reports' ? 'reportingPeriods' : $section;
        return [
            'data' => [$key => $rows],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $page * $perPage < $total,
            ],
        ];
    }


    /**
     * Apply exactly the parent-request filters used by the Requests UI before counting
     * and paginating. Filtering only in Vue makes closed/unmatched pages appear endless.
     */
    private function applyRequestFilters($query, array $filters): void
    {
        $needle = trim((string) ($filters['query'] ?? ''));
        if ($needle !== '') {
            $safe = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($needle, 0, 120)).'%';
            $query->where(function ($rows) use ($safe) {
                $rows->where('client_requests.title', 'ilike', $safe)
                    ->orWhereExists(fn ($client) => $client->selectRaw('1')->from('clients as request_client')
                        ->whereColumn('request_client.id', 'client_requests.client_id')
                        ->where('request_client.name', 'ilike', $safe))
                    ->orWhereExists(fn ($lead) => $lead->selectRaw('1')->from('leads as request_lead')
                        ->whereColumn('request_lead.id', 'client_requests.lead_id')
                        ->where('request_lead.name', 'ilike', $safe));
            });
        }

        $target = (string) ($filters['request_target'] ?? '');
        if (preg_match('/^(client|lead):([1-9][0-9]*)$/D', $target, $match)) {
            $query->where('client_requests.'.($match[1] === 'client' ? 'client_id' : 'lead_id'), (int) $match[2]);
        }

        $status = (string) ($filters['request_status'] ?? '');
        if ($status === 'Открыт' || $status === 'Закрыт') {
            // An explicitly open request can be displayed as "В работе" when it has active attempts.
            $query->where('client_requests.status', $status);
        } elseif ($status === 'В работе') {
            $query->where('client_requests.status', 'Открыт')
                ->whereExists(fn ($positions) => $positions->selectRaw('1')->from('positions as request_position')
                    ->join('connection_attempts as active_attempt', 'active_attempt.position_id', '=', 'request_position.id')
                    ->whereColumn('request_position.client_request_id', 'client_requests.id')
                    ->where('request_position.status', 'Открыт')
                    ->whereNotIn('active_attempt.status', RequestLifecycle::CLOSED_ATTEMPTS));
        }
    }

    private function applyClientFilters($query, array $filters): void
    {
        $needle = trim((string) ($filters['query'] ?? ''));
        if ($needle !== '') {
            $safe = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_substr($needle, 0, 120)).'%';
            $managerIds = json_decode((string) ($filters['manager_employee_ids'] ?? ''), true);
            $managerIds = is_array($managerIds) && count($managerIds) <= 10000
                ? array_values(array_filter(array_map('intval', $managerIds), fn ($id) => $id > 0)) : [];
            $query->where(fn ($rows) => $rows->where('name', 'ilike', $safe)
                ->orWhere('type', 'ilike', $safe)->orWhere('sector', 'ilike', $safe)
                ->orWhereIn('account_employee_id', $managerIds)
                ->orWhereIn('sales_employee_id', $managerIds));
        }
        $responsible = (string) ($filters['responsible'] ?? '');
        if (preg_match('/^(account|sales):([1-9][0-9]*)$/D', $responsible, $match)) {
            $query->where($match[1] === 'account' ? 'account_employee_id' : 'sales_employee_id', (int) $match[2]);
        }
        $employeeIds = json_decode((string) ($filters['department_employee_ids'] ?? ''), true);
        if (is_array($employeeIds) && count($employeeIds) <= 10000) {
            $ids = array_values(array_filter(array_map('intval', $employeeIds), fn ($id) => $id > 0));
            $query->where(fn ($rows) => $rows->whereIn('account_employee_id', $ids)
                ->orWhereIn('sales_employee_id', $ids));
        }
        $technologies = json_decode((string) ($filters['technologies'] ?? ''), true);
        if (is_array($technologies) && count($technologies) <= 200) {
            $names = array_values(array_filter($technologies, fn ($item) => is_string($item) && strlen($item) < 255));
            $query->whereExists(function ($sub) use ($names) {
                $sub->selectRaw('1')->from('client_members as cm')
                    ->join('member_terms as mt', 'mt.client_member_id', '=', 'cm.id')
                    ->whereColumn('cm.client_id', 'clients.id')
                    ->whereIn('mt.technology', $names);
            });
        }
        $activity = (string) ($filters['activity'] ?? '');
        if (in_array($activity, ['active', 'inactive'], true)) {
            $today = (string) ($filters['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $today)) $today = now()->toDateString();
            $query->whereExists(function ($sub) use ($today) {
                $sub->selectRaw('1')->from('client_members as cm')
                    ->join('member_terms as mt', 'mt.client_member_id', '=', 'cm.id')
                    ->whereColumn('cm.client_id', 'clients.id')
                    ->whereDate('mt.valid_from', '<=', $today)
                    ->where(fn ($term) => $term->whereNull('mt.valid_to')->orWhereDate('mt.valid_to', '>=', $today));
            }, 'and', $activity === 'inactive');
            // The third whereExists argument negates the predicate for inactive clients.
        }
    }

    private function leadContactOptions(array $access, ?array $clientIds, ?array $leadEmployeeIds): array
    {
        if (!($access['platform_admin'] ?? false) && !($access['permissions']['contacts.view']['allowed'] ?? false)) return [];

        $leadIds = Lead::query()
            ->when($leadEmployeeIds !== null, fn ($query) => $query->whereIn('responsible_employee_id', $leadEmployeeIds))
            ->pluck('id')->all();
        $relationQuery = ContactRelation::query()->where('active', true)
            ->where(function ($query) use ($leadIds, $clientIds) {
                $query->where(fn ($rel) => $rel->where('entity_type', 'lead')->whereIn('entity_id', $leadIds));
                if ($clientIds === null) {
                    $query->orWhere('entity_type', 'client');
                } else {
                    $query->orWhere(fn ($rel) => $rel->where('entity_type', 'client')->whereIn('entity_id', $clientIds));
                }
            });
        $relations = $relationQuery->get()->groupBy('contact_person_id');
        return ContactPerson::query()->select(['id', 'full_name'])
            ->whereIn('id', $relations->keys()->all())->orderBy('full_name')->get()
            ->map(fn ($contact) => [
                ...$contact->getAttributes(),
                'relations' => ($relations->get($contact->id) ?? collect())->map(fn ($relation) => $relation->getRawOriginal())->all(),
            ])->all();
    }

    private function clients(Collection $batch): array
    {
        $ids = $batch->pluck('id')->all();
        if (!$ids) return [];
        // The new page contract renders canonical ClientMember relations.
        // Legacy project-members remain on the old /overview endpoint only.
        $projectsByClient = Project::query()->select(['id', 'client_id', 'name', 'is_default'])
            ->whereIn('client_id', $ids)
            ->orderByDesc('is_default')->orderByRaw('name nulls first')
            ->get()->groupBy('client_id');
        $clientMembers = app(ClientMemberOverviewService::class)->groupedByClient($ids);

        return $batch->map(function (Client $client) use ($projectsByClient, $clientMembers) {
            $row = $client->getRawOriginal();
            $row['logo_url'] = !empty($row['logo_storage_path'])
                ? '/api/clients/clients/'.$client->id.'/logo?v='.rawurlencode(basename($row['logo_storage_path']))
                : null;
            unset($row['logo_storage_path'], $row['logo_mime_type']);
            $row['projects'] = ($projectsByClient->get($client->id) ?? collect())
                ->map(fn (Project $project) => [...$project->getRawOriginal(), 'members' => []])->all();
            $row['client_members'] = $clientMembers[$client->id] ?? [];
            return $row;
        })->all();
    }

    /** Lightweight selector data, never connection terms, logos or client commercial details. */
    private function catalog(?array $visibleClientIds = null): array
    {
        $clients = Client::query()->select(['id', 'name', 'account_employee_id', 'sales_employee_id'])
            ->when($visibleClientIds !== null, fn ($query) => $query->whereIn('id', $visibleClientIds))
            ->orderBy('name')->orderBy('id')->get();
        $projects = Project::query()->select(['id', 'client_id', 'name', 'is_default'])
            ->orderByDesc('is_default')->get()->groupBy('client_id');
        return $clients->map(fn (Client $client) => [
            ...$client->getAttributes(),
            'projects' => ($projects->get($client->id) ?? collect())->map(fn ($project) => $project->getAttributes())->all(),
            'client_members' => [],
        ])->all();
    }

    private function leads(Collection $batch): array
    {
        $ids = $batch->pluck('id')->all();
        if (!$ids) return [];
        $events = LeadEvent::query()->whereIn('lead_id', $ids)->orderBy('created_at')->orderBy('id')->get()->groupBy('lead_id');
        $entities = LeadLegalEntity::query()->whereIn('lead_id', $ids)->orderBy('name')->get()->groupBy('lead_id');
        $contacts = ContactRelation::query()->from('contact_relations as relation')
            ->join('contact_people as contact', 'contact.id', '=', 'relation.contact_person_id')
            ->where('relation.entity_type', 'lead')->whereIn('relation.entity_id', $ids)->where('relation.active', true)
            ->select(['relation.entity_id', 'contact.id', 'contact.full_name', 'contact.position', 'contact.phone', 'contact.email', 'relation.relation_role'])
            ->orderBy('contact.full_name')->get()->groupBy('entity_id');
        $counts = ClientRequest::query()->whereIn('lead_id', $ids)->select('lead_id')
            ->selectRaw('count(*) as total')->groupBy('lead_id')->pluck('total', 'lead_id');

        return $batch->map(fn (Lead $lead) => [
            ...$lead->getRawOriginal(),
            'request_count' => (int) ($counts[$lead->id] ?? 0),
            'events' => ($events->get($lead->id) ?? collect())->map(fn ($row) => $row->getRawOriginal())->all(),
            'legal_entities' => ($entities->get($lead->id) ?? collect())->map(fn ($row) => $row->getRawOriginal())->all(),
            'contacts' => ($contacts->get($lead->id) ?? collect())->map(function ($row) {
                $result = $row->getRawOriginal();
                unset($result['entity_id']);
                return $result;
            })->all(),
        ])->all();
    }

    private function contacts(Collection $batch): array
    {
        $ids = $batch->pluck('id')->all();
        if (!$ids) return [];
        $relations = ContactRelation::query()->whereIn('contact_person_id', $ids)->get()->groupBy('contact_person_id');
        return $batch->map(fn (ContactPerson $person) => [
            ...$person->getRawOriginal(),
            'relations' => ($relations->get($person->id) ?? collect())->map(fn ($row) => $row->getRawOriginal())->all(),
        ])->all();
    }

    private function requests(Collection $batch, array $access): array
    {
        $ids = $batch->pluck('id')->all();
        if (!$ids) return [];
        $positions = Position::query()->whereIn('client_request_id', $ids)
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $attempts = ConnectionAttempt::query()->whereIn('position_id', $positions->pluck('id')->all())
            ->orderBy('id')->get();
        $interviews = AttemptInterview::query()->whereIn('connection_attempt_id', $attempts->pluck('id')->all())
            ->orderBy('sequence')->get()->groupBy('connection_attempt_id');
        $memberIds = $attempts->pluck('client_member_id')->filter()->unique()->all();
        $starts = MemberTerm::query()->whereIn('client_member_id', $memberIds)
            ->select('client_member_id')->selectRaw('min(valid_from) as start_date')
            ->groupBy('client_member_id')->pluck('start_date', 'client_member_id');
        $clients = Client::query()->whereIn('id', $batch->pluck('client_id')->filter()->all())->pluck('name', 'id');
        $leads = Lead::query()->whereIn('id', $batch->pluck('lead_id')->filter()->all())->pluck('name', 'id');
        $attemptsByPosition = $attempts->groupBy('position_id');
        $positionsByRequest = $positions->groupBy('client_request_id');

        return $batch->map(function (ClientRequest $request) use ($positionsByRequest, $attemptsByPosition, $interviews, $starts, $clients, $leads, $access) {
            $rows = ($positionsByRequest->get($request->id) ?? collect())->map(function (Position $position) use ($attemptsByPosition, $interviews, $starts, $access) {
                $attemptRows = ($attemptsByPosition->get($position->id) ?? collect())->map(function (ConnectionAttempt $attempt) use ($interviews, $starts) {
                    $data = $attempt->getRawOriginal();
                    unset($data['cv_storage_path']);
                    $data['failure_reasons'] = json_decode((string) ($data['failure_reasons'] ?? '[]'), true) ?: [];
                    $data['interviews'] = ($interviews->get($attempt->id) ?? collect())->map(fn ($row) => $row->getRawOriginal())->all();
                    $data['has_connection'] = $attempt->client_member_id !== null;
                    $data['connection_started_at'] = $attempt->client_member_id ? ($starts[$attempt->client_member_id] ?? null) : null;
                    return $data;
                });
                $active = $attemptRows->contains(fn ($attempt) => !in_array($attempt['status'], RequestLifecycle::CLOSED_ATTEMPTS, true));
                return [
                    ...$position->getRawOriginal(),
                    'responsible_rn_employee_id' => $position->responsible_rn_employee_id ?? ProductionDirection::responsibleIdFromAccess($access, $position->getRawOriginal()),
                    'display_status' => $position->status === 'Закрыт' ? 'Закрыт' : ($active ? 'В работе' : 'Открыт'),
                    'attempts' => $attemptRows->all(),
                ];
            });
            return [
                ...$request->getRawOriginal(),
                'target_name' => $request->client_id ? ($clients[$request->client_id] ?? null) : ($leads[$request->lead_id] ?? null),
                'target_type' => $request->client_id ? 'client' : 'lead',
                'display_status' => RequestLifecycle::requestDisplayStatus($request->status, $rows),
                'positions' => $rows->all(),
            ];
        })->all();
    }
}
