<?php

namespace App\Migration\Services;

use App\Migration\Core\TimesheetsResolution;
use App\Migration\Core\SourceRowConflict;
use Illuminate\Support\Facades\DB;

/**
 * Import into ClientMember-granular Timesheets.
 * No assumptions that numeric legacy IDs of different services share a namespace.
 * Ambiguous partner identities require explicit timesheets.members operator overrides.
 */
trait TimesheetsClientMemberImport
{
    private function v3Cents(mixed $value): int
    {
        if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>24)
            throw new SourceRowConflict('INVALID_HOURS','Часы должны быть числом от 0 до 24.',[]);
        $v=(float)$value;
        $c=(int)round($v*100);
        if(abs($v*100-$c)>0.000001)
            throw new SourceRowConflict('INVALID_HOUR_PRECISION',
                'Историческая точность не помещается в DECIMAL(5,2); округление запрещено.',[]);
        return $c;
    }
    private function v3Member(int $employee, ?int $partner, ?int $knownClient, string $id): int
    {
        $q=DB::connection('target_clients')->table('client_members');
        if($employee>0)$q->where('employee_id',$employee);
        else $q->where('partner_specialist_id',$partner);
        if($knownClient!==null)$q->where('client_id',$knownClient);
        $rows=$q->get();
        $operator=$this->store->override('timesheets','members',$id);
        if($operator!==null) {
            $selected=$rows->firstWhere('id',(int)$operator);
            if(!$selected)throw new SourceRowConflict('MEMBER_OVERRIDE_INVALID',
                'Ручной target ClientMember не совпадает с доказанной личностью/клиентом.',
                ['legacy_member_id'=>$id,'target_id'=>$operator]);
            return (int)$selected->id;
        }
        if($rows->count()!==1)
            throw new SourceRowConflict('MEMBER_IDENTITY_UNRESOLVED',
                'Не определено единственное клиентское подключение. Нужен timesheets.members override.',
                ['legacy_member_id'=>$id,'candidate_client_members'=>$rows->pluck('id')->all()]);
        return (int)$rows->first()->id;
    }
    protected function importClientMemberFacts(): void
    {
        // A legacy v2 mapping cannot silently be reinterpreted as a v3 ID.
        // The operator must reset/reconcile its metadata after a verified target snapshot.
        $usersByUuid=[];
        $this->rows('users',function($r,$id)use(&$usersByUuid){
            $employee=TimesheetsResolution::employee($r,$this->store);
            $previous=$this->store->mapping('timesheets','users',$id);
            if($previous!==null&&(int)$previous!==$employee)
                throw new SourceRowConflict('IDENTITY_MAPPING_COLLISION',
                    'Сопоставление Timesheets user противоречит Employees.',[]);
            $this->map('users',$id,$employee,$r,['resolution'=>['version'=>3]]);
            $uuid=trim((string)($r['employee_id']??''));
            if($uuid!=='')$usersByUuid[$uuid][$employee]=true;
        });
        $catalog=DB::connection('target_clients')->table('client_members')
            ->get(['id','client_id','employee_id','partner_specialist_id'])->keyBy('id');
        $resolved=[];
        $this->rows('members',function($r,$id)use(&$resolved,$usersByUuid,$catalog){
            $type=mb_strtolower(trim((string)($r['memberable_type']??'')));
            $employee=0;$partner=null;$knownClient=null;
            if($type==='employee'){
                $uuid=trim((string)($r['employee_id']??''));
                $ids=array_keys($usersByUuid[$uuid]??[]);
                if(count($ids)!==1)throw new SourceRowConflict('EMPLOYEE_IDENTITY_UNRESOLVED',
                    'Корпоративный UUID участника не сопоставлен однозначно с Employees.',
                    ['legacy_member_id'=>$id,'uuid'=>$uuid]);
                $employee=(int)$ids[0];
            }elseif(in_array($type,['subcontract','contractor','external','partner'],true)){
                // Legacy partner numeric references are never compared directly
                // with Clients partner IDs: source namespaces are independent.
                $operator=$this->store->override('timesheets','members',$id);
                if($operator===null)throw new SourceRowConflict('PARTNER_IDENTITY_UNRESOLVED',
                    'Партнёру требуется подтверждённое ручное сопоставление с ClientMember.',
                    ['legacy_member_id'=>$id,'source_type'=>$type]);
                $selected=$catalog->get((int)$operator);
                if(!$selected||$selected->partner_specialist_id===null)
                    throw new SourceRowConflict('PARTNER_IDENTITY_UNRESOLVED',
                        'Override ссылается не на партнёрского ClientMember.',
                        ['legacy_member_id'=>$id,'target_id'=>$operator]);
                $partner=(int)$selected->partner_specialist_id;
            }else throw new SourceRowConflict('UNSUPPORTED_MEMBER_TYPE',
                'Неизвестный тип участника; автоматическое присвоение личности запрещено.',
                ['legacy_member_id'=>$id,'source_type'=>$type]);
            // Only a verified previous Clients source-ID mapping is meaningful.
            // Unmapped timesheets.members.client_id is a different namespace.
            if(!empty($r['client_id'])){
                $linked=$this->store->mapping('clients','clients',(string)$r['client_id']);
                if($linked!==null)$knownClient=(int)$linked;
            }
            $target=$this->v3Member($employee,$partner,$knownClient,$id);
            $prior=$this->store->mapping('timesheets','members',$id);
            if($prior!==null) {
                $meta=DB::table('migration_mappings')->where('service','timesheets')
                    ->where('entity_type','members')->where('legacy_id',$id)->value('metadata');
                $version=json_decode($meta??'{}',true)['resolution']['version']??null;
                if((int)$version!==3||(int)$prior!==$target)
                    throw new SourceRowConflict('MEMBER_MAPPING_SCHEMA_VERSION_MISMATCH',
                        'Старое сопоставление к ProjectMember нельзя переиспользовать как ClientMember без сверки.',
                        ['source_member_id'=>$id,'previous_target'=>$prior,'client_member_id'=>$target]);
            }
            $this->map('members',$id,$target,$r,['resolution'=>['version'=>3,'client_member_id'=>$target,
                'employee_id'=>$employee?:null,'partner_specialist_id'=>$partner]]);
            $resolved[$id]=['client_member_id'=>$target,'employee_id'=>$employee?:null,
                'partner_specialist_id'=>$partner,'client_id'=>(int)$catalog[$target]->client_id];
        });
        $rates=[];$rateSource=[];
        foreach($this->source['rates']??[] as $rate)$rateSource[(string)$rate['id']]=$rate;
        $this->rows('rates',function($rate,$id)use($resolved,&$rates){
            $member=$this->need($resolved[(string)($rate['member_id']??'')]??null,'Не определён участник ставки.');
            [$from,$to]=$this->dates($rate['from'],$rate['to']);
            $terms=DB::connection('target_clients')->table('member_terms')
                ->where('client_member_id',$member['client_member_id'])
                ->whereDate('valid_from','<=',$to??'9999-12-31')
                ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$from))
                ->orderBy('valid_from')->get(['id','valid_from','valid_to','hours_per_day']);
            if($terms->isEmpty())throw new SourceRowConflict('RATE_TERM_MISSING',
                'Не найдено ни одной коммерческой ставки Clients на интервал источника.',
                ['legacy_rate_id'=>$id,'client_member_id'=>$member['client_member_id']]);
            $anchor=(int)$terms->first()->id;
            $existing=$this->store->mapping('timesheets','rates',$id);
            if($existing!==null) {
                $meta=DB::table('migration_mappings')->where('service','timesheets')
                    ->where('entity_type','rates')->where('legacy_id',$id)->value('metadata');
                $version=json_decode($meta??'{}',true)['resolution']['version']??null;
                if((int)$version!==3||(int)$existing!==$anchor)
                    throw new SourceRowConflict('RATE_MAPPING_SCHEMA_VERSION_MISMATCH',
                        'Старая ставка не может быть переобозначена без сверки.',[
                            'legacy_rate_id'=>$id,'previous_target'=>$existing,'target_term_id'=>$anchor]);
            }
            $this->map('rates',$id,$anchor,$rate,['resolution'=>['version'=>3,
                'client_member_id'=>$member['client_member_id'],'term_id'=>$anchor]]);
            $rates[$id]=$member+['legacy_from'=>$from,'legacy_to'=>$to,
                'terms'=>$terms->map(fn($t)=>['id'=>(int)$t->id,
                    'valid_from'=>substr((string)$t->valid_from,0,10),
                    'valid_to'=>$t->valid_to===null?null:substr((string)$t->valid_to,0,10)])->all()];
        });
        $groups=[];$personDays=[];
        $this->rows('timesheets',function($fact,$id)use($rates,&$groups,&$personDays){
            $rate=$this->need($rates[(string)($fact['rate_id']??'')]??null,
                'Невозможно сопоставить ставку исторической записи.');
            [$date]=$this->dates($fact['date'],$fact['date']);
            $valid=array_values(array_filter($rate['terms'],static fn($t)=>
                $t['valid_from']<=$date && (empty($t['valid_to'])||$t['valid_to']>=$date)));
            if(count($valid)!==1)throw new SourceRowConflict('RATE_DATE_UNRESOLVED',
                'По дате часов не определяется единственная версия условий Clients.',
                ['legacy_entry_id'=>$id,'client_member_id'=>$rate['client_member_id'],'date'=>$date]);
            $cents=$this->v3Cents($fact['hours']??null);
            $memberId=(int)$rate['client_member_id'];
            $groupKey=$memberId.':'.$date;
            $person=$rate['employee_id']!==null?'employee:'.$rate['employee_id']:
                'partner:'.$rate['partner_specialist_id'];
            $dayKey=$person.':'.$date;
            $personDays[$dayKey]=($personDays[$dayKey]??0)+$cents;
            $groups[$groupKey]??=['id'=>$memberId,'date'=>$date,
                'employee_id'=>$rate['employee_id'],'partner_specialist_id'=>$rate['partner_specialist_id'],
                'cents'=>0,'source'=>[],'term_id'=>(int)$valid[0]['id'],'client_id'=>$rate['client_id']];
            $groups[$groupKey]['cents']+=$cents;
            $groups[$groupKey]['source'][]=['id'=>(string)$id,'row'=>$fact];
            if($groups[$groupKey]['cents']>2400)
                throw new SourceRowConflict('CLIENT_DAY_OVER_24',
                    'На одного специалиста и клиента за день более 24 часов.',
                    ['client_member_id'=>$memberId,'date'=>$date]);
        });
        // Refuse any write when preflight or dry-run discovered unresolved facts.
        if($this->summary['conflicts']>0)return;
        foreach($personDays as $key=>$sum){
            if($sum>2400)throw new SourceRowConflict('PERSON_DAY_OVER_24',
                'Более 24 часов за сутки у одного сотрудника/партнёра.',
                ['person_day'=>$key,'centihours'=>$sum]);
        }
        $clientMembers=DB::connection('target_clients')->table('client_members')
            ->get(['id','employee_id','partner_specialist_id'])->keyBy('id');
        $existing=DB::connection('target_timesheets')->table('timesheet_entries')
            ->get(['id','employee_id','client_member_id','project_id','work_date','hours']);
        $targetDays=[];
        $mappedIds=DB::table('migration_mappings')
            ->where('service','timesheets')->where('entity_type','timesheets')->pluck('target_id')->map('intval')->all();
        $mappedSet=array_fill_keys($mappedIds,true);
        foreach($existing as $entry){
            if(isset($mappedSet[(int)$entry->id]))continue;
            $m=$clientMembers->get((int)$entry->client_member_id);
            $person=$entry->employee_id!==null?'employee:'.$entry->employee_id:
                ($m&&$m->partner_specialist_id!==null?'partner:'.$m->partner_specialist_id:null);
            if($person===null)throw new SourceRowConflict('EXISTING_FACT_IDENTITY_UNRESOLVED',
                'Существующий факт без доказанной личности блокирует перенос.',
                ['target_entry_id'=>$entry->id]);
            $key=$person.':'.substr((string)$entry->work_date,0,10);
            $targetDays[$key]=($targetDays[$key]??0)+$this->v3Cents($entry->hours);
        }
        foreach($personDays as $key=>$sum)
            if(($targetDays[$key]??0)+$sum>2400)
                throw new SourceRowConflict('TARGET_DAY_OVER_24',
                    'Новые часы вместе с существующими превышают 24.',
                    ['person_day'=>$key]);
        foreach($groups as $group){
            $source=$group['source'];usort($source,fn($a,$b)=>strcmp($a['id'],$b['id']));
            $canonical=$source[0];$id=$canonical['id'];
            $notes=array_map(static fn($item)=>
                '[legacy timesheets #'.$item['id'].', rate #'.($item['row']['rate_id']??'').']'.
                ($item['row']['description']!==null?"\n".$item['row']['description']:''),$source);
            $payload=['client_member_id'=>$group['id'],
                'employee_id'=>$group['employee_id'],'project_id'=>null,
                'work_date'=>$group['date'],'hours'=>number_format($group['cents']/100,2,'.',''),
                'description'=>implode("\n\n",$notes),'term_conflict'=>false];
            $natural=['client_member_id'=>$group['id'],'work_date'=>$group['date']];
            $target=$this->write('timesheets',$id,'timesheet_entries',$payload,$canonical['row'],$natural,
                ['resolution'=>['version'=>3,'client_member_id'=>$group['id'],
                    'member_term_id'=>$group['term_id'],'source_group'=>array_column($source,'id')]]);
            foreach(array_slice($source,1) as $alias)
                $this->map('timesheets',$alias['id'],$target,$alias['row'],
                    ['alias_of'=>$id,'resolution'=>['version'=>3,'client_member_id'=>$group['id']]]);
        }
        foreach(['month_confirmations','time_records','vacations','business_dates','departments'] as $table)
            $this->rows($table,fn($r,$id)=>$this->preserve($table,$r,$id,
                'Legacy evidence retained; actor and approval timestamps are not fabricated.'));
    }
}
