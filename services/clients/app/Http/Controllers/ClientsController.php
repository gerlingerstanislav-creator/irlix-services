<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientsController extends Controller
{
    public function overview()
    {
        $clients = DB::table('clients')->orderBy('name')->get()->map(function ($client) {
            $projects = DB::table('projects')->where('client_id',$client->id)->orderByDesc('is_default')->orderBy('name')->get()->map(function($project){
                $members = DB::table('project_members')->where('project_id',$project->id)->orderBy('specialist_name')->get()->map(function($member){
                    $terms = DB::table('member_terms')->where('project_member_id',$member->id)->orderBy('valid_from')->get();
                    return [...(array)$member,'terms'=>$terms];
                });
                return [...(array)$project,'members'=>$members];
            });
            return [...(array)$client,'projects'=>$projects];
        });
        $leads=DB::table('leads')->orderByDesc('created_at')->get();
        $requests=DB::table('client_requests')->orderByDesc('created_at')->get()->map(function($request){
            $positions=DB::table('positions')->where('client_request_id',$request->id)->get()->map(function($position){
                return [...(array)$position,'attempts'=>DB::table('connection_attempts')->where('position_id',$position->id)->get()];
            });
            return [...(array)$request,'positions'=>$positions];
        });
        $reportingPeriods=DB::table('reporting_periods')->orderByDesc('period_start')->get();
        return response()->json(['data'=>compact('clients','leads','requests','reportingPeriods')]);
    }

    public function storeClient(Request $request)
    {
        $data=$request->validate(['name'=>'required|string|max:255','type'=>'nullable|string|max:100','sector'=>'nullable|string|max:150','sales_employee_id'=>'nullable|integer','account_employee_id'=>'required|integer']);
        $id=DB::transaction(function()use($data){$id=DB::table('clients')->insertGetId([...$data,'created_at'=>now(),'updated_at'=>now()]);DB::table('projects')->insert(['client_id'=>$id,'name'=>null,'is_default'=>true,'created_at'=>now(),'updated_at'=>now()]);return $id;});
        return response()->json(['data'=>DB::table('clients')->find($id)],201);
    }

    public function storeLead(Request $request)
    {
        $statuses=['Новый лид','Первичный контакт','Уточнение потребностей','КП отправлено','Активные переговоры','Клиент в игноре','Сделка закрыта - Успех','Сделка закрыта - Отказ'];
        $data=$request->validate(['name'=>'required|string|max:255','source'=>'nullable|string|max:255','responsible_employee_id'=>'required|integer','status'=>['required',Rule::in($statuses)]]);
        $id=DB::table('leads')->insertGetId([...$data,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('leads')->find($id)],201);
    }

    public function storeMember(Request $request, int $project)
    {
        $data=$request->validate(['specialist_id'=>'required|integer','specialist_name'=>'required|string|max:255','source_attempt_id'=>'nullable|integer','technology'=>'required|string|max:100','level'=>'required|string|max:100','hourly_rate'=>'required|numeric|min:0','hours_per_day'=>'required|numeric|min:0|max:24','valid_from'=>'required|date','valid_to'=>'nullable|date|after_or_equal:valid_from']);
        $result=DB::transaction(function()use($data,$project){$member=DB::table('project_members')->where(['project_id'=>$project,'specialist_id'=>$data['specialist_id']])->first();if(!$member){$memberId=DB::table('project_members')->insertGetId(['project_id'=>$project,'specialist_id'=>$data['specialist_id'],'specialist_name'=>$data['specialist_name'],'source_attempt_id'=>$data['source_attempt_id']??null,'created_at'=>now(),'updated_at'=>now()]);}else{$memberId=$member->id;}$this->assertTermsAvailable($memberId,$data['valid_from'],$data['valid_to']??null);DB::table('member_terms')->insert(['project_member_id'=>$memberId,'technology'=>$data['technology'],'level'=>$data['level'],'hourly_rate'=>$data['hourly_rate'],'hours_per_day'=>$data['hours_per_day'],'valid_from'=>$data['valid_from'],'valid_to'=>$data['valid_to']??null,'created_at'=>now(),'updated_at'=>now()]);return $memberId;});
        return response()->json(['data'=>['member_id'=>$result]],201);
    }

    public function storeTerms(Request $request, int $member)
    {
        $data=$request->validate(['technology'=>'required|string|max:100','level'=>'required|string|max:100','hourly_rate'=>'required|numeric|min:0','hours_per_day'=>'required|numeric|min:0|max:24','valid_from'=>'required|date','valid_to'=>'nullable|date|after_or_equal:valid_from']);
        $this->assertTermsAvailable($member,$data['valid_from'],$data['valid_to']??null);
        $id=DB::table('member_terms')->insertGetId(['project_member_id'=>$member,...$data,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('member_terms')->find($id)],201);
    }

    public function moveMember(Request $request, int $member)
    {
        $data=$request->validate(['project_id'=>'required|integer|exists:projects,id']);
        DB::table('project_members')->where('id',$member)->update(['project_id'=>$data['project_id'],'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('project_members')->find($member)]);
    }

    private function assertTermsAvailable(int $memberId,string $from,?string $to): void
    {
        $end=$to??'9999-12-31';
        $overlap=DB::table('member_terms')->where('project_member_id',$memberId)->whereRaw("daterange(valid_from, COALESCE(valid_to, DATE '9999-12-31'), '[]') && daterange(?::date, ?::date, '[]')",[$from,$end])->exists();
        if($overlap) abort(422,'Периоды условий ProjectMember не могут пересекаться.');
    }
}
