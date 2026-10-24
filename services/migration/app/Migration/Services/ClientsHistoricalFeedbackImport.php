<?php

namespace App\Migration\Services;

use App\Migration\Core\SourceRowConflict;
use Illuminate\Support\Facades\DB;

/** Proven legacy project links are history, never invented dated assignments. */
trait ClientsHistoricalFeedbackImport
{
    private function validateCanonicalHistory(): array
    {
        $original = config('migration.imported_tables.clients');
        try {
            config(['migration.imported_tables.clients' => $original + [
                'feedback' => 'client_member_feedbacks', 'member_project_history' => 'project_members',
            ]]);
            $rows = $this->source;
            $rows['member_project_history'] = array_values(array_filter($rows['members'], fn ($r) => !empty($r['project_id'])));
            return $this->validateRows($rows);
        } finally {
            config(['migration.imported_tables.clients' => $original]);
        }
    }

    /** Immutable historical rows must not silently overwrite changed source or target evidence on retry. */
    private function guardHistoricalRetry(string $entity, string $id, string $table, array $row, array $payload): void
    {
        $mapping = DB::table('migration_mappings')->where(['service'=>'clients','entity_type'=>$entity,'legacy_id'=>$id])->first();
        if (!$mapping) return;
        $metadata = json_decode($mapping->metadata ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        if (($metadata['source'] ?? null) != $row || ($metadata['target_table'] ?? null) !== $table
            || (!isset($metadata['alias_of']) && ($metadata['target_payload'] ?? null) != $payload)) {
            throw new SourceRowConflict('HISTORICAL_SOURCE_DRIFT', 'Историческая запись или её сопоставление изменились; требуется явная сверка.', ['table'=>$entity,'legacy_id'=>$id]);
        }
        if (isset($metadata['alias_of'])) {
            $canonical = DB::table('migration_mappings')->where(['service'=>'clients','entity_type'=>$entity,'legacy_id'=>(string)$metadata['alias_of']])->value('metadata');
            $canonicalPayload = json_decode($canonical ?? '{}', true)['target_payload'] ?? [];
            foreach ($payload as $field=>$value) if (!in_array($field,['created_at','updated_at'],true)
                && ($canonicalPayload[$field] ?? null) != $value) throw new SourceRowConflict('HISTORICAL_SOURCE_DRIFT','Изменилось доказательство исторической личности.',[]);
            $payload = $canonicalPayload;
        }
        $target = DB::connection('target_clients')->table($table)->find((int)$mapping->target_id);
        foreach ($payload as $field=>$expected) {
            $actual = $target?->$field;
            if (!$target || ($expected === null ? $actual !== null : ($actual === null || (string)$actual !== (string)$expected))) {
                throw new SourceRowConflict('HISTORICAL_TARGET_DRIFT', 'Целевая историческая запись изменена или отсутствует; повторный перенос её не перезаписывает.', ['table'=>$entity,'legacy_id'=>$id,'field'=>$field]);
            }
        }
    }

    private function importHistoricalFeedback(array $memberSource): void
    {
        $history = [];
        $this->rows('members', function ($r, $id) use ($memberSource, &$history) {
            if (empty($r['project_id'])) return;
            $member = $this->need($memberSource[$id] ?? null, 'Не определена личность исторического участника проекта.');
            $project = $this->ref('projects', $r['project_id']);
            $key = $member['client_member_id'].':'.$project;
            $payload = ['project_id'=>$project,'specialist_id'=>$member['employee_id'],
                'partner_specialist_id'=>$member['partner_specialist_id'],
                'specialist_name'=>$this->memberHistoryName($r),'source_attempt_id'=>null,
                'created_at'=>$r['created_at'] ?? null,'updated_at'=>$r['updated_at'] ?? null];
            $previous=$member['previous_mapping']??null;
            $retained=null;
            if (($previous['metadata']['target_table']??null)==='project_members') {
                $retained=DB::connection('target_clients')->table('project_members')->find((int)$previous['target_id']);
                $this->assertHistoricalPayload($previous['metadata']['target_payload']??[],$retained);
                // Preserve an existing target's timestamps and source attempt reference;
                // the original nullable source fields remain in source metadata.
                $payload['created_at']=$retained->created_at;
                $payload['updated_at']=$retained->updated_at;
                $payload['source_attempt_id']=$retained->source_attempt_id;
            }
            $this->guardHistoricalRetry('member_project_history',$id,'project_members',$r,$payload);
            if (isset($history[$key])) {
                $canonical = $history[$key];
                $previous = $this->store->mapping('clients','member_project_history',$id);
                if ($previous !== null && (int)$previous !== $canonical['id']) throw new SourceRowConflict(
                    'HISTORICAL_MAPPING_COLLISION','Историческая проектная связь уже сопоставлена с другой записью.',[]);
                $this->map('member_project_history',$id,$canonical['id'],$r,['target_table'=>'project_members',
                    'alias_of'=>$canonical['legacy_id'],'resolution'=>['version'=>3,'group_key'=>$key]]);
                return;
            }
            $where=['project_id'=>$project,$member['partner_specialist_id'] === null ? 'specialist_id' : 'partner_specialist_id'
                =>$member['partner_specialist_id'] ?? $member['employee_id']];
            $metadata=['resolution'=>['version'=>3,'group_key'=>$key],'history_kind'=>'undated_project_membership'];
            if($retained!==null){
                $this->assertHistoricalPayload($payload,$retained);
                $target=(int)$retained->id;
                $priorHistory=$this->store->mapping('clients','member_project_history',$id);
                if($priorHistory!==null&&(int)$priorHistory!==$target)throw new SourceRowConflict('HISTORICAL_MAPPING_COLLISION','Проектная история указывает на другую запись.',[]);
                $this->map('member_project_history',$id,$target,$r,$metadata+['target_table'=>'project_members','target_payload'=>$payload]);
                $this->summary['tables']['member_project_history']=($this->summary['tables']['member_project_history']??0)+1;
            }else $target=$this->write('member_project_history',$id,'project_members',$payload,$r,$where,$metadata);
            $history[$key]=['id'=>$target,'legacy_id'=>$id];
        });
        $this->rows('feedback', function ($r, $id) use ($memberSource) {
            $fields=['id','member_id','content','date','rating','interview_id','created_at','updated_at'];
            if (array_diff($fields,array_keys($r)) || array_diff(array_keys($r),$fields)) throw new SourceRowConflict(
                'LEGACY_FEEDBACK_SCHEMA_UNRESOLVED','Схема исходной обратной связи отличается от проверенной; требуется сверка.',
                ['legacy_feedback_id'=>$id,'source_fields'=>array_keys($r)]);
            if (!is_string($r['content']) || !is_string($r['rating'])) throw new SourceRowConflict(
                'LEGACY_FEEDBACK_SCHEMA_UNRESOLVED','Текст и рейтинг обратной связи должны сохраняться без преобразования.',[]);
            [$date] = $this->dates($r['date'],null);
            if ($r['interview_id'] !== null && (!ctype_digit((string)$r['interview_id']) || (int)$r['interview_id'] < 1))
                throw new SourceRowConflict('INVALID_FEEDBACK_INTERVIEW','Некорректный исходный идентификатор интервью.',[]);
            $memberId=$this->ref('members',$r['member_id']);
            $member=$this->need($memberSource[(string)$r['member_id']] ?? null,'Не определён исходный участник обратной связи.');
            $project=empty($member['project_id']) ? null : $this->ref('projects',$member['project_id']);
            if ($project !== null) $this->ref('member_project_history',$r['member_id']);
            $payload=['client_member_id'=>$memberId,'project_id'=>$project,'text'=>$r['content'],
                'created_by_username'=>null,'occurred_on'=>$date,'rating'=>$r['rating'],
                'legacy_interview_id'=>$r['interview_id'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']];
            $this->guardHistoricalRetry('feedback',$id,'client_member_feedbacks',$r,$payload);
            $this->write('feedback',$id,'client_member_feedbacks',$payload,$r,[],
                ['attribution'=>'source_author_absent','legacy_member_id'=>(string)$r['member_id']]);
        });
    }

    private function memberHistoryName(array $row): string
    {
        $employee=mb_strtolower((string)$row['memberable_type'])==='employee';
        $person=$this->lookup($employee ? 'users' : 'subcontracts',$row['memberable_id']);
        return $this->need($employee ? $this->fullName($person)
            : ($person['full_name'] ?? $this->fullName($person)), 'Не определено имя исторического участника.');
    }
}
