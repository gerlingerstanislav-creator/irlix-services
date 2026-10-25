<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContactPerson;
use App\Models\ContactRelation;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ClientLegalEntity;
use App\Models\ClientNote;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadLegalEntity;
use App\Models\ClientHistoryEvent;
use App\Models\ClientMember;
use App\Models\MemberTerm;
use App\Models\ClientAccountDelegation;

final class ClientCardReadService
{
    private static function raw($model): ?object
    {
        return $model ? (object) $model->getRawOriginal() : null;
    }

    private function serializeClient(object $client): array
    {
        $row = \App\Http\Controllers\ClientLogoController::serialize($client);
        $row['technologies'] = $client->technologies ? (json_decode($client->technologies, true) ?: []) : [];
        return $row;
    }

    public function show(int $client)
    {
        $clientRow = self::raw(Client::query()->find($client));
        abort_unless($clientRow, 404, 'Client not found');
        $contacts = ContactPerson::query()->join('contact_relations','contact_relations.contact_person_id','=','contact_people.id')->where('contact_relations.entity_type','client')->where('contact_relations.entity_id',$client)->where('contact_relations.active',true)->orderBy('contact_people.full_name')->select(['contact_people.id','contact_people.full_name','contact_people.position','contact_people.phone','contact_people.email','contact_relations.id as relation_id','contact_relations.relation_role','contact_relations.comment as relation_comment'])->get()->map(static fn ($model) => (object) $model->getRawOriginal());
        return [
            'client'=>$this->serializeClient($clientRow),
            'projects'=>Project::query()->where('client_id',$client)->orderByDesc('is_default')->orderBy('name')->get()->map(static fn ($model) => (object) $model->getRawOriginal()),
            'contacts'=>$contacts,
            'reporting_periods'=>ReportingPeriod::query()->where('client_id',$client)->orderByDesc('period_start')->get()->map(static fn ($model) => (object) $model->getRawOriginal()),
            'legal_entities'=>ClientLegalEntity::query()->where('client_id',$client)->orderBy('name')->get()->map(static fn ($model) => (object) $model->getRawOriginal()),
            'notes'=>ClientNote::query()->where('client_id',$client)->orderByDesc('created_at')->orderByDesc('id')->get()->map(static fn ($model) => (object) $model->getRawOriginal()),
            'history'=>$this->history($client),
            'origin_lead_id'=>Lead::query()->where('converted_client_id',$client)->value('id'),
            'origin_lead'=>$this->originLead($client),
            'delegations'=>ClientAccountDelegation::query()->where('client_id',$client)->orderByDesc('starts_at')->get()->map(static fn ($model) => (object) $model->getRawOriginal()),
        ];
    }

    private function originLead(int $client): ?array
    {
        $lead = self::raw(Lead::query()->where('converted_client_id', $client)->first());
        if (!$lead) return null;
        $events = LeadEvent::query()->where('lead_id', $lead->id)->orderBy('created_at')->orderBy('id')->get()->map(static fn ($model) => (object) $model->getRawOriginal());
        $entities = LeadLegalEntity::query()->where('lead_id', $lead->id)->orderBy('name')->get()->map(static fn ($model) => (object) $model->getRawOriginal());
        $contacts = ContactRelation::query()->from('contact_relations as relation')
            ->join('contact_people as contact', 'contact.id', '=', 'relation.contact_person_id')
            ->where('relation.entity_type', 'lead')->where('relation.entity_id', $lead->id)
            ->where('relation.active', true)->select('contact.id', 'contact.full_name', 'contact.position', 'contact.phone', 'contact.email')->get()->map(static fn ($model) => (object) $model->getRawOriginal());
        return [...(array)$lead, 'events'=>$events, 'legal_entities'=>$entities, 'contacts'=>$contacts];
    }

    private function history(int $client): array
    {
        $events = [];
        $clientRow = Client::query()->find($client);
        if ($clientRow?->created_at) {
            $events[]=['id'=>'client-created-'.$client,'type'=>'client_created','title'=>'Создание клиента',
                'text'=>$clientRow->name,'created_at'=>$clientRow->created_at];
        }
        $origin = self::raw(Lead::query()->where('converted_client_id', $client)->first());
        if ($origin) $events[]=['id'=>'origin-lead-'.$origin->id, 'type'=>'lead_origin', 'title'=>'Создан из лида', 'text'=>'История привлечения', 'origin_lead_id'=>(int)$origin->id, 'created_at'=>$clientRow->created_at];
        foreach (Project::query()->where('client_id',$client)->where('is_default',false)->get()->map(static fn ($model) => (object) $model->getRawOriginal()) as $project) {
            $events[]=['id'=>'project-created-'.$project->id,'type'=>'project_created',
                'title'=>'Создание проекта','text'=>$project->name,'created_at'=>$project->created_at];
        }
        foreach (ClientHistoryEvent::query()->where('client_id',$client)->orderBy('created_at')->orderBy('id')->get()->map(static fn ($model) => (object) $model->getRawOriginal()) as $event) {
            $events[]=['id'=>'activity-'.$event->id,'type'=>$event->type,'title'=>$event->title,'text'=>$event->text,'author'=>$event->created_by_username,'created_at'=>$event->created_at];
        }
        $notes = ClientNote::query()->where('client_id', $client)->get()->map(static fn ($model) => (object) $model->getRawOriginal());
        foreach ($notes as $note) {
            $events[] = ['id'=>'note-'.$note->id, 'type'=>'comment','title'=>'Комментарий','text'=>$note->text,'author'=>$note->created_by_username,'created_at'=>$note->created_at];
        }
        $members = ClientMember::query()->where('client_id',$client)->get()->map(static fn ($model) => (object) $model->getRawOriginal());
        $termsByMember = $members->isEmpty() ? collect() : MemberTerm::query()
            ->whereIn('client_member_id',$members->pluck('id'))
            ->orderBy('valid_from')->orderBy('id')->get()->map(static fn ($model) => (object) $model->getRawOriginal())->groupBy('client_member_id');
        foreach ($members as $member) {
            $person = $member->specialist_name ?: 'Сотрудник';
            $project = 'Клиентское подключение';
            $terms = $termsByMember->get($member->id, collect());
            foreach ($terms as $index => $term) {
                $events[] = [
                    'id'=>'term-'.$term->id, 'type'=>$index===0?'connection':'rate_revision',
                    'title'=>$index===0?'Подключение специалиста':'Пересмотр условий и ставки',
                    'text'=>$person.' · '.$project.' · '.($term->hourly_rate === null ? 'Ставка неизвестна' : number_format((float)$term->hourly_rate,0,',',' ').' ₽/ч'),
                    'created_at'=>$term->created_at ?: $term->valid_from,
                ];
            }
            // The last recorded end date is a planned end, not proof of an actual disconnect.
            // Show only connection lifecycle changes that can be verified from persisted records.
            if ($terms->isNotEmpty()) {
                $last=$terms->last();
                if ($last->valid_to && substr((string)$last->valid_to,0,10) <= now()->toDateString()) {
                    $events[]=['id'=>'end-'.$member->id,'type'=>'connection_end','title'=>'Истечение срока подключения','text'=>$person.' · '.$project,'created_at'=>$last->valid_to.' 23:59:59'];
                }
            }
        }
        $periods = ReportingPeriod::query()->where('client_id',$client)->get()->map(static fn ($model) => (object) $model->getRawOriginal());
        $stages = [
            'timesheets_sent_at'=>'Таймшиты отправлены клиенту',
            'timesheets_approved_at'=>'Клиент согласовал таймшиты',
            'act_sent_at'=>'Акт отправлен клиенту',
            'act_approved_at'=>'Клиент согласовал акт',
            'paid_at'=>'Оплата счёта',
        ];
        foreach ($periods as $period) {
            $periodName='Период '.substr((string)$period->period_start,0,10).' — '.substr((string)$period->period_end,0,10);
            if ($period->created_at) {
                $events[]=['id'=>'period-created-'.$period->id,'type'=>'period_created','title'=>'Создание отчётного периода',
                    'text'=>$periodName,'created_at'=>$period->created_at];
            }
            foreach ($stages as $column=>$title) {
                if (empty($period->$column)) continue;
                $events[]=['id'=>'period-'.$period->id.'-'.$column,'type'=>'reporting_period','title'=>$title,'text'=>$periodName,'created_at'=>$period->$column];
            }
        }
        usort($events,static fn($a,$b)=>strcmp((string)$a['created_at'],(string)$b['created_at']) ?: (($a['type']==='lead_origin' ? -1 : 0) <=> ($b['type']==='lead_origin' ? -1 : 0)) ?: strcmp((string)$a['id'],(string)$b['id']));
        return $events;
    }


}
