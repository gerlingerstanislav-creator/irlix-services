<?php

use App\Support\CurrentEmployee;
use App\Support\TimeHours;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// This workflow is operated by internal employees. Future partner self-service
// must use its own verified OIDC subject and a different permission/scope path.
$partnerTimeContext = static function (Request $request, bool $write) {
    $actor = app(CurrentEmployee::class)->resolve($request);
    $secret = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
    abort_unless($secret !== '', 503, 'Сервисный доступ к Clients не настроен.');
    $base = rtrim((string) env('CLIENTS_INTERNAL_URL', 'http://clients:8000/internal'), '/');
    try {
        $response = Http::withHeaders(['X-Irlix-Timesheets-Token'=>$secret])
            ->acceptJson()->timeout(8)->get($base.'/client-member-assignments');
    } catch (\Throwable) { abort(503, 'Каталог ClientMember недоступен.'); }
    $body = $response->json();
    abort_unless($response->successful() && is_array($body)
        && ($body['complete'] ?? false) === true
        && is_array($body['data'] ?? null) && array_is_list($body['data'])
        && ($body['count'] ?? null) === count($body['data'])
        && ($body['locked_reporting_periods_complete'] ?? false) === true
        && is_array($body['locked_reporting_periods'] ?? null)
        && ($body['locked_reporting_periods_count'] ?? null) === count($body['locked_reporting_periods']),
        503, 'Неполный каталог ClientMember: изменение часов заблокировано.');
    $token = (string) ($request->bearerToken() ?: $request->header('X-Irlix-Access-Token'));
    $url = rtrim((string) env('CLIENTS_URL','http://clients:8000/api'), '/').'/permissions/me';
    try {
        $response = Http::withToken($token)->acceptJson()->timeout(8)->get($url);
    } catch (\Throwable) { abort(503, 'Сервис разрешений недоступен.'); }
    abort_unless($response->successful() && is_array($response->json('data')), 503,
        'Невозможно проверить разрешения Timesheets.');
    $access = $response->json('data');
    $permission = 'timesheets.management.'.($write?'manage':'view');
    $scope = $access['permissions'][$permission]['scope'] ?? 'none';
    abort_unless(($access['platform_admin'] ?? false) || (
        ($access['permissions'][$permission]['allowed'] ?? false)
        && in_array($scope, ['own','team','all'],true)
    ), 403, 'Недостаточно прав для управления таймшитами партнёров.');
    return [
        'actor' => $actor,
        'scope' => ($access['platform_admin'] ?? false) ? 'all' : $scope,
        'members' => array_values(array_filter($body['data'], static fn($member) => is_array($member)
            && is_int($member['client_member_id'] ?? null)
            && is_int($member['client_id'] ?? null)
            && is_int($member['partner_specialist_id'] ?? null)
            && !isset($member['employee_id']) && is_array($member['terms'] ?? null))),
        'locked' => $body['locked_reporting_periods'],
    ];
};

$partnerAllowed = static function(array $context, array $member): bool {
    if ($context['scope'] === 'all') return true;
    $actor = (int) $context['actor']['id'];
    // Partners have no production-department relationship. Team production
    // grants don't automatically make every external contractor editable.
    return (int) ($member['account_employee_id'] ?? 0) === $actor
        || in_array($actor, $member['delegated_account_employee_ids'] ?? [], true);
};

$partnerMember = static function (array $context, int $memberId) use ($partnerAllowed): array {
    foreach ($context['members'] as $member) {
        if ((int)$member['client_member_id'] !== $memberId) continue;
        abort_unless($partnerAllowed($context, $member),403,'Этот клиент вне области доступа.');
        return $member;
    }
    abort(404,'Подключение партнёра не найдено.');
};

$partnerPeriodLock = static function(array $context, array $member, string $date, ?string $until=null): void {
    $end = $until ?: $date;
    foreach ($context['locked'] as $period) {
        if ((int) ($period['client_id'] ?? 0) !== (int)$member['client_id']) continue;
        if (($period['period_start'] ?? '') <= $end && ($period['period_end'] ?? '') >= $date) {
            abort(423,'ТШ партнёра заблокирован защищённым отчётным периодом.');
        }
    }
};

$partnerTerm = static function(array $member,string $date): array {
    $valid = array_values(array_filter($member['terms'], static fn($term)=>
        ($term['valid_from'] ?? '') <= $date
        && (empty($term['valid_to']) || $term['valid_to'] >= $date)));
    abort_unless(count($valid) === 1,422,'Для даты должен действовать ровно один MemberTerms.');
    return $valid[0];
};

$partnerDayLock = static function (int $partnerId, string $date): void {
    if (DB::getDriverName() === 'pgsql')
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['timesheets:partner-day:'.$partnerId.':'.$date]);
};

Route::get('/partners', function(Request $request) use ($partnerTimeContext, $partnerAllowed) {
    $input=$request->validate(['month'=>['required','date_format:Y-m']]);
    $first=$input['month'].'-01';
    $last=\Carbon\CarbonImmutable::parse($first)->endOfMonth()->toDateString();
    $context=$partnerTimeContext($request,false);
    $visible=array_values(array_filter($context['members'],fn($member)=>$partnerAllowed($context,$member)));
    $ids=array_column($visible,'client_member_id');
    return response()->json(['data'=>[
        'month'=>$input['month'],
        'members'=>$visible,
        'entries'=>$ids ? DB::table('timesheet_entries')->whereIn('client_member_id',$ids)
            ->whereBetween('work_date',[$first,$last])->orderBy('work_date')->get():[],
        'confirmations'=>$ids ? DB::table('partner_time_confirmations')->whereIn('client_member_id',$ids)
            ->whereBetween('work_date',[$first,$last])->get():[],
        'approvals'=>$ids ? DB::table('final_approvals')->whereIn('client_member_id',$ids)
            ->where('month',$first)->get():[],
        'exceptions'=>$ids ? DB::table('final_approval_exceptions')->whereIn('client_member_id',$ids)
            ->whereBetween('work_date',[$first,$last])->get():[],
        'locked_periods'=>array_values(array_filter($context['locked'],
            fn($period)=>in_array((int)($period['client_id']??0),array_column($visible,'client_id'),true)))
    ]]);
});

Route::put('/partners/entries', function(Request $request) use (
    $partnerTimeContext,$partnerMember,$partnerTerm,$partnerPeriodLock,$partnerDayLock
) {
    $data=$request->validate([
        'client_member_id'=>['required','integer','min:1'],
        'work_date'=>['required','date_format:Y-m-d'],
        'hours'=>['required','numeric','min:0','max:24'],
        'description'=>['nullable','string','max:4000'],
    ]);
    $context=$partnerTimeContext($request,true);
    $member=$partnerMember($context,(int)$data['client_member_id']);
    $partnerTerm($member,$data['work_date']);
    $partnerPeriodLock($context,$member,$data['work_date']);
    $hours=TimeHours::quarter($data['hours']);
    $date=$data['work_date'];
    $description=trim((string)($data['description'] ?? '')) ?: null;
    DB::transaction(function()use($context,$member,$date,$hours,$description,$partnerDayLock) {
        $partnerId=(int)$member['partner_specialist_id'];
        $memberId=(int)$member['client_member_id'];
        $partnerDayLock($partnerId,$date);
        $siblingIds=array_column(array_filter($context['members'],
            fn($row)=>(int)$row['partner_specialist_id']===$partnerId),'client_member_id');
        $existing=DB::table('timesheet_entries')->where('client_member_id',$memberId)
            ->where('work_date',$date)->lockForUpdate()->first();
        $month=substr($date,0,7).'-01';
        $approved=DB::table('final_approvals')->where('client_member_id',$memberId)
            ->where('month',$month)->exists();
        $exception=DB::table('final_approval_exceptions')->where('client_member_id',$memberId)
            ->where('work_date',$date)->exists();
        abort_if($approved && !$exception,423,'Финально подтверждённый ТШ нельзя изменить.');
        $other=DB::table('timesheet_entries')->whereIn('client_member_id',$siblingIds?:[0])
            ->where('work_date',$date)->where('client_member_id','!=',$memberId)->sum('hours');
        abort_if(TimeHours::cents($other)+TimeHours::cents($hours)>2400,422,
            'Суммарно партнёру нельзя указать более 24 часов в день.');
        $old=$existing ? (array)$existing : null;
        if($existing && TimeHours::cents($existing->hours)===TimeHours::cents($hours)
            && $existing->description===$description) return;
        if(!$existing && $hours==='0.00' && $description===null) return;
        $payload=['client_member_id'=>$memberId,'employee_id'=>null,'project_id'=>null,
            'work_date'=>$date,'hours'=>$hours,'description'=>$description,'updated_at'=>now()];
        if($existing) DB::table('timesheet_entries')->where('id',$existing->id)->update($payload);
        else DB::table('timesheet_entries')->insert([...$payload,'created_at'=>now()]);
        DB::table('partner_time_confirmations')->where('client_member_id',$memberId)->where('work_date',$date)->delete();
        if($approved) DB::table('final_approval_exceptions')->updateOrInsert([
            'client_member_id'=>$memberId,'work_date'=>$date],['created_at'=>now(),'updated_at'=>now()]);
        DB::table('timesheet_audit')->insert([
            'action'=>'partner_entry_changed',
            'actor_employee_id'=>(int)$context['actor']['id'],
            'employee_id'=>null,'project_id'=>null,'client_member_id'=>$memberId,'work_date'=>$date,
            'before'=>$old===null?null:json_encode($old,JSON_UNESCAPED_UNICODE),
            'after'=>json_encode(['hours'=>$hours,'description'=>$description],JSON_UNESCAPED_UNICODE),
            'created_at'=>now(),
        ]);
    });
    return response()->json(['data'=>['ok'=>true]]);
});

Route::post('/partners/confirm',function(Request $request)use(
    $partnerTimeContext,$partnerMember,$partnerTerm,$partnerPeriodLock,$partnerDayLock
){
    $data=$request->validate([
        'client_member_id'=>['required','integer','min:1'],
        'work_date'=>['required','date_format:Y-m-d'],
    ]);
    $context=$partnerTimeContext($request,true);
    $member=$partnerMember($context,(int)$data['client_member_id']);
    $partnerTerm($member,$data['work_date']);
    $partnerPeriodLock($context,$member,$data['work_date']);
    DB::transaction(function()use($context,$member,$data,$partnerDayLock){
        $date=$data['work_date'];$id=(int)$member['client_member_id'];
        $partnerDayLock((int)$member['partner_specialist_id'],$date);
        DB::table('partner_time_confirmations')->updateOrInsert(
            ['client_member_id'=>$id,'work_date'=>$date],
            ['confirmed_by_employee_id'=>(int)$context['actor']['id'],'confirmed_by_subject'=>null,
                'confirmed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    });
    return response()->json(['data'=>['ok'=>true]]);
});

Route::post('/partners/final-approval',function(Request $request)use(
    $partnerTimeContext,$partnerMember,$partnerPeriodLock,$partnerDayLock
){
    $data=$request->validate([
        'client_member_id'=>['required','integer','min:1'],
        'month'=>['required','date_format:Y-m'],
        'approved'=>['required','boolean'],
    ]);
    $context=$partnerTimeContext($request,true);
    $member=$partnerMember($context,(int)$data['client_member_id']);
    $first=$data['month'].'-01';
    $end=\Carbon\CarbonImmutable::parse($first)->endOfMonth()->toDateString();
    $partnerPeriodLock($context,$member,$first,$end);
    DB::transaction(function()use($context,$member,$data,$first,$end,$partnerDayLock){
        $id=(int)$member['client_member_id'];
        $allDays=[];
        $cursor=\Carbon\CarbonImmutable::parse($first);
        while($cursor->toDateString()<=$end) {
            $day=$cursor->toDateString();
            if(count(array_filter($member['terms'],static fn($term)=>$term['valid_from']<=$day
                && (empty($term['valid_to'])||$term['valid_to']>=$day)))===1)$allDays[]=$day;
            $cursor=$cursor->addDay();
        }
        foreach($allDays as $day)$partnerDayLock((int)$member['partner_specialist_id'],$day);
        DB::table('final_approval_exceptions')->where('client_member_id',$id)
            ->whereBetween('work_date',[$first,$end])->delete();
        if($data['approved']){
            abort_if(!$allDays,422,'В месяце нет действующих коммерческих условий.');
            foreach($allDays as $day){
                DB::table('partner_time_confirmations')->updateOrInsert(
                    ['client_member_id'=>$id,'work_date'=>$day],
                    ['confirmed_by_employee_id'=>(int)$context['actor']['id'],
                        'confirmed_by_subject'=>null,'confirmed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('final_approvals')->updateOrInsert(
                ['client_member_id'=>$id,'month'=>$first],
                ['employee_id'=>null,'project_id'=>null,'approved_by'=>(int)$context['actor']['id'],
                    'approved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        }else{
            DB::table('final_approvals')->where('client_member_id',$id)->where('month',$first)->delete();
        }
        DB::table('timesheet_audit')->insert([
            'action'=>$data['approved']?'partner_final_approved':'partner_final_unapproved',
            'actor_employee_id'=>(int)$context['actor']['id'],
            'employee_id'=>null,'project_id'=>null,'client_member_id'=>$id,'work_date'=>null,
            'before'=>null,'after'=>json_encode(['month'=>$first,'approved'=>$data['approved']],JSON_UNESCAPED_UNICODE),
            'created_at'=>now(),
        ]);
    });
    return response()->json(['data'=>['ok'=>true]]);
});
