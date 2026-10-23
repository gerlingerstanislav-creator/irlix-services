<?php

namespace App\Migration\Services;

use App\Migration\Core\TimesheetsResolution;
use App\Migration\Core\SourceRowConflict;
use App\Migration\Core\TimesheetsFactStage;
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
        if(!is_string($value)&&!is_int($value)&&!is_float($value))
            throw new SourceRowConflict('INVALID_HOURS','Часы должны быть числом от 0 до 24.',[]);
        $raw=is_float($value)?json_encode($value,JSON_PRESERVE_ZERO_FRACTION):trim((string)$value);
        if(!is_numeric($raw)||!preg_match('/^([+-]?)([0-9]*)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]+))?$/D',$raw,$parts))
            throw new SourceRowConflict('INVALID_HOURS','Часы должны быть числом от 0 до 24.',[]);
        $digits=ltrim(($parts[2]??'').($parts[3]??''),'0');
        if($digits==='')return 0;
        if(($parts[1]??'')==='-')throw new SourceRowConflict('INVALID_HOURS','Отрицательные часы недопустимы.',[]);
        $exponent=(int)($parts[4]??0);
        $scale=2-strlen($parts[3]??'')+$exponent;
        if($scale<0){
            $trailing=strlen($digits)-strlen(rtrim($digits,'0'));
            if(-$scale>$trailing)throw new SourceRowConflict('INVALID_HOUR_PRECISION',
                'Историческая точность не помещается в DECIMAL(5,2); округление запрещено.',[]);
            $digits=substr($digits,0,strlen($digits)+$scale);
        }else{
            if(strlen($digits)+$scale>4)throw new SourceRowConflict('INVALID_HOURS','Часы превышают 24.',[]);
            $digits.=str_repeat('0',$scale);
        }
        $c=(int)$digits;
        if($c>2400)throw new SourceRowConflict('INVALID_HOURS','Часы превышают 24.',[]);
        return $c;
    }
    private function v3Member(int $employee, ?int $partner, ?int $knownClient, string $id, string $sourceProject): int
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
        if($knownClient===null){
            $unfiltered=$rows;
            $clients=\App\Migration\Core\TimesheetsClientMemberMatching::sourceClientIds($sourceProject);
            $rows=$rows->filter(static fn($row)=>in_array((int)$row->client_id,$clients,true));
            if($employee>0 && $rows->count()===0 && $unfiltered->count()===1) {
                // When the old project was renamed, a corporate UUID plus
                // exactly one real Clients membership and complete date
                // coverage is stronger evidence than a free-text label.
                $inspection=\App\Migration\Core\TimesheetsClientMemberMatching::inspectSource($id);
                $hasFactDates=false;
                foreach($inspection['source_rates']??[] as $rate)
                    if(!empty($rate['fact_dates']))$hasFactDates=true;
                $compatible=array_merge($inspection['candidates']??[],
                    $inspection['possible_employee_candidates']??[]);
                $match=\App\Migration\Core\EmployeeAutoMatch::choose(
                    $unfiltered->map(static fn($row)=>[
                        'target_id'=>(int)$row->id,'employee_id'=>(int)$row->employee_id])->values()->all(),
                    $compatible,$hasFactDates);
                if($match!==null)$rows=$unfiltered->filter(
                    static fn($row)=>(int)$row->id===$match);
            }
        }
        if($rows->count()!==1)
            throw new SourceRowConflict('MEMBER_IDENTITY_UNRESOLVED',
                'Не определено единственное клиентское подключение. Нужен timesheets.members override.',
                ['legacy_member_id'=>$id,'candidate_client_members'=>$rows->pluck('id')->all()]);
        return (int)$rows->first()->id;
    }
    /** Metadata ownership must prove this exact aggregate, never an old project ID. */
    private array $v3MappedSources=[];

    private function v3GroupTarget(array $group): ?int
    {
        $ids=array_column($group['source'],'id');
        if(!array_intersect_key($this->v3MappedSources,array_fill_keys($ids,true)))return null;
        $mappings=DB::table('migration_mappings')->where('service','timesheets')
            ->where('entity_type','timesheets')->whereIn('legacy_id',$ids)->get();
        if($mappings->isEmpty())return null;
        $targetIds=$mappings->pluck('target_id')->map(static fn($id)=>(int)$id)->unique();
        if($targetIds->count()!==1)throw new SourceRowConflict('FACT_GROUP_MAPPING_COLLISION',
            'Исходные часы одной группы ранее перенесены в разные факты; требуется сверка.',[]);
        $targetId=(int)$targetIds->first();
        $target=DB::connection('target_timesheets')->table('timesheet_entries')->find($targetId);
        foreach($mappings as $mapping){
            $meta=json_decode($mapping->metadata??'{}',true);
            $resolution=$meta['resolution']??[];
            if((int)($resolution['version']??0)!==3 || !$target
                || (int)$target->client_member_id!==(int)$group['id'] || $target->project_id!==null
                || substr((string)$target->work_date,0,10)!==$group['date'])
                throw new SourceRowConflict('FACT_MAPPING_SCHEMA_VERSION_MISMATCH',
                    'Mapping факта не доказывает каноническую ClientMember-группу; требуется сверка.',
                    ['target_entry_id'=>$targetId]);
            if(!isset($meta['alias_of'])){
                $sourceHash=hash('sha256',json_encode($group['source'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                if(!isset($resolution['source_hash']) || !hash_equals($resolution['source_hash'],$sourceHash))
                    throw new SourceRowConflict('FACT_SOURCE_GROUP_CHANGED','Состав или значения ранее перенесённой группы изменились; требуется сверка.',[]);
                foreach($meta['target_payload']??[] as $field=>$expected){
                    $actual=$target->$field??null;
                    if($expected===null?$actual!==null:(string)$actual!==(string)$expected){
                        if($actual!==null&&is_numeric($actual)&&is_numeric($expected)&&(float)$actual===(float)$expected)continue;
                        if(is_bool($expected)&&filter_var($actual,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)===$expected)continue;
                        throw new SourceRowConflict('FACT_TARGET_CHANGED','Целевой факт изменён после переноса; повтор не перезаписывает часы или описание.', ['target_entry_id'=>$targetId]);
                    }
                }
            }
        }
        $canonical=(string)$ids[0];
        if(!$mappings->contains(fn($m)=>(string)$m->legacy_id===$canonical))
            throw new SourceRowConflict('FACT_CANONICAL_MAPPING_MISSING','Утрачено каноническое сопоставление группы; требуется восстановление metadata.',[]);
        return $targetId;
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
            $employee=0;$partner=null;$knownClient=null;$autoPartnerMatched=false;
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
                if($operator===null) {
                    $inspection=\App\Migration\Core\TimesheetsClientMemberMatching::inspectSource($id);
                    $hasFactDates=false;
                    foreach($inspection['source_rates']??[] as $sourceRate)
                        if(!empty($sourceRate['fact_dates'])) $hasFactDates=true;
                    $operator=\App\Migration\Core\PartnerAutoMatch::choose(
                        $inspection['candidates']??[], $hasFactDates);
                    $autoPartnerMatched=$operator!==null;
                }
                if($operator===null)throw new SourceRowConflict('PARTNER_IDENTITY_UNRESOLVED',
                    'Нельзя автоматически определить единственного партнёра с доказанной личностью, клиентом и датами.',
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
            // A Timesheets client reference has its own namespace. Only an explicit
            // override may link it to a target Clients client.
            if(!empty($r['client_id'])){
                $linked=$this->store->override('timesheets','clients',(string)$r['client_id']);
                if($linked!==null)$knownClient=(int)$linked;
            }
            // For a partner, only the inspected ClientMember selected by explicit
            // override or strict unique evidence may be used. Do not reinterpret
            // an unrelated identical legacy numeric ID as a client identifier.
            if($partner!==null && $operator!==null) {
                $confirmed=$catalog->get((int)$operator);
                if(!$confirmed || (int)$confirmed->partner_specialist_id!==$partner ||
                    ($knownClient!==null && (int)$confirmed->client_id!==$knownClient))
                    throw new SourceRowConflict('PARTNER_IDENTITY_UNRESOLVED',
                        'Сопоставленный партнёр или клиент противоречит исходному подтверждению.',
                        ['legacy_member_id'=>$id]);
                $target=(int)$operator;
            } else $target=$this->v3Member($employee,$partner,$knownClient,$id,(string)($r['project']??''));
            $prior=$this->store->mapping('timesheets','members',$id);$previousMapping=null;
            if($prior!==null) {
                $meta=DB::table('migration_mappings')->where('service','timesheets')
                    ->where('entity_type','members')->where('legacy_id',$id)->value('metadata');
                $decoded=json_decode($meta??'{}',true);$version=(int)($decoded['resolution']['version']??0);
                if($version>=3&&(int)$prior!==$target)
                    throw new SourceRowConflict('MEMBER_MAPPING_SCHEMA_VERSION_MISMATCH',
                        'Существующее ClientMember сопоставление противоречит текущему доказательству.',
                        ['source_member_id'=>$id,'previous_target'=>$prior,'client_member_id'=>$target]);
                if($version<3 && $employee===0 && $this->store->override('timesheets','members',$id)===null)
                    throw new SourceRowConflict('MEMBER_MAPPING_SCHEMA_VERSION_MISMATCH',
                        'Старое проектное сопоставление партнёра требует явного подтверждения личности.',
                        ['source_member_id'=>$id,'previous_target'=>$prior,'client_member_id'=>$target]);
                $previousMapping=$version<3 ? ['target_id'=>$prior,'version'=>$version,'metadata'=>$decoded] : ($decoded['previous_mapping']??null);
            }
            $this->map('members',$id,$target,$r,['resolution'=>['version'=>3,'client_member_id'=>$target,
                'employee_id'=>$employee?:null,'partner_specialist_id'=>$partner,
                'match_method'=>$autoPartnerMatched?'unique_exact_name_client_and_fact_dates':'confirmed_or_corporate_identity'],
                'previous_mapping'=>$previousMapping]);
            $resolved[$id]=['client_member_id'=>$target,'employee_id'=>$employee?:null,
                'partner_specialist_id'=>$partner,'client_id'=>(int)$catalog[$target]->client_id];
        });
        $rates=[];
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
            $existing=$this->store->mapping('timesheets','rates',$id);$previousMapping=null;
            if($existing!==null) {
                $meta=DB::table('migration_mappings')->where('service','timesheets')
                    ->where('entity_type','rates')->where('legacy_id',$id)->value('metadata');
                $decoded=json_decode($meta??'{}',true);$version=(int)($decoded['resolution']['version']??0);
                if($version>=3&&(int)$existing!==$anchor)
                    throw new SourceRowConflict('RATE_MAPPING_SCHEMA_VERSION_MISMATCH',
                        'Существующая каноническая ставка противоречит текущим условиям.',
                        ['legacy_rate_id'=>$id,'previous_target'=>$existing,'target_term_id'=>$anchor]);
                $previousMapping=$version<3 ? ['target_id'=>$existing,'version'=>$version,'metadata'=>$decoded] : ($decoded['previous_mapping']??null);
            }
            $this->map('rates',$id,$anchor,$rate,['resolution'=>['version'=>3,
                'client_member_id'=>$member['client_member_id'],'term_id'=>$anchor],
                'previous_mapping'=>$previousMapping]);
            $rates[$id]=$member+['legacy_from'=>$from,'legacy_to'=>$to,
                'terms'=>$terms->map(fn($t)=>['id'=>(int)$t->id,
                    'valid_from'=>substr((string)$t->valid_from,0,10),
                    'valid_to'=>$t->valid_to===null?null:substr((string)$t->valid_to,0,10)])->all()];
        });
        $stage=new TimesheetsFactStage();
        $this->auditPages=!$this->isPreflight();
        $submittedSourceRows=0;
        $excludedRateLess=0;
        $this->rows('timesheets',function($fact,$id)use($rates,$stage,&$submittedSourceRows,&$excludedRateLess){
            if (($fact['rate_id'] ?? null) === null || (string) $fact['rate_id'] === '') {
                // Owner-authorized scope exclusion, NOT proof that these are internal activities.
                // Preserve the full source row in private conflict evidence; never create fake hours.
                $excludedRateLess++;
                $this->warning('timesheets',$id,'RATELESS_FACT_EXCLUDED_BY_SCOPE',
                    'Часы без связи со ставкой исключены из текущего переноса по решению владельца; исходная запись сохранена для отдельной сверки.',
                    ['source'=>$fact,'exclusion_policy'=>'owner-approved-unclassified-rate-null']);
                return;
            }
            $rate=$this->need($rates[(string)($fact['rate_id']??'')]??null,
                'Невозможно сопоставить ставку исторической записи.');
            [$date]=$this->dates($fact['date'],$fact['date']);
            $valid=array_values(array_filter($rate['terms'],static fn($t)=>
                $t['valid_from']!==null && $t['valid_from']<=$date && (empty($t['valid_to'])||$t['valid_to']>=$date)));
            if(count($valid)!==1)throw new SourceRowConflict('RATE_DATE_UNRESOLVED',
                'По дате часов не определяется единственная версия условий Clients.',
                ['legacy_entry_id'=>$id,'client_member_id'=>$rate['client_member_id'],'date'=>$date]);
            // Preserve the fact and its original is_submitted flag in source lineage,
            // but do not synthesize a daily confirmation from a per-rate boolean.
            if($this->boolean($fact['is_submitted']??false))$submittedSourceRows++;
            $stage->add($id,$fact,$rate,$date,(int)$valid[0]['id'],$this->v3Cents($fact['hours']??null));
        });
        $this->auditPages=false;
        if (!$this->isPreflight()) $this->summary['excluded_unclassified_rate_null']=$excludedRateLess;
        if($submittedSourceRows>0) {
            $this->summary['warnings']++;
            $this->store->event($this->runId,'legacy-submission-state',
                'Перенесены часы с сохранением исходного is_submitted в lineage; дневные подтверждения не синтезированы.',
                'warning',['source_rows'=>$submittedSourceRows,'target_confirmation'=>'not_inferred']);
        }
        $this->rows('month_confirmations',function($r,$id){
            // The inspected legacy schema supplies flags, but no auditable action
            // timestamp/actor or proof that all project confirmations agree.
            throw new SourceRowConflict('LEGACY_FINAL_CONFIRMATION_UNRESOLVED',
                'Месячное подтверждение требует сверки полноты, автора и времени; исходная запись сохранена в диагностике.',
                ['legacy_confirmation_id'=>$id]);
        });
        if($this->summary['conflicts']>0)return;
        $personDays=iterator_to_array($stage->dailyTotals());
        foreach($personDays as $key=>$sum)
            if($sum>2400)throw new SourceRowConflict('PERSON_DAY_OVER_24',
                'Более 24 часов за сутки у одного сотрудника/партнёра.',
                ['person_day'=>$key,'centihours'=>$sum]);
        // Reconcile every complete group before any facts are written. The source
        // group spans pages; a previous partial alias save is safe to retry.
        $owned=[];
        $this->v3MappedSources=array_fill_keys(DB::table('migration_mappings')->where('service','timesheets')
            ->where('entity_type','timesheets')->pluck('legacy_id')->all(),true);
        foreach($stage->groups() as $group) {
            if($group['cents']>2400)throw new SourceRowConflict('CLIENT_DAY_OVER_24',
                'На одного специалиста и клиента за день более 24 часов.',
                ['client_member_id'=>$group['id'],'date'=>$group['date']]);
            $target=$this->v3GroupTarget($group);
            if($target!==null)$owned[$target]=true;
        }
        $clientMembers=DB::connection('target_clients')->table('client_members')
            ->get(['id','employee_id','partner_specialist_id'])->keyBy('id');
        $existing=DB::connection('target_timesheets')->table('timesheet_entries')
            ->get(['id','employee_id','client_member_id','project_id','work_date','hours']);
        $targetDays=[];
        foreach($existing as $entry){
            if(isset($owned[(int)$entry->id]))continue;
            $m=$clientMembers->get((int)$entry->client_member_id);
            $person=$entry->employee_id!==null?'employee:'.$entry->employee_id:
                ($m&&$m->partner_specialist_id!==null?'partner:'.$m->partner_specialist_id:null);
            if($person===null)throw new SourceRowConflict('EXISTING_FACT_IDENTITY_UNRESOLVED',
                'Существующий факт без доказанной личности блокирует перенос.',
                ['target_entry_id'=>$entry->id]);
            $key=$person.':'.substr((string)$entry->work_date,0,10);
            if(isset($personDays[$key]) && $entry->project_id!==null)
                throw new SourceRowConflict('LEGACY_TARGET_FACT_REQUIRES_RECONCILIATION',
                    'Существующие проектные часы могут дублировать источник; сначала требуется сверка старых и новых фактов.',
                    ['target_entry_id'=>$entry->id]);
            $targetDays[$key]=($targetDays[$key]??0)+$this->v3Cents($entry->hours);
        }
        foreach($personDays as $key=>$sum)
            if(($targetDays[$key]??0)+$sum>2400)
                throw new SourceRowConflict('TARGET_DAY_OVER_24',
                    'Новые часы вместе с существующими превышают 24.',
                    ['person_day'=>$key]);
        foreach($stage->groups() as $group){
            $source=$group['source'];usort($source,fn($a,$b)=>strcmp($a['id'],$b['id']));
            $canonical=$source[0];$id=$canonical['id'];
            $notes=array_map(static fn($item)=>
                '[legacy timesheets #'.$item['id'].', rate #'.($item['row']['rate_id']??'').']'.
                (($item['row']['description']??null)!==null?"\n".$item['row']['description']:''),$source);
            $payload=['client_member_id'=>$group['id'],
                'employee_id'=>$group['employee_id'],'project_id'=>null,
                'work_date'=>$group['date'],'hours'=>number_format($group['cents']/100,2,'.',''),
                'description'=>implode("\n\n",$notes),'term_conflict'=>false];
            $natural=['client_member_id'=>$group['id'],'work_date'=>$group['date']];
            $target=$this->write('timesheets',$id,'timesheet_entries',$payload,$canonical['row'],$natural,
                ['resolution'=>['version'=>3,'client_member_id'=>$group['id'],
                    'member_term_id'=>$group['term_id'],'source_group'=>array_column($source,'id'),'group_key'=>$group['id'].':'.$group['date'],'source_hash'=>hash('sha256',json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))]]);
            foreach(array_slice($source,1) as $alias)
                $this->map('timesheets',$alias['id'],$target,$alias['row'],
                    ['alias_of'=>$id,'target_table'=>'timesheet_entries','resolution'=>['version'=>3,'client_member_id'=>$group['id'],'group_key'=>$group['id'].':'.$group['date']]]);
        }
        foreach(['time_records','vacations','business_dates','departments'] as $table)
            $this->rows($table,fn($r,$id)=>$this->preserve($table,$r,$id,
                'Legacy evidence retained; actor and approval timestamps are not fabricated.'));
    }
}
