<?php

use App\Support\ClientMemberDirectory;
use App\Support\CurrentEmployee;
use App\Support\TimeHours;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

$partnerAccess = static function (Request $request, ClientMemberDirectory $directory, CurrentEmployee $actor): array {
    $me = $actor->resolve($request);
    $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
    $response=Http::withToken($token)->acceptJson()->timeout(5)
        ->get(rtrim((string)env('CLIENTS_URL','http://clients:8000/api'),'/').'/permissions/me');
    abort_unless($response->successful(),503,'Не удалось проверить доступ через Clients');
    $access=$response->json('data');
    abort_unless(is_array($access),503,'Некорректный ответ прав');
    $grant=$access['permissions']['timesheets.management.manage']??[];
    abort_unless(($access['platform_admin']??false)||($grant['allowed']??false),403,'Недостаточно прав на ТШ партнёров');
    $snapshot=$directory->snapshot();
    $id=(int)$me['id'];
    $scope=(string)($grant['scope']??'none');
    $rows=array_values(array_filter($snapshot['members'], static fn($m)=>
        $m['partner_specialist_id']!==null &&
        (($access['platform_admin']??false)||$scope==='all'||
        ((int)($m['account_employee_id']??0)===$id ||
            in_array($id,$m['delegated_account_employee_ids']??[],true))&&in_array($scope,['own','team'],true))));
    return [$me,$rows,$snapshot['locked_periods'],$snapshot['members']];
};
$findPartnerTerm = static function (array $rows,int $id,string $date): array {
    $all=array_values(array_filter($rows,static fn($m)=>
        (int)$m['client_member_id']===$id && $m['valid_from']!==null && $m['valid_from']<=$date &&
        (empty($m['valid_to'])||$m['valid_to']>=$date)));
    abort_if(count($all)!==1,422,'Нет однозначных коммерческих условий на выбранную дату.');
    return $all[0];
};
$checkPartnerLock = static function(array $locks,int $clientId,string $date): void {
    foreach($locks as $period)
        if((int)$period['client_id']===$clientId && $period['period_start']<=$date&&$period['period_end']>=$date)
            abort(423,'Отчётный период передан клиенту и заблокирован.');
};
$partnerDayLock = static function (int $partnerId,string $date): void {
    if(DB::getDriverName()==='pgsql') DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[
        'timesheets:partner-day:'.$partnerId.':'.$date
    ]);
};

Route::get('/partner-hours',function(Request $request,ClientMemberDirectory $directory,CurrentEmployee $actor)use($partnerAccess){
    $month=$request->validate(['month'=>['required','date_format:Y-m']])['month'];
    [$me,$rows,$locks]=$partnerAccess($request,$directory,$actor);
    $from=$month.'-01';$to=Carbon::parse($from)->endOfMonth()->toDateString();
    $active=array_values(array_filter($rows,static fn($m)=>$m['valid_from']!==null && $m['valid_from']<=$to &&
        (empty($m['valid_to'])||$m['valid_to']>=$from)));
    $ids=array_values(array_unique(array_column($active,'client_member_id')));
    return response()->json(['data'=>[
        'month'=>$month,'members'=>$active,'locked_reporting_periods'=>$locks,
        'entries'=>DB::table('timesheet_entries')->whereIn('client_member_id',$ids?:[0])->whereBetween('work_date',[$from,$to])->get(),
        'confirmations'=>DB::table('partner_daily_confirmations')->whereIn('client_member_id',$ids?:[0])->whereBetween('work_date',[$from,$to])->get(),
        'final_approvals'=>DB::table('final_approvals')->whereIn('client_member_id',$ids?:[0])->where('month',$from)->get(),
        'final_approval_exceptions'=>DB::table('final_approval_exceptions')->whereIn('client_member_id',$ids?:[0])->whereBetween('work_date',[$from,$to])->get()
    ]]);
});

Route::put('/partner-hours',function(Request $request,ClientMemberDirectory $directory,CurrentEmployee $actor)
use($partnerAccess,$findPartnerTerm,$checkPartnerLock,$partnerDayLock){
    $data=$request->validate(['client_member_id'=>['required','integer','min:1'],
        'work_date'=>['required','date_format:Y-m-d'],
        'hours'=>['required','numeric','min:0','max:24'],
        'description'=>['nullable','string','max:4000']]);
    [$me,$rows,$locks,$allMembers]=$partnerAccess($request,$directory,$actor);
    $id=(int)$data['client_member_id'];$date=$data['work_date'];
    $m=$findPartnerTerm($rows,$id,$date);
    $checkPartnerLock($locks,(int)$m['client_id'],$date);
    $hours=TimeHours::quarter($data['hours']);
    $month=Carbon::parse($date)->startOfMonth()->toDateString();
    DB::transaction(function()use($id,$date,$hours,$data,$me,$month,$allMembers,$m,$partnerDayLock){
        $partnerId=(int)$m['partner_specialist_id'];$partnerDayLock($partnerId,$date);
        $approved=DB::table('final_approvals')->where('client_member_id',$id)->where('month',$month)->exists();
        $exception=DB::table('final_approval_exceptions')->where('client_member_id',$id)->where('work_date',$date)->exists();
        abort_if($approved&&!$exception,423,'ТШ партнёра уже окончательно подтверждены.');
        $linkedIds=array_values(array_unique(array_map('intval',array_column(array_values(array_filter($allMembers,
            static fn($v)=>(int)($v['partner_specialist_id']??0)===$partnerId)), 'client_member_id'))));
        $others=DB::table('timesheet_entries')->whereIn('client_member_id',$linkedIds?:[0])
            ->where('work_date',$date)->where('client_member_id','<>',$id)->sum('hours');
        abort_if(TimeHours::cents($others)+TimeHours::cents($hours)>2400,422,'Более 24 часов в день у партнёра.');
        $existing=DB::table('timesheet_entries')->where('client_member_id',$id)->where('work_date',$date)->lockForUpdate()->first();
        $description=trim((string)($data['description']??''))?:null;
        if ($existing && TimeHours::cents($existing->hours)===TimeHours::cents($hours)&&$existing->description===$description) return;
        $payload=['employee_id'=>null,'project_id'=>null,'client_member_id'=>$id,'work_date'=>$date,
            'hours'=>$hours,'description'=>$description,'updated_at'=>now()];
        if ($existing) DB::table('timesheet_entries')->where('id',$existing->id)->update($payload);
        else DB::table('timesheet_entries')->insert([...$payload,'created_at'=>now()]);
        DB::table('partner_daily_confirmations')->where('client_member_id',$id)->where('work_date',$date)->delete();
        if($approved) DB::table('final_approval_exceptions')->updateOrInsert([
            'client_member_id'=>$id,'work_date'=>$date
        ],['employee_id'=>null,'project_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('timesheet_audit')->insert([
            'action'=>$existing?'partner_entry_updated':'partner_entry_created',
            'actor_employee_id'=>(int)$me['id'],'employee_id'=>null,'project_id'=>null,
            'client_member_id'=>$id,'work_date'=>$date,
            'before'=>$existing?json_encode((array)$existing,JSON_UNESCAPED_UNICODE):null,
            'after'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'created_at'=>now()
        ]);
    });
    return response()->json(['data'=>['ok'=>true]]);
});

Route::post('/partner-hours/confirm',function(Request $request,ClientMemberDirectory $directory,CurrentEmployee $actor)
use($partnerAccess,$findPartnerTerm,$checkPartnerLock,$partnerDayLock){
    $data=$request->validate(['client_member_id'=>['required','integer','min:1'],
        'work_date'=>['required','date_format:Y-m-d'],'confirmed'=>['required','boolean']]);
    [$me,$rows,$locks]=$partnerAccess($request,$directory,$actor);
    $id=(int)$data['client_member_id'];$date=$data['work_date'];
    $m=$findPartnerTerm($rows,$id,$date);$checkPartnerLock($locks,(int)$m['client_id'],$date);
    DB::transaction(function()use($id,$date,$data,$me,$m,$partnerDayLock){
        $partnerDayLock((int)$m['partner_specialist_id'],$date);
        $month=Carbon::parse($date)->startOfMonth()->toDateString();
        $final=DB::table('final_approvals')->where('client_member_id',$id)->where('month',$month)->exists();
        if(!$data['confirmed'] && $final) abort(423,'Сначала снимите финальное подтверждение.');
        if($data['confirmed']) DB::table('partner_daily_confirmations')->updateOrInsert(
            ['client_member_id'=>$id,'work_date'=>$date],
            ['confirmed_at'=>now(),'confirmed_by_employee_id'=>(int)$me['id'],'created_at'=>now(),'updated_at'=>now()]);
        else DB::table('partner_daily_confirmations')->where('client_member_id',$id)->where('work_date',$date)->delete();
    });
    return response()->json(['data'=>['ok'=>true]]);
});

Route::post('/partner-hours/final-approval',function(Request $request,ClientMemberDirectory $directory,CurrentEmployee $actor)
use($partnerAccess,$checkPartnerLock,$partnerDayLock,$findPartnerTerm){
    $data=$request->validate(['client_member_id'=>['required','integer','min:1'],
        'month'=>['required','date_format:Y-m'],'approved'=>['required','boolean']]);
    [$me,$rows,$locks]=$partnerAccess($request,$directory,$actor);
    $id=(int)$data['client_member_id'];$from=$data['month'].'-01';
    $to=Carbon::parse($from)->endOfMonth()->toDateString();
    $terms=array_values(array_filter($rows,static fn($m)=>(int)$m['client_member_id']===$id &&
        $m['valid_from']!==null && $m['valid_from']<=$to && (empty($m['valid_to'])||$m['valid_to']>=$from)));
    abort_if(!$terms,422,'Нет подключения партнёра в указанном месяце.');
    $m=$terms[0];
    for($d=Carbon::parse($from);$d->lte(Carbon::parse($to));$d->addDay())
        $checkPartnerLock($locks,(int)$m['client_id'],$d->toDateString());
    if($data['approved']) {
        // Final approval cannot hide uncovered or unconfirmed days that have hours.
        $actual=DB::table('timesheet_entries')->where('client_member_id',$id)
            ->whereBetween('work_date',[$from,$to])->get();
        foreach($actual as $entry) {
            $findPartnerTerm($rows,$id,substr((string)$entry->work_date,0,10));
            abort_if((bool)($entry->term_conflict??false),422,'Не разрешён конфликт периода.');
            $prelim=DB::table('partner_daily_confirmations')
                ->where('client_member_id',$id)->where('work_date',$entry->work_date)->exists();
            abort_unless($prelim,422,'Не подтверждены все заполненные дни партнёра.');
        }
    }
    DB::transaction(function()use($id,$from,$to,$data,$me,$m,$partnerDayLock){
        for($d=Carbon::parse($from);$d->lte(Carbon::parse($to));$d->addDay())
            $partnerDayLock((int)$m['partner_specialist_id'],$d->toDateString());
        if($data['approved']){
            DB::table('final_approvals')->updateOrInsert(
                ['client_member_id'=>$id,'month'=>$from],
                ['employee_id'=>null,'project_id'=>null,'approved_by'=>(int)$me['id'],
                    'approved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        }else DB::table('final_approvals')->where('client_member_id',$id)->where('month',$from)->delete();
        DB::table('final_approval_exceptions')->where('client_member_id',$id)->whereBetween('work_date',[$from,$to])->delete();
    });
    return response()->json(['data'=>['ok'=>true]]);
});
