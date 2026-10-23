<?php

namespace App\Http\Controllers;

use App\Support\ClientContourAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientMembersController extends Controller
{
    /**
     * This is the new client-level connection contract. A participant is linked
     * to the client, not to a project, and has one identity for its full lifetime.
     */
    private function check(Request $request, ClientContourAccess $resolver, int $clientId, bool $write): object
    {
        $client = DB::table('clients')->find($clientId);
        abort_unless($client, 404, 'Клиент не найден.');
        $access = $resolver->resolve($request);
        $permission = $write ? 'members.manage' : 'members.view';
        $level = (string)($access['permissions'][$permission]['scope'] ?? 'none');
        abort_unless($access['platform_admin'] || ($access['permissions'][$permission]['allowed'] ?? false), 403, 'Нет доступа к подключениям.');
        if ($access['platform_admin'] || $level === 'all') return $client;
        $employeeId = (int) ($access['employee']['id'] ?? 0);
        $team = array_map('intval', $access['team_employee_ids'] ?? []);
        $candidateIds = [$employeeId];
        if ($level === 'team') $candidateIds = array_values(array_unique([...$candidateIds,...$team]));
        abort_unless(($level === 'own' || $level === 'team') && (
            in_array((int) ($client->account_employee_id ?? 0), $candidateIds, true)
            || (!$client->account_employee_id && in_array((int) ($client->sales_employee_id ?? 0), $candidateIds, true))
            || DB::table('client_account_delegations')->where('client_id', $clientId)
                ->whereIn('to_employee_id', $candidateIds)->whereNull('revoked_at')
                ->whereDate('starts_at', '<=', today())->whereDate('ends_at', '>=', today())->exists()
        ), 403, 'Клиент вне области доступа.');
        return $client;
    }

    public function index(Request $request, ClientContourAccess $resolver, int $client)
    {
        $this->check($request, $resolver, $client, false);
        $rows = DB::table('client_members')->where('client_id', $client)->orderBy('specialist_name')->get();
        return response()->json(['data' => $rows->map(fn($row) => [...(array)$row,
            'terms' => DB::table('member_terms')->where('client_member_id',$row->id)->orderBy('valid_from')->get(),
            'projects' => DB::table('project_assignments as pa')->join('projects as p','p.id','=','pa.project_id')
                ->where('pa.client_member_id',$row->id)->get(['pa.id','pa.project_id','p.name as project_name','pa.valid_from','pa.valid_to']),
        ])]);
    }

    public function store(Request $request, ClientContourAccess $resolver, int $client)
    {
        $this->check($request, $resolver, $client, true);
        $data = $request->validate([
            'specialist_type' => ['required', Rule::in(['employee','partner'])],
            'employee_id' => ['required_if:specialist_type,employee','nullable','integer','min:1'],
            'partner_specialist_id' => ['required_if:specialist_type,partner','nullable','integer', 'min:1', Rule::exists('partner_specialists','id')],
            'technology' => ['required','string','max:100'],
            'level' => ['required','string','max:100'],
            'hourly_rate' => ['required','numeric','min:0','max:9999999999.99'],
            'hours_per_day' => ['required','numeric','min:0','max:24'],
            'valid_from' => ['required','date_format:Y-m-d'],
            'valid_to' => ['nullable','date_format:Y-m-d','after_or_equal:valid_from'],
        ]);
        $partner = $data['specialist_type']==='partner'
            ? DB::table('partner_specialists')->find((int)$data['partner_specialist_id']) : null;
        if ($data['specialist_type']==='employee') {
            abort_if(!empty($data['partner_specialist_id']), 422, 'Нельзя смешивать типы личности.');
            // Full Employees identity/name validation is handled by the Employees directory
            // in the permanent employee-member migration. Do not create a guessed snapshot here.
            abort(422, 'Создание штатных ClientMember станет доступно после миграции Employees-подключений.');
        }
        abort_if(!empty($data['employee_id']), 422, 'Партнёр не является штатным сотрудником.');
        abort_unless($partner, 422);
        $memberId=DB::transaction(function() use ($client,$partner,$data) {
            DB::table('clients')->where('id',$client)->lockForUpdate()->first();
            $member = DB::table('client_members')->where('client_id',$client)
                ->where('partner_specialist_id',$partner->id)->lockForUpdate()->first();
            if (!$member) {
                $memberId = DB::table('client_members')->insertGetId([
                    'client_id'=>$client, 'employee_id'=>null, 'partner_specialist_id'=>$partner->id,
                    'specialist_name'=>$partner->full_name, 'created_at'=>now(), 'updated_at'=>now()
                ]);
            } else $memberId=(int)$member->id;
            $this->addTerms($memberId,$data);
            return $memberId;
        });
        return response()->json(['data'=>['client_member_id'=>$memberId]], 201);
    }

    public function storeTerms(Request $request, ClientContourAccess $resolver, int $member)
    {
        $record = DB::table('client_members')->find($member);
        abort_unless($record,404,'Подключение не найдено.');
        $this->check($request,$resolver,(int)$record->client_id,true);
        $data = $request->validate([
            'technology' => ['required','string','max:100'],
            'level' => ['required','string','max:100'],
            'hourly_rate' => ['required','numeric','min:0','max:9999999999.99'],
            'hours_per_day' => ['required','numeric','min:0','max:24'],
            'valid_from' => ['required','date_format:Y-m-d'],
            'valid_to' => ['nullable','date_format:Y-m-d','after_or_equal:valid_from'],
        ]);
        $termId = DB::transaction(function () use ($member,$data) {
            DB::table('client_members')->where('id',$member)->lockForUpdate()->first();
            return $this->addTerms($member,$data);
        });
        return response()->json(['data'=>DB::table('member_terms')->find($termId)],201);
    }

    private function addTerms(int $member, array $data): int
    {
        $from=$data['valid_from'];
        $to=$data['valid_to'] ?? null;
        $conflict=DB::table('member_terms')->where('client_member_id',$member)
            ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$from))
            ->when($to,fn($q)=>$q->whereDate('valid_from','<=',$to))
            ->exists();
        abort_if($conflict,422,'Условия подключения пересекаются с существующим периодом.');
        $clientId=(int)DB::table('client_members')->where('id',$member)->value('client_id');
        $lock=DB::table('reporting_periods')->where('client_id',$clientId)
            ->where('status','<>','Новый')
            ->whereDate('period_end','>=',$from)
            ->when($to,fn($q)=>$q->whereDate('period_start','<=',$to))
            ->exists();
        abort_if($lock,423,'Даты пересекаются с защищённым отчётным периодом.');
        return DB::table('member_terms')->insertGetId([
            'client_member_id'=>$member,'project_member_id'=>null,
            'technology'=>$data['technology'],'level'=>$data['level'],
            'hourly_rate'=>$data['hourly_rate'],'hours_per_day'=>$data['hours_per_day'],
            'valid_from'=>$from,'valid_to'=>$to,
            'created_at'=>now(),'updated_at'=>now()
        ]);
    }

    public function feedbacks(Request $request, ClientContourAccess $resolver, int $member)
    {
        $row=DB::table('client_members')->find($member);
        abort_unless($row,404);
        $this->check($request,$resolver,(int)$row->client_id,false);
        return response()->json(['data'=>DB::table('client_member_feedbacks')
            ->where('client_member_id',$member)->orderByDesc('created_at')->orderByDesc('id')->get()]);
    }

    public function addFeedback(Request $request, ClientContourAccess $resolver, int $member)
    {
        $row=DB::table('client_members')->find($member);
        abort_unless($row,404);
        $this->check($request,$resolver,(int)$row->client_id,true);
        $data=$request->validate([
            'text'=>['required','string','max:10000'],
            'project_id'=>['nullable','integer',Rule::exists('projects','id')],
        ]);
        if(!empty($data['project_id'])){
            abort_unless(DB::table('projects')->where('id',$data['project_id'])
                ->where('client_id',$row->client_id)->exists(),422,'Проект принадлежит другому клиенту.');
            abort_unless(DB::table('project_assignments')->where('project_id',$data['project_id'])
                ->where('client_member_id',$member)->exists(),422,'Для проектного фидбека нужно историческое участие.');
        }
        $id=DB::table('client_member_feedbacks')->insertGetId([
            'client_member_id'=>$member,'project_id'=>$data['project_id']??null,
            'text'=>$data['text'],
            'created_by_username'=>$request->attributes->get('identity',[])['preferred_username']??null,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return response()->json(['data'=>DB::table('client_member_feedbacks')->find($id)],201);
    }

    public function projectAssignment(Request $request, ClientContourAccess $resolver, int $member)
    {
        $record=DB::table('client_members')->find($member);
        abort_unless($record,404);
        $this->check($request,$resolver,(int)$record->client_id,true);
        $data=$request->validate([
            'project_id'=>['required','integer',Rule::exists('projects','id')],
            'valid_from'=>['required','date_format:Y-m-d'],
            'valid_to'=>['nullable','date_format:Y-m-d','after_or_equal:valid_from'],
        ]);
        abort_unless(DB::table('projects')->where('id',$data['project_id'])
            ->where('client_id',$record->client_id)->exists(),422,'Проект принадлежит другому клиенту.');
        $id=DB::transaction(function()use($member,$data) {
            DB::table('client_members')->where('id',$member)->lockForUpdate()->first();
            $exists=DB::table('project_assignments')->where('client_member_id',$member)
                ->where('project_id',$data['project_id'])
                ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$data['valid_from']))
                ->when($data['valid_to']??null,fn($q)=>$q->whereDate('valid_from','<=',$data['valid_to']))
                ->exists();
            abort_if($exists,422,'Периоды назначения на проект пересекаются.');
            return DB::table('project_assignments')->insertGetId([...$data,
                'client_member_id'=>$member,'created_at'=>now(),'updated_at'=>now()]);
        });
        return response()->json(['data'=>DB::table('project_assignments')->find($id)],201);
    }
}
