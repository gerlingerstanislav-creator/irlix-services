<?php

namespace App\Http\Controllers;

use App\Support\ClientContourAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientMembersController extends Controller
{
    private function allow(Request $request, string $permission, ?int $clientId = null): void
    {
        if ($request->attributes->get('clients_scope_authorized') === true) return;
        $permission = str_replace('clients.', 'members.', $permission);
        $resolver = app(ClientContourAccess::class);
        $access = ($request->attributes->get('client_contour_access') ?? $resolver->resolve($request));
        if ($access['platform_admin'] ?? false) return;
        abort_unless($resolver->allows($access, $permission), 403, 'Недостаточно прав.');
        $scope = $resolver->scope($access, $permission);
        if ($scope === 'all') return;
        if ($clientId !== null && $scope === 'own') {
            $actor = (int)($access['employee']['id'] ?? 0);
            $client = DB::table('clients')->find($clientId);
            abort_unless($client && ($actor === (int)$client->account_employee_id || $actor === (int)$client->sales_employee_id),403,'Клиент вне области доступа.');
            return;
        }
        abort(403, 'Доступ к подключению вне области доступа.');
    }
    private function member(int $id): object
    {
        $row = DB::table('client_members')->find($id);
        abort_unless($row, 404, 'Подключение не найдено.');
        return $row;
    }
    private function payload(object $member): array
    {
        return [...(array)$member,
            'terms'=>DB::table('member_terms')->where('client_member_id',$member->id)->orderByDesc('valid_from')->get(),
            'projects'=>DB::table('project_assignments as a')->join('projects as p','p.id','=','a.project_id')
                ->where('a.client_member_id',$member->id)->select('a.*','p.name as project_name')->orderByDesc('a.valid_from')->get(),
            'feedbacks'=>DB::table('client_member_feedbacks')->where('client_member_id',$member->id)->orderByDesc('created_at')->get(),
        ];
    }
    public function index(Request $request, int $client)
    {
        $this->allow($request,'clients.view',$client);
        abort_unless(DB::table('clients')->where('id',$client)->exists(),404);
        return response()->json(['data'=>DB::table('client_members')->where('client_id',$client)->orderBy('specialist_name')
            ->get()->map(fn($member)=>$this->payload($member))->values()]);
    }
    public function show(Request $request, int $member)
    {
        $m = $this->member($member);
        $this->allow($request,'clients.view',(int)$m->client_id);
        return response()->json(['data'=>$this->payload($m)]);
    }
    public function store(Request $request, int $client)
    {
        $this->allow($request,'clients.manage',$client);
        abort_unless(DB::table('clients')->where('id',$client)->exists(),404);
        $data=$request->validate([
            'employee_id'=>['nullable','integer','min:1'],
            'partner_specialist_id'=>['nullable','integer',Rule::exists('partner_specialists','id')],
            'specialist_name'=>['nullable','string','max:255'],
            'source_attempt_id'=>['nullable','integer','min:1'],
        ]);
        abort_if((!empty($data['employee_id'])) === (!empty($data['partner_specialist_id'])),422,'Выберите ровно один тип специалиста.');
        $partner = !empty($data['partner_specialist_id'])
            ? DB::table('partner_specialists')->where('id',$data['partner_specialist_id'])->where('active',true)->first() : null;
        if (!empty($data['partner_specialist_id'])) abort_unless($partner && $partner->partner_id && DB::table('partners')->where('id',$partner->partner_id)->where('active',true)->exists(),422,'Специалист должен быть активным и принадлежать партнёру.');
        $name=trim((string)($partner?->full_name ?? ($data['specialist_name']??'')));
        abort_if($name==='',422,'Требуется ФИО специалиста.');
        $where=['client_id'=>$client, $partner?'partner_specialist_id':'employee_id'=>$partner?->id??$data['employee_id']];
        $id=DB::transaction(function()use($where,$client,$partner,$data,$name){
            DB::table('clients')->where('id',$client)->lockForUpdate()->first();
            $existing=DB::table('client_members')->where($where)->lockForUpdate()->first();
            $id=$existing?->id??DB::table('client_members')->insertGetId([
                'client_id'=>$client,'employee_id'=>$partner?null:$data['employee_id'],
                'partner_specialist_id'=>$partner?->id,'specialist_name'=>$name,
                'created_at'=>now(),'updated_at'=>now()
            ]);
            if(!empty($data['source_attempt_id'])) {
                $attempt=DB::table('connection_attempts as a')->join('positions as p','p.id','=','a.position_id')
                    ->join('client_requests as r','r.id','=','p.client_request_id')->where('a.id',$data['source_attempt_id'])
                    ->select('a.*','r.client_id as attempt_client_id')->lockForUpdate()->first();
                abort_unless($attempt && (int)$attempt->attempt_client_id===$client,422,'Попытка относится к другому клиенту.');
                abort_unless((bool)$attempt->is_external===($partner!==null) && ($partner || (int)$attempt->specialist_id===(int)$data['employee_id']),422,'Личность не соответствует попытке.');
                abort_if($attempt->client_member_id && (int)$attempt->client_member_id!==(int)$id,422,'Попытка уже связана с другим подключением.');
                abort_unless(in_array($attempt->status,['Ожидает подключения','Закрыт: успех'],true),422,'Попытка не готова к подключению.');
                DB::table('connection_attempts')->where('id',$attempt->id)->update(['client_member_id'=>$id,'updated_at'=>now()]);
            }
            return $id;
        });
        return response()->json(['data'=>$this->payload($this->member($id))],201);
    }
    public function addTerm(Request $request, int $member)
    {
        $m=$this->member($member);$this->allow($request,'clients.manage',(int)$m->client_id);
        $data=$request->validate([
            'technology'=>['required','string','max:100'],'level'=>['required','string','max:100'],
            'hourly_rate'=>['required','numeric','min:0'],'hours_per_day'=>['required','numeric','min:0','max:24'],
            'valid_from'=>['required','date'],'valid_to'=>['nullable','date','after_or_equal:valid_from']
        ]);
        $id=DB::transaction(function()use($member,$data){
            DB::table('clients')->where('id',$this->member($member)->client_id)->lockForUpdate()->first();
            DB::table('client_members')->where('id',$member)->lockForUpdate()->first();
            \App\Support\MemberIdentity::assertPeriodEditable((int)$this->member($member)->client_id,$data['valid_from'],$data['valid_to']??null);
            $overlap=DB::table('member_terms')->where('client_member_id',$member)
                ->whereDate('valid_from','<=',$data['valid_to']??'9999-12-31')
                ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$data['valid_from']))->exists();
            abort_if($overlap,422,'Пересечение периодов коммерческих условий.');
            return DB::table('member_terms')->insertGetId([...$data,'client_member_id'=>$member,
                'created_at'=>now(),'updated_at'=>now()]);
        });
        return response()->json(['data'=>DB::table('member_terms')->find($id)],201);
    }
    public function addProjectAssignment(Request $request, int $member)
    {
        $m=$this->member($member);$this->allow($request,'clients.manage',(int)$m->client_id);
        $data=$request->validate([
            'project_id'=>['required','integer','min:1'],
            'valid_from'=>['required','date'],'valid_to'=>['nullable','date','after_or_equal:valid_from'],
        ]);
        abort_unless(DB::table('projects')->where('id',$data['project_id'])->where('client_id',$m->client_id)->exists(),422,'Проект принадлежит другому клиенту.');
        $id=DB::transaction(function()use($member,$data){
            DB::table('clients')->where('id',$this->member($member)->client_id)->lockForUpdate()->first();
            DB::table('client_members')->where('id',$member)->lockForUpdate()->first();
            $overlap=DB::table('project_assignments')->where('client_member_id',$member)->where('project_id',$data['project_id'])
                ->whereDate('valid_from','<=',$data['valid_to']??'9999-12-31')
                ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$data['valid_from']))->exists();
            abort_if($overlap,422,'Участие в этом проекте уже существует на указанный период.');
            return DB::table('project_assignments')->insertGetId([...$data,'client_member_id'=>$member,
                'created_at'=>now(),'updated_at'=>now()]);
        });
        return response()->json(['data'=>DB::table('project_assignments')->find($id)],201);
    }
    public function addFeedback(Request $request, int $member)
    {
        $m=$this->member($member);$this->allow($request,'clients.manage',(int)$m->client_id);
        $data=$request->validate(['project_id'=>['nullable','integer','min:1'],
            'text'=>['required','string','max:10000']]);
        if (!empty($data['project_id'])) {
            abort_unless(DB::table('projects')->where('id',$data['project_id'])->where('client_id',$m->client_id)->exists(),422,'Проект не принадлежит клиенту.');
            $dated=DB::table('project_assignments')->where('client_member_id',$member)->where('project_id',$data['project_id'])
                ->whereDate('valid_from','<=',now()->toDateString())->exists();
            $historical=DB::table('project_members')->where('project_id',$data['project_id'])
                ->where($m->partner_specialist_id!==null?'partner_specialist_id':'specialist_id',$m->partner_specialist_id??$m->employee_id)->exists();
            abort_unless($dated||$historical,422,'Специалист никогда не участвовал в этом проекте.');
        }
        abort_if(trim($data['text']) === '',422,'Текст обратной связи обязателен.');
        $id=DB::table('client_member_feedbacks')->insertGetId([
            'client_member_id'=>$member,'project_id'=>$data['project_id']??null,
            'text'=>trim($data['text']), 'created_by_username'=>$request->attributes->get('identity',[])['preferred_username']??null,
            'created_at'=>now(),'updated_at'=>now()
        ]);
        return response()->json(['data'=>DB::table('client_member_feedbacks')->find($id)],201);
    }
    public function updateTerm(Request $request,int $term)
    {
        $term=\App\Support\MemberIdentity::canonicalTermId($term);
        $row=DB::table('member_terms')->find($term);
        abort_unless($row&&$row->client_member_id,404,'Коммерческие условия не найдены.');
        $m=$this->member((int)$row->client_member_id);
        $this->allow($request,'clients.manage',(int)$m->client_id);
        $data=$request->validate([
            'technology'=>['sometimes','required','string','max:100'],
            'level'=>['sometimes','required','string','max:100'],
            'hourly_rate'=>['sometimes','required','numeric','min:0'],
            'hours_per_day'=>['sometimes','required','numeric','min:0','max:24'],
            'valid_from'=>['sometimes','required','date'],
            'valid_to'=>['sometimes','nullable','date']
        ]);
        $from=$data['valid_from']??substr((string)$row->valid_from,0,10);
        $to=array_key_exists('valid_to',$data)?$data['valid_to']:$row->valid_to;
        abort_if($to!==null&&$to<$from,422,'Дата окончания раньше даты начала.');
        return DB::transaction(function()use($m,$row,$term,$data,$from,$to){
            DB::table('clients')->where('id',$m->client_id)->lockForUpdate()->first();
            DB::table('client_members')->where('id',$m->id)->lockForUpdate()->first();
            $row=DB::table('member_terms')->where('id',$term)->lockForUpdate()->first();
            abort_unless($row && (int)$row->client_member_id===(int)$m->id,409,'Условия подключения изменились.');
            $from=$data['valid_from']??substr((string)$row->valid_from,0,10);
            $to=array_key_exists('valid_to',$data)?$data['valid_to']:$row->valid_to;
            abort_if($to!==null&&$to<$from,422,'Дата окончания раньше даты начала.');
            \App\Support\MemberIdentity::assertTermChangeEditable((int)$m->client_id,$row,$data,$from,$to);
            $overlap=DB::table('member_terms')->where('client_member_id',$m->id)->where('id','<>',$term)
                ->whereDate('valid_from','<=',$to??'9999-12-31')
                ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$from))->exists();
            abort_if($overlap,422,'Условия пересекаются с другой версией.');
            DB::table('member_terms')->where('id',$term)->update([...$data,'updated_at'=>now()]);
            return response()->json(['data'=>DB::table('member_terms')->find($term)]);
        });
    }

    public function updateProjectAssignment(Request $request,int $assignment)
    {
        $row=DB::table('project_assignments')->find($assignment);
        abort_unless($row,404);
        $m=$this->member((int)$row->client_member_id);
        $this->allow($request,'clients.manage',(int)$m->client_id);
        $data=$request->validate([
            'project_id'=>['sometimes','required','integer','min:1'],
            'valid_from'=>['sometimes','required','date'],
            'valid_to'=>['sometimes','nullable','date']
        ]);
        $project=$data['project_id']??$row->project_id;
        $from=$data['valid_from']??substr((string)$row->valid_from,0,10);
        $to=array_key_exists('valid_to',$data)?$data['valid_to']:$row->valid_to;
        abort_if($to!==null&&$to<$from,422,'Дата окончания раньше начала.');
        abort_unless(DB::table('projects')->where('id',$project)->where('client_id',$m->client_id)->exists(),
            422,'Проект принадлежит другому клиенту.');
        return DB::transaction(function()use($m,$row,$assignment,$project,$from,$to,$data){
            DB::table('clients')->where('id',$m->client_id)->lockForUpdate()->first();
            DB::table('client_members')->where('id',$m->id)->lockForUpdate()->first();
            $overlap=DB::table('project_assignments')->where('client_member_id',$m->id)
                ->where('id','<>',$assignment)->where('project_id',$project)
                ->whereDate('valid_from','<=',$to??'9999-12-31')
                ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$from))->exists();
            abort_if($overlap,422,'Пересечение назначений на один проект.');
            DB::table('project_assignments')->where('id',$assignment)->update([...$data,'updated_at'=>now()]);
            return response()->json(['data'=>DB::table('project_assignments')->find($assignment)]);
        });
    }

}
