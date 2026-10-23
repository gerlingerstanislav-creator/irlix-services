<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

/** Operator evidence for canonical client memberships, including partner identity. */
final class TimesheetsClientMemberMatching
{
    /** A source project label may prove its client, never a numeric cross-service ID. */
    public static function sourceClientIds(string $sourceProject): array
    {
        $name=mb_strtolower(trim($sourceProject));
        if($name==='')return [];
        $db=DB::connection('target_clients');
        $ids=$db->table('clients')->whereRaw('LOWER(TRIM(name)) = ?',[$name])->pluck('id')->all();
        if($db->getSchemaBuilder()->hasTable('projects'))$ids=array_merge($ids,
            $db->table('projects')->whereRaw('LOWER(TRIM(name)) = ?',[$name])->pluck('client_id')->all());
        return array_values(array_unique(array_map(static fn($id)=>(int)$id,$ids)));
    }

    public static function candidates(?int $employeeId, ?int $clientId = null): array
    {
        $q=DB::connection('target_clients')->table('client_members as cm')
            ->join('clients as c','c.id','=','cm.client_id');
        $employeeId===null?$q->whereNotNull('cm.partner_specialist_id'):$q->where('cm.employee_id',$employeeId);
        if($clientId!==null)$q->where('cm.client_id',$clientId);
        $members=$q->orderBy('cm.id')->get(['cm.*','c.name as client_name']);
        $terms=DB::connection('target_clients')->table('member_terms')
            ->whereIn('client_member_id',$members->pluck('id'))->orderBy('valid_from')->orderBy('id')->get();
        return $members->map(static function($member)use($terms){
            return ['target_id'=>(int)$member->id,'client_member_id'=>(int)$member->id,
                'client_id'=>(int)$member->client_id,'employee_id'=>$member->employee_id===null?null:(int)$member->employee_id,
                'partner_specialist_id'=>$member->partner_specialist_id===null?null:(int)$member->partner_specialist_id,
                'specialist_name'=>$member->specialist_name,'client_name'=>$member->client_name,
                'project_id'=>null,'project_name'=>'Клиентское подключение (без проекта)','is_default'=>false,
                'terms'=>$terms->where('client_member_id',$member->id)->map(static fn($term)=>[
                    'id'=>(int)$term->id,'valid_from'=>substr((string)$term->valid_from,0,10),
                    'valid_to'=>$term->valid_to===null?null:substr((string)$term->valid_to,0,10),
                    'hours_per_day'=>(string)$term->hours_per_day])->values()->all()];
        })->all();
    }

    public static function inspectSource(string $legacyId): array
    {
        if(!ctype_digit($legacyId)||(int)$legacyId<1)throw new \DomainException('Некорректный ID участника источника.');
        $reader=new LegacyReader('timesheets');$reader->assertSafe();
        $rows=$reader->select('SELECT * FROM public.members WHERE id = ?',[(int)$legacyId],false);
        if(count($rows)!==1)throw new \DomainException('Исходный участник не найден или неоднозначен.');
        return self::inspect($legacyId,(array)$rows[0],$reader);
    }

    public static function inspect(string $legacyId,array $source,LegacyReader $reader): array
    {
        $store=new MigrationStore();$employeeId=null;$employee=null;
        $type=mb_strtolower(trim((string)($source['memberable_type']??'')));
        if($type==='employee'){
            $uuid=trim((string)($source['employee_id']??''));
            if($uuid==='')throw new \DomainException('Корпоративный UUID отсутствует.');
            $ids=[];
            foreach($reader->select('SELECT * FROM public.users WHERE employee_id = ?',[$uuid],false) as $user)
                $ids[]=TimesheetsResolution::employee((array)$user,$store);
            $ids=array_values(array_unique($ids));
            if(count($ids)!==1)throw new \DomainException('UUID не определяет одного сотрудника Employees.');
            $employeeId=(int)$ids[0];
            $employee=DB::connection('target_employees')->table('employees')->find($employeeId);
        }elseif(!TimesheetsUnresolvedMembers::isExternal($type))throw new \DomainException('Неизвестный тип личности участника.');
        $knownClient=empty($source['client_id'])?null:$store->override('timesheets','clients',(string)$source['client_id']);
        $candidates=self::candidates($employeeId,$knownClient===null?null:(int)$knownClient);
        $rates=array_map(static fn($r)=>(array)$r,$reader->select('SELECT * FROM public.rates WHERE member_id = ? ORDER BY id',[(int)$legacyId],false));
        $dates=[];$examples=[];
        if($rates){
            $rateIds=array_column($rates,'id');$placeholders=implode(',',array_fill(0,count($rateIds),'?'));
            foreach($reader->select('SELECT DISTINCT rate_id,date FROM public.timesheets WHERE rate_id IN ('.$placeholders.') ORDER BY rate_id,date',$rateIds,false) as $fact)
                $dates[(string)$fact->rate_id][]=(string)$fact->date;
            foreach($reader->select('SELECT id,rate_id,date,hours FROM public.timesheets WHERE rate_id IN ('.$placeholders.') ORDER BY date,id LIMIT 12',$rateIds,false) as $fact)
                $examples[]=['legacy_entry_id'=>(string)$fact->id,'legacy_rate_id'=>(string)$fact->rate_id,'date'=>(string)$fact->date,'hours'=>(string)$fact->hours];
        }
        foreach($rates as &$rate)$rate['fact_dates']=$dates[(string)$rate['id']]??[];
        unset($rate);
        $candidates=TimesheetsMemberMatching::resolveByPeriods($rates,$candidates)['candidates'];
        $sourceName=TimesheetsUnresolvedMembers::fullName($source);
        $normalizedName=static fn(?string $name): string => mb_strtolower(
            preg_replace('/\\s+/u',' ',trim((string)$name)));
        $sourceIdentity=$normalizedName($sourceName);
        $sourceClientCandidates=self::sourceClientIds((string)($source['project']??''));
        // Candidate evidence is advisory only: similar names or project labels never
        // authorize a cross-system partner identity. The operator must confirm.
        foreach($candidates as &$candidate) {
            $candidate['name_exact']=$sourceIdentity!=='' &&
                $sourceIdentity===$normalizedName($candidate['specialist_name']??null);
            $candidate['client_label_exact']=in_array((int)$candidate['client_id'],
                $sourceClientCandidates,true);
            $candidate['match_evidence']=[
                'full_name_exact'=>$candidate['name_exact'],
                'client_label_exact'=>$candidate['client_label_exact'],
                'fact_dates_covered'=>$candidate['period_matches'],
                'identity_confirmed'=>$employeeId!==null,
            ];
        }
        unset($candidate);
        $override=$store->override('timesheets','members',$legacyId);
        $provenClients=$knownClient===null?$sourceClientCandidates:[(int)$knownClient];
        $proven=array_values(array_filter($candidates,static fn($c)=>in_array((int)$c['client_id'],$provenClients,true)));
        // When the client label is missing/ambiguous, show scoped identity clues
        // separately; unverified suggestions cannot be submitted as an override.
        $possible=$employeeId===null ? array_values(array_filter($candidates,
            static fn($c)=>$c['name_exact'] && $c['period_matches'])) : [];
        usort($possible,static fn($a,$b)=>((int)$b['client_label_exact']<=>(int)$a['client_label_exact'])
            ?: ((int)$a['target_id']<=>(int)$b['target_id']));
        $selected=$override===null?($employeeId!==null&&count($proven)===1?$proven[0]:null):
            collect($employeeId===null?$candidates:$proven)->firstWhere('target_id',(int)$override);
        if($employeeId===null && $selected!==null &&
            (!$selected['name_exact'] || !$selected['period_matches']))
            $selected=null;
        $verified=$selected!==null&&$selected['period_matches'];
        $diagnosis=['code'=>$verified?'unique_verified':($employeeId===null?'PARTNER_IDENTITY_UNRESOLVED':'MEMBER_IDENTITY_UNRESOLVED'),
            'message'=>$verified?'Клиентское подключение и фактические даты подтверждены.':'Нужно явное сопоставление с клиентским подключением; имя и числовой source ID не доказывают личность.'];
        $resolution=$verified?['version'=>3,'client_member_id'=>$selected['target_id'],'employee_id'=>$employeeId,'partner_specialist_id'=>$selected['partner_specialist_id']]:null;
        ksort($source);
        return ['source'=>['legacy_id'=>$legacyId,'project'=>$source['project']??'',
                'memberable_type'=>$type,'employee_id'=>$employeeId,
                'employee_name'=>$employee->full_name??TimesheetsUnresolvedMembers::fullName($source)??''],
            'source_fingerprint'=>hash('sha256',json_encode([$source,$rates,$proven,$override,$knownClient,$employee->login??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            'source_rates'=>$rates,'source_entry_examples'=>$examples,'candidates'=>$proven,
            'possible_partner_candidates'=>$possible,
            'partner_match_policy'=>'names_are_hints_only; explicit reviewed override required',
            'suggested_target_id'=>$verified?$selected['target_id']:null,'diagnosis'=>$diagnosis,
            'resolution'=>$resolution,'root_cause'=>$verified?null:['table'=>'members','legacy_id'=>$legacyId]+$diagnosis];
    }
}
