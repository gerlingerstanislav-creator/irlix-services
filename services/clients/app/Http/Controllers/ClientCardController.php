<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientCardController extends Controller
{
    public function show(int $client)
    {
        $clientRow = DB::table('clients')->find($client);
        abort_unless($clientRow, 404, 'Client not found');
        $contacts = DB::table('contact_people')->join('contact_relations','contact_relations.contact_person_id','=','contact_people.id')->where('contact_relations.entity_type','client')->where('contact_relations.entity_id',$client)->where('contact_relations.active',true)->orderBy('contact_people.full_name')->select(['contact_people.id','contact_people.full_name','contact_people.position','contact_people.phone','contact_people.email','contact_relations.id as relation_id','contact_relations.relation_role','contact_relations.comment as relation_comment'])->get();
        return response()->json(['data'=>[
            'client'=>$this->serializeClient($clientRow),
            'projects'=>DB::table('projects')->where('client_id',$client)->orderByDesc('is_default')->orderBy('name')->get(),
            'contacts'=>$contacts,
            'reporting_periods'=>DB::table('reporting_periods')->where('client_id',$client)->orderByDesc('period_start')->get(),
            'legal_entities'=>DB::table('client_legal_entities')->where('client_id',$client)->orderBy('name')->get(),
            'notes'=>DB::table('client_notes')->where('client_id',$client)->orderByDesc('created_at')->orderByDesc('id')->get(),
            'history'=>$this->history($client),
        ]]);
    }

    private function history(int $client): array
    {
        $events = [];
        $clientRow = DB::table('clients')->find($client);
        if ($clientRow?->created_at) {
            $events[]=['id'=>'client-created-'.$client,'type'=>'client_created','title'=>'Создание клиента',
                'text'=>$clientRow->name,'created_at'=>$clientRow->created_at];
        }
        foreach (DB::table('projects')->where('client_id',$client)->where('is_default',false)->get() as $project) {
            $events[]=['id'=>'project-created-'.$project->id,'type'=>'project_created',
                'title'=>'Создание проекта','text'=>$project->name,'created_at'=>$project->created_at];
        }
        foreach (DB::table('client_history_events')->where('client_id',$client)->orderBy('created_at')->orderBy('id')->get() as $event) {
            $events[]=['id'=>'activity-'.$event->id,'type'=>$event->type,'title'=>$event->title,'text'=>$event->text,'author'=>$event->created_by_username,'created_at'=>$event->created_at];
        }
        $notes = DB::table('client_notes')->where('client_id', $client)->get();
        foreach ($notes as $note) {
            $events[] = ['id'=>'note-'.$note->id, 'type'=>'comment','title'=>'Комментарий','text'=>$note->text,'author'=>$note->created_by_username,'created_at'=>$note->created_at];
        }
        $members = DB::table('project_members as pm')
            ->join('projects as p','p.id','=','pm.project_id')
            ->where('p.client_id',$client)
            ->select('pm.*','p.name as project_name')->get();
        $termsByMember = $members->isEmpty() ? collect() : DB::table('member_terms')
            ->whereIn('project_member_id',$members->pluck('id'))
            ->orderBy('valid_from')->orderBy('id')->get()->groupBy('project_member_id');
        foreach ($members as $member) {
            $person = $member->specialist_name ?: 'Сотрудник';
            $project = $member->project_name ?: 'Основной проект';
            $terms = $termsByMember->get($member->id, collect());
            foreach ($terms as $index => $term) {
                $events[] = [
                    'id'=>'term-'.$term->id, 'type'=>$index===0?'connection':'rate_revision',
                    'title'=>$index===0?'Подключение специалиста':'Пересмотр условий и ставки',
                    'text'=>$person.' · '.$project.' · '.number_format((float)$term->hourly_rate,0,',',' ').' ₽/ч',
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
        $periods = DB::table('reporting_periods')->where('client_id',$client)->get();
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
        usort($events,static fn($a,$b)=>strcmp((string)$a['created_at'],(string)$b['created_at']) ?: strcmp((string)$a['id'],(string)$b['id']));
        return $events;
    }

    public function updateProject(Request $request, int $project)
    {
        $row = DB::table('projects')->find($project);
        abort_unless($row, 404, 'Project not found');
        abort_if((bool) $row->is_default, 422, 'Основной проект нельзя переименовать.');
        $data = $request->validate(['name'=>['required','string','max:255']]);
        DB::table('projects')->where('id',$project)->update(['name'=>trim($data['name']),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('projects')->find($project)]);
    }

    public function destroyProject(int $project)
    {
        $row = DB::table('projects')->find($project);
        abort_unless($row, 404, 'Project not found');
        abort_if((bool) $row->is_default, 422, 'Основной проект нельзя удалить.');
        abort_if(DB::table('project_members')->where('project_id',$project)->exists(), 422, 'Нельзя удалить проект с участниками.');
        DB::table('projects')->where('id',$project)->delete();
        return response()->noContent();
    }

    public function update(Request $request, int $client)
    {
        abort_unless(DB::table('clients')->where('id',$client)->exists(),404,'Client not found');
        $data=$request->validate([
            'name'=>['sometimes','required','string','max:255'],'type'=>['sometimes','nullable','string','max:100'],'sector'=>['sometimes','nullable','string','max:150'],'sales_employee_id'=>['prohibited'],'account_employee_id'=>['prohibited'],'description'=>['sometimes','nullable','string','max:20000'],'act_approval_days'=>['sometimes','nullable','integer','min:0','max:3650'],'payment_days'=>['sometimes','nullable','integer','min:0','max:3650'],'technologies'=>['sometimes','array'],'technologies.*'=>['string','max:100'],
        ]);
        if(array_key_exists('technologies',$data))$data['technologies']=json_encode(array_values(array_unique(array_filter(array_map('trim',$data['technologies'])))),JSON_UNESCAPED_UNICODE);
        DB::transaction(function () use ($client,$data,$request): void {
            $before=DB::table('clients')->where('id',$client)->lockForUpdate()->first();
            DB::table('clients')->where('id',$client)->update([...$data,'updated_at'=>now()]);
            $labels=['name'=>'Название клиента','type'=>'Тип клиента','sector'=>'Сектор клиента',
                'sales_employee_id'=>'Sales-менеджер','description'=>'Описание клиента',
                'act_approval_days'=>'Срок согласования акта','payment_days'=>'Срок оплаты','technologies'=>'Технологии'];
            foreach ($data as $key=>$value) {
                if ((string)($before->$key ?? '') === (string)($value ?? '')) continue;
                \App\Support\ClientHistory::record($client,'client_updated','Изменение клиента',
                    ($labels[$key] ?? $key).': данные обновлены',
                    $request->attributes->get('identity',[])['preferred_username'] ?? null);
            }
        });
        return response()->json(['data'=>$this->serializeClient(DB::table('clients')->find($client))]);
    }

    public function updateContact(Request $request,int $contact)
    {
        abort_unless(DB::table('contact_people')->where('id',$contact)->exists(),404,'Contact not found');
        $data=$request->validate(['full_name'=>['sometimes','required','string','max:255'],'position'=>['sometimes','nullable','string','max:255'],'phone'=>['sometimes','nullable','string','max:100'],'email'=>['sometimes','nullable','email','max:255']]);
        DB::table('contact_people')->where('id',$contact)->update([...$data,'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('contact_people')->find($contact)]);
    }

    public function storeLegalEntity(Request $request,int $client)
    {
        abort_unless(DB::table('clients')->where('id',$client)->exists(),404,'Client not found');
        $data=$request->validate($this->legalEntityRules(false));
        $id=DB::table('client_legal_entities')->insertGetId(['client_id'=>$client,...$this->normalizeLegalEntity($data),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('client_legal_entities')->find($id)],201);
    }

    public function updateLegalEntity(Request $request,int $entity)
    {
        abort_unless(DB::table('client_legal_entities')->where('id',$entity)->exists(),404,'Legal entity not found');
        $data=$request->validate($this->legalEntityRules(true));
        DB::table('client_legal_entities')->where('id',$entity)->update([...$this->normalizeLegalEntity($data),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('client_legal_entities')->find($entity)]);
    }

    public function storeNote(Request $request,int $client)
    {
        abort_unless(DB::table('clients')->where('id',$client)->exists(),404,'Client not found');
        $data=$request->validate(['text'=>['required','string','max:20000']]);$text=trim($data['text']);abort_if($text==='',422,'Note text is required');$identity=$request->attributes->get('identity',[]);
        $id=DB::table('client_notes')->insertGetId(['client_id'=>$client,'text'=>$text,'created_by_username'=>$identity['preferred_username']??null,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('client_notes')->find($id)],201);
    }

    private function legalEntityRules(bool $partial):array
    {
        $presence=$partial?'sometimes':'required';
        return ['name'=>[$presence,'required','string','max:255'],'inn'=>[$presence,'required','string','max:32'],'full_name'=>[$presence,'required','string','max:500'],'ogrn'=>['sometimes','nullable','string','max:32'],'kpp'=>['sometimes','nullable','string','max:32'],'registration_date'=>['sometimes','nullable','date'],'okpo'=>['sometimes','nullable','string','max:32'],'oktmo'=>['sometimes','nullable','string','max:32'],'address'=>['sometimes','nullable','string','max:2000']];
    }

    private function normalizeLegalEntity(array $data):array
    {
        foreach(['name','inn','full_name','ogrn','kpp','okpo','oktmo','address'] as $field){if(!array_key_exists($field,$data))continue;$value=trim((string)$data[$field]);$data[$field]=in_array($field,['name','inn','full_name'],true)?$value:($value===''?null:$value);}return $data;
    }

    private function serializeClient(object $client):array
    {
        $row=ClientLogoController::serialize($client);$row['technologies']=$client->technologies?(json_decode($client->technologies,true)?:[]):[];return $row;
    }
}
