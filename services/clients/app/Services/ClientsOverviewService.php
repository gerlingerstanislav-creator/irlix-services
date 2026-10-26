<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\LegacyProjectMember;
use App\Models\MemberTerm;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadLegalEntity;
use App\Models\ContactPerson;
use App\Models\ContactRelation;
use App\Models\ClientRequest;
use App\Models\Position;
use App\Models\ConnectionAttempt;
use App\Models\AttemptInterview;
use App\Models\ReportingPeriod;
use App\Support\ProductionDirection;
use App\Support\RequestLifecycle;

final class ClientsOverviewService
{
    private static function raw($model): object
    {
        return (object) $model->getRawOriginal();
    }

    private static function serializeClient(object $client): array
    {
        $row = (array) $client;
        $row['logo_url'] = !empty($row['logo_storage_path'])
            ? '/api/clients/clients/'.$client->id.'/logo?v='.rawurlencode(basename($row['logo_storage_path']))
            : null;
        unset($row['logo_storage_path'], $row['logo_mime_type']);
        return $row;
    }

    /** Eloquent aggregate of Clients-owned entities, preserving the old API response. */
    public function overview(array $access): array
    {
        $clientMembersByClient = app(\App\Services\ClientMemberOverviewService::class)->groupedByClient();
        $clients = Client::query()->orderBy('name')->get()->map(function ($client) use ($clientMembersByClient) {
            $projects = Project::query()
                ->where('client_id', $client->id)
                ->orderByDesc('is_default')
                ->orderByRaw('name nulls first')
                ->get()
                ->map(function ($project) {
                    $members = LegacyProjectMember::query()
                        ->where('project_id', $project->id)
                        ->orderBy('specialist_name')
                        ->get()
                        ->map(function ($member) {
                            $terms = MemberTerm::query()
                                ->where('project_member_id', $member->id)
                                ->orderBy('valid_from')
                                ->get()->map(fn ($model) => self::raw($model));
                            return [...$member->getRawOriginal(), 'terms' => $terms];
                        });
                    return [...$project->getRawOriginal(), 'members' => $members];
                });
            return [...self::serializeClient(self::raw($client)), 'projects' => $projects,
                'client_members' => $clientMembersByClient[$client->id] ?? []];
        });

        $leads = Lead::query()->orderByDesc('created_at')->get()->map(function ($lead) {
            $events = LeadEvent::query()->where('lead_id', $lead->id)->orderBy('created_at')->orderBy('id')->get()->map(fn ($model) => self::raw($model));
            $legalEntities = LeadLegalEntity::query()->where('lead_id', $lead->id)->orderBy('name')->get()->map(fn ($model) => self::raw($model));
            $contacts = ContactRelation::query()->from('contact_relations as relation')
                ->join('contact_people as contact', 'contact.id', '=', 'relation.contact_person_id')
                ->where('relation.entity_type', 'lead')->where('relation.entity_id', $lead->id)->where('relation.active', true)
                ->select(['contact.id', 'contact.full_name', 'contact.position', 'contact.phone', 'contact.email', 'relation.relation_role'])
                ->orderBy('contact.full_name')->get()->map(fn ($model) => self::raw($model));
            return [...$lead->getRawOriginal(),
                'request_count' => ClientRequest::query()->where('lead_id', $lead->id)->count(),
                'events' => $events, 'legal_entities' => $legalEntities, 'contacts' => $contacts,
            ];
        });
        $contacts = ContactPerson::query()->orderBy('full_name')->get()->map(function ($contact) {
            $relations = ContactRelation::query()->where('contact_person_id', $contact->id)->get()->map(fn ($model) => self::raw($model));
            return [...$contact->getRawOriginal(), 'relations' => $relations];
        });
        $requests = ClientRequest::query()->orderByDesc('created_at')->orderByDesc('id')->get()->map(function ($request) use ($access) {
            $positions = Position::query()->where('client_request_id', $request->id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(function ($position) use ($access) {
                $attempts = ConnectionAttempt::query()->where('position_id', $position->id)->orderBy('id')->get()->map(function ($attempt) {
                    $data = $attempt->getRawOriginal();
                    unset($data['cv_storage_path']);
                    $data['failure_reasons'] = json_decode((string) ($data['failure_reasons'] ?? '[]'), true) ?: [];
                    $data['interviews'] = AttemptInterview::query()->where('connection_attempt_id', $attempt->id)->orderBy('sequence')->get()->map(fn ($model) => self::raw($model));
                    $memberId=$attempt->client_member_id??null;
                    $data['has_connection'] = $memberId!==null;
                    $data['connection_started_at'] = $memberId ? MemberTerm::query()->where('client_member_id',$memberId)->min('valid_from') : null;
                    return $data;
                });
                $active = $attempts->contains(fn ($attempt) => !in_array($attempt['status'], RequestLifecycle::CLOSED_ATTEMPTS, true));
                return [...$position->getRawOriginal(), 'responsible_rn_employee_id' => $position->responsible_rn_employee_id ?? ProductionDirection::responsibleIdFromAccess($access, $position->getRawOriginal()), 'display_status' => $position->status === 'Закрыт' ? 'Закрыт' : ($active ? 'В работе' : 'Открыт'), 'attempts' => $attempts];
            });
            $targetName = $request->client_id
                ? Client::query()->where('id', $request->client_id)->value('name')
                : Lead::query()->where('id', $request->lead_id)->value('name');
            return [...$request->getRawOriginal(), 'target_name' => $targetName, 'target_type' => $request->client_id ? 'client' : 'lead', 'display_status' => RequestLifecycle::requestDisplayStatus($request->status, $positions), 'positions' => $positions];
        });
        $reportingPeriods = ReportingPeriod::query()->orderByDesc('period_start')->get()->map(fn ($model) => self::raw($model));


        return compact('clients', 'leads', 'contacts', 'requests', 'reportingPeriods');
    }
}
