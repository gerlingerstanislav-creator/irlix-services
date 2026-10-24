<?php

namespace App\Migration\Services;

use App\Migration\Core\SourceRowConflict;
use Illuminate\Support\Facades\DB;

/** Rebase only records whose retained source/target evidence proves the expand migration. */
trait ClientsLegacyMappingRebase
{
    private function mappingEvidence(string $entity, string $id): ?array
    {
        $mapping=DB::table('migration_mappings')->where(['service'=>'clients','entity_type'=>$entity,'legacy_id'=>$id])->first();
        return $mapping ? ['target_id'=>(int)$mapping->target_id,
            'metadata'=>json_decode($mapping->metadata??'{}',true,512,JSON_THROW_ON_ERROR)] : null;
    }

    private function assertHistoricalPayload(array $payload, ?object $target): void
    {
        if (!$target || !$payload) throw new SourceRowConflict('LEGACY_MAPPING_EVIDENCE_MISSING','Отсутствует доказательство прежней целевой записи.',[]);
        foreach ($payload as $field=>$expected) {
            $actual=$target->$field??null;
            if ($expected===null ? $actual===null : ($actual!==null&&(string)$expected===(string)$actual)) continue;
            if ($expected!==null&&$actual!==null&&is_numeric($expected)&&is_numeric($actual)
                &&abs((float)$expected-(float)$actual)<0.000001) continue;
            if (is_bool($expected)&&filter_var($actual,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)===$expected) continue;
            throw new SourceRowConflict('LEGACY_TARGET_DRIFT','Прежняя целевая запись изменена; автоматическая перепривязка запрещена.',['field'=>$field]);
        }
    }

    private function legacyMemberRebase(string $id, array $row, array $identity): ?array
    {
        $prior=$this->mappingEvidence('members',$id);
        if (!$prior || ($prior['metadata']['target_table']??null)==='client_members') return null;
        $metadata=$prior['metadata'];
        if (($metadata['target_table']??null)!=='project_members' || ($metadata['source']??null)!=$row)
            throw new SourceRowConflict('MAPPING_SCHEMA_VERSION_MISMATCH','Прежняя проектная связь не имеет проверяемого исходного доказательства.',[]);
        $old=DB::connection('target_clients')->table('project_members')->find($prior['target_id']);
        $this->assertHistoricalPayload($metadata['target_payload']??[],$old);
        if(!empty($row['project_id']))$project=$this->ref('projects',$row['project_id']);
        else{
            // The old adapter created a synthetic default for client-only rows.
            // It proves the former container's client, never source project history.
            $project=$this->store->mapping('clients','default_projects',$row['client_id']??'');
            $container=$project===null?null:DB::connection('target_clients')->table('projects')->find((int)$project);
            if(!$container||(int)$container->client_id!==(int)$identity['client_id']
                || !filter_var($container->is_default,FILTER_VALIDATE_BOOLEAN)||$container->name!==null)
                throw new SourceRowConflict('MAPPING_SCHEMA_VERSION_MISMATCH','Не доказан прежний default-контейнер исходного подключения без проекта.',[]);
            $project=(int)$project;
        }
        if ((int)$old->project_id!==$project || ($identity['employee_id']===null ? $old->specialist_id!==null : (int)$old->specialist_id!==(int)$identity['employee_id'])
            || ($identity['partner_specialist_id']===null ? $old->partner_specialist_id!==null : (int)$old->partner_specialist_id!==(int)$identity['partner_specialist_id']))
            throw new SourceRowConflict('MAPPING_SCHEMA_VERSION_MISMATCH','Прежний проектный участник не соответствует клиенту и личности.',[]);
        $matches=DB::connection('target_clients')->table('client_members')->where($identity)->get();
        if ($matches->count()!==1) throw new SourceRowConflict('MAPPING_SCHEMA_VERSION_MISMATCH','Expand migration не создала однозначного ClientMember для прежнего участника.',[]);
        return ['target_id'=>(int)$matches->first()->id,'previous_mapping'=>$prior];
    }

    private function canonicalRateEvidence(string $id, array $row, int $member): ?array
    {
        $prior=$this->mappingEvidence('rates',$id);
        if (!$prior) return null;
        $metadata=$prior['metadata'];
        if ((int)($metadata['resolution']['version']??0)>=3) {
            if(($metadata['previous_mapping']??null)!==null&&($metadata['source']??null)!=$row)
                throw new SourceRowConflict('LEGACY_SOURCE_DRIFT','Исходная версия условий после перепривязки изменена; требуется сверка.',[]);
            return ['target_id'=>$prior['target_id'],'previous_mapping'=>$metadata['previous_mapping']??null,'rebased'=>false];
        }
        if (($metadata['target_table']??null)!=='member_terms'||($metadata['source']??null)!=$row
            || !DB::connection('target_clients')->getSchemaBuilder()->hasTable('member_term_lineage'))
            throw new SourceRowConflict('TERM_MAPPING_EVIDENCE_MISSING','Прежние условия не имеют проверяемой истории expand migration.',[]);
        $lineage=DB::connection('target_clients')->table('member_term_lineage')->where('source_term_id',$prior['target_id'])->first();
        if (!$lineage||(int)$lineage->original_client_member_id!==$member)
            throw new SourceRowConflict('TERM_MAPPING_EVIDENCE_MISSING','История исходной версии условий не подтверждает ClientMember.',[]);
        $original=json_decode($lineage->original_payload,true,512,JSON_THROW_ON_ERROR);
        $this->assertHistoricalPayload($metadata['target_payload']??[],(object)$original);
        return ['target_id'=>(int)$lineage->canonical_term_id,'previous_mapping'=>$prior,'rebased'=>true];
    }
}
