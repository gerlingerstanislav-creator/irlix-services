<?php

namespace App\Migration\Services;

use App\Migration\Core\SourceRowConflict;

/** Compatible legacy project rates form one commercial timeline per ClientMember. */
trait ClientsCanonicalTermsImport
{
    private function importCanonicalTerms(array $memberSource): void
    {
        $plans=[];
        $this->rows('rates',function($r,$id)use(&$plans){
            [$from,$to]=$this->dates($r['start_date'],$r['end_date']);
            $member=$this->ref('members',$r['member_id']);
            $technology=empty($r['technology_id'])?null:$this->lookup('technologies',$r['technology_id'],'rates.technology_id',$id)['title'];
            $level=isset($r['grade'])&&trim((string)$r['grade'])!==''?$r['grade']:null;
            $rate=config('migration.clients_defer_rate_amounts')?null:$this->amount($r['rate'],'rates.rate',9999999999.99);
            $values=['technology'=>$technology,'level'=>$level,'hourly_rate'=>$rate,
                'hours_per_day'=>$this->amount($r['workload'],'rates.workload',24)];
            $plans[$member][]=['from'=>$from,'to'=>$to,'values'=>$values,'sources'=>[['id'=>$id,'row'=>$r]]];
        });
        $groups=[];
        foreach($plans as $member=>$terms){
            usort($terms,fn($a,$b)=>[$a['from'],$a['sources'][0]['id']]<=>[$b['from'],$b['sources'][0]['id']]);
            $merged=[];
            foreach($terms as $term){
                $i=count($merged)-1;
                $previous=$i>=0?$merged[$i]:null;
                $overlap=$previous!==null&&($previous['to']===null||$previous['to']>=$term['from']);
                $touches=$previous!==null&&($previous['to']===null||
                    (new \DateTimeImmutable($previous['to']))->modify('+1 day')->format('Y-m-d')>=$term['from']);
                // Unknown encrypted amounts cannot prove commercial equality.
                $compatible=$previous!==null&&$term['values']['hourly_rate']!==null&&$previous['values']===$term['values'];
                if($overlap&&!$compatible)throw new SourceRowConflict('SOURCE_PERIOD_OVERLAP',
                    'Пересекающиеся условия одного клиента различаются или сумма неизвестна; требуется операторская сверка.',
                    ['client_member_id'=>$member,'source_rate_ids'=>array_merge(array_column($previous['sources'],'id'),array_column($term['sources'],'id'))]);
                if($touches&&$compatible){
                    $merged[$i]['to']=$previous['to']===null||$term['to']===null?null:max($previous['to'],$term['to']);
                    $merged[$i]['sources']=array_merge($previous['sources'],$term['sources']);
                }else $merged[]=$term;
            }
            foreach($merged as $term)$groups[]=$term+['member'=>$member];
        }
        foreach($groups as $term){
            $sources=$term['sources'];usort($sources,fn($a,$b)=>strcmp($a['id'],$b['id']));
            $canonical=$sources[0];$id=$canonical['id'];$member=$term['member'];
            $key=$member.':'.$term['from'].':'.($term['to']??'open');
            $mapped=[];$evidence=[];$rebased=false;
            foreach($sources as $source){
                $proof=$this->canonicalRateEvidence($source['id'],$source['row'],$member);
                if($proof!==null){$mapped[(string)$proof['target_id']]=true;$evidence[$source['id']]=$proof;$rebased=$rebased||$proof['rebased'];}
            }
            if(count($mapped)>1)throw new SourceRowConflict('TERM_GROUP_MAPPING_COLLISION',
                'Совместимые исходные условия уже перенесены отдельными строками; требуется сверка ссылок.',[]);
            $canonicalTarget=$this->store->mapping('clients','rates',$id);
            if($mapped&&$canonicalTarget===null)throw new SourceRowConflict('TERM_CANONICAL_MAPPING_MISSING',
                'Каноническое сопоставление условий отсутствует; требуется восстановление metadata.',[]);
            $values=$term['values'];
            if($values['hourly_rate']===null)unset($values['hourly_rate']);
            $targetRow=$mapped ? \Illuminate\Support\Facades\DB::connection('target_clients')->table('member_terms')->find((int)array_key_first($mapped)) : null;
            $payload=$values+['client_member_id'=>$member,'project_member_id'=>$targetRow?->project_member_id,
                'valid_from'=>$term['from'],'valid_to'=>$term['to']];
            $metadata=['resolution'=>['version'=>3,'group_key'=>$key,'source_group'=>array_column($sources,'id')],
                'previous_mapping'=>$evidence[$id]['previous_mapping']??null];
            if($metadata['previous_mapping']!==null)$this->assertHistoricalPayload($payload,$targetRow);
            if($rebased){
                $this->assertHistoricalPayload($payload,$targetRow);
                $target=(int)$targetRow->id;
                $this->map('rates',$id,$target,$canonical['row'],$metadata+['target_table'=>'member_terms','target_payload'=>$payload]);
                $this->summary['tables']['rates']=($this->summary['tables']['rates']??0)+1;
            }else{
                $this->noOverlap('rates',$id,'member_terms',['client_member_id'=>$member],
                    'valid_from','valid_to',$term['from'],$term['to']);
                $target=$this->write('rates',$id,'member_terms',$payload,$canonical['row'],[],$metadata);
            }
            foreach(array_slice($sources,1) as $source)$this->map('rates',$source['id'],$target,$source['row'],[
                'alias_of'=>$id,'target_table'=>'member_terms','resolution'=>['version'=>3,'group_key'=>$key],
                'previous_mapping'=>$evidence[$source['id']]['previous_mapping']??null]);
            foreach($sources as $source){
                $r=$source['row'];$sourceId=$source['id'];
                if($term['values']['technology']===null)$this->warning('rates',$sourceId,'MISSING_TERM_TECHNOLOGY','Технология неизвестна; сохранён NULL.',[]);
                if($term['values']['level']===null)$this->warning('rates',$sourceId,'MISSING_TERM_GRADE','Грейд неизвестен; сохранён NULL.',[]);
                if($term['values']['hourly_rate']===null)$this->warning('rates',$sourceId,'RATE_AMOUNT_DEFERRED','Сумма ставки неизвестна; ноль не подставляется.',[]);
                if(!empty($r['is_revision'])||!empty($r['reason']))$this->preserve('rates',$r,$sourceId,
                    'Историческая причина пересмотра сохранена без синтетического действия.');
            }
        }
        // Commercial dates do not establish project participation dates.
        $this->rows('members',function($r,$id)use($memberSource){
            $member=$memberSource[$id]??null;
            if(!$member||empty($member['project_id']))return;
            if(empty($r['valid_from'])){
                $this->preserve('members',$r,$id,'Проектная принадлежность сохранена; даты назначения не доказаны и не выдумываются из коммерческих условий.');
                return;
            }
            [$from,$to]=$this->dates($r['valid_from'],$r['valid_to']??null);
            $project=$this->ref('projects',$member['project_id']);
            $this->noOverlap('member_project_assignments',$id,'project_assignments',
                ['client_member_id'=>$member['client_member_id'],'project_id'=>$project],'valid_from','valid_to',$from,$to);
            $this->write('member_project_assignments',$id,'project_assignments',[
                'client_member_id'=>$member['client_member_id'],'project_id'=>$project,'valid_from'=>$from,'valid_to'=>$to],$r);
        });
    }
}
