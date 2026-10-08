<?php

namespace App\Migration\Services;

use App\Migration\Core\SchemaMigration;
use App\Migration\Core\MigrationOperationsClient;
use Illuminate\Support\Facades\DB;

class VacationsV2Migration extends SchemaMigration
{
    private ?\App\Migration\Core\LegacyVacationDocuments $documents = null;

    protected function documents(): \App\Migration\Core\LegacyVacationDocuments { return $this->documents ??= app(\App\Migration\Core\LegacyVacationDocuments::class); }

    public function key(): string { return 'vacations'; }

    protected function assertCheckpoint(): void
    {
        try { parent::assertCheckpoint(); return; }
        catch (\RuntimeException $e) {
            if ($e->getCode() === 409) throw $e;
            [$code,$state] = app(MigrationOperationsClient::class)->request('GET','/vacations/state');
            if ($code === 200 && !in_array($state['operation']['state'] ?? '', ['queued','running'], true)
                && collect($state['snapshots'] ?? [])->contains(fn ($s) => ($s['compatible'] ?? true))) return;
            throw $e;
        }
    }

    protected function import(): void
    {
        $this->rows('users', function ($row, $id) { $this->map('users', $id, $this->employee($row), $row); });
        $this->rows('vacations', function ($row, $id) {
            $employee = $this->ref('users', $row['user_id']);
            [$from, $to] = $this->dates($row['from'], $row['to']);
            $this->need($to, 'Absence end is required');
            $type = $this->enum($row['type'], config('migration.vacation_types'), 'type');
            $status = $this->enum($row['status'], config('migration.vacation_statuses'), 'status');
            $this->number($row['working_hours'], 32767);
            $absence = $this->writeRelated('vacations', $id, 'absences', [
                'employee_id' => $employee, 'type' => $type, 'starts_on' => $from, 'ends_on' => $to,
                'calendar_days' => (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1,
                'status' => $status, 'created_by_subject' => 'legacy-migration',
            ], $row);
            if (!$this->dryRun && !DB::connection('target_vacations')->table('absence_audit_log')->where('absence_id', $absence)->where('event', 'legacy_migrated')->exists()) {
                DB::connection('target_vacations')->table('absence_audit_log')->insert([
                    'absence_id' => $absence, 'event' => 'legacy_migrated', 'actor_subject' => 'legacy-migration',
                    'after' => json_encode(['legacy_vacation_id' => $id, 'status' => $row['status'], 'working_hours' => $row['working_hours']]),
                    'created_at' => $row['created_at'] ?? now(),
                ]);
                DB::connection('target_vacations')->table('absence_status_history')->insert([
                    'absence_id' => $absence, 'to_status' => $status, 'actor_subject' => 'legacy-migration',
                    'reason' => 'Imported stored legacy state; transition timestamps are unavailable',
                    'context' => json_encode(['legacy_vacation_id' => $id, 'reconstructed' => false]), 'created_at' => now(),
                ]);
            }
        });
        $this->rows('approvers', function ($row, $id) {
            $absence = $this->ref('vacations', $row['vacation_id']);
            $users = array_values(array_filter($this->source['users'] ?? [], fn ($user) => (string) ($user['employee_id'] ?? '') === (string) $row['employee_id']));
            if (count($users) === 1) $approver = $this->ref('users', $users[0]['id']);
            else {
                $this->need(count($users) === 0, 'Неоднозначный UUID согласующего в старых users');
                $approver = $this->store->mapping('employees','employee',$row['employee_id']);
                $this->need($approver && DB::connection('target_employees')->table('employees')->where('id', $approver)->exists(), 'Не найден сотрудник-согласующий по UUID: '.$row['employee_id']);
            }
            $order = filter_var($row['order'], FILTER_VALIDATE_INT);
            $this->need($order !== false && $order > 0, 'Некорректный порядок согласующего');
            $this->writeRelated('approvers', $id, 'absence_approvals', [
                'absence_id'=>$absence, 'sequence'=>$order, 'stage'=>'employee_review',
                'status'=>$this->boolean($row['is_approved']) ? 'approved' : 'waiting',
                'required_role'=>null, 'approver_employee_id'=>(int) $approver,
                'approver_subject'=>null, 'acted_by_subject'=>null, 'acted_at'=>null,
                'comment'=>null,
            ], $row);
        });
        if (config('migration.vacation_documents_enabled', false)) $this->rows('attachments', function ($row, $id) {
            if (!self::vacationAttachment($row)) { $this->preserve('attachments',$row,$id,'Attachment belongs to an unsupported legacy object; not attached to a vacation.'); return; }
            $absence = $this->ref('vacations', $row['attachmentable_id']);
            $url = trim((string) $row['url']); $parts = parse_url($url);
            $this->need($parts && in_array($parts['scheme'] ?? '', ['http','https'],true) && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && !preg_match('/[\x00-\x20\x7f]/',$url), 'Для документа нужна полная корректная HTTP/HTTPS ссылка старого сервиса');
            $sourceVacation = collect($this->source['vacations'])->firstWhere('id',$row['attachmentable_id']);
            $type = $this->enum($sourceVacation['type'],config('migration.vacation_types'),'type');
            $sourceUser = collect($this->source['users'])->firstWhere('id',$sourceVacation['user_id']);
            $employeeId = $this->ref('users',$sourceVacation['user_id']);
            $employeeName = DB::connection('target_employees')->table('employees')->where('id',$employeeId)->value('full_name') ?: ($sourceUser['name'] ?? 'Сотрудник');
            $documentKey = \App\Migration\Core\EmployeeLoginRegistry::sourceKey().':'.$id.':'.$url;
            $file = $this->documents()->stage($documentKey,$url);
            $extension = strtolower(pathinfo($row['name'],PATHINFO_EXTENSION));
            $mimeExtensions = ['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg','application/msword'=>'doc','application/CDFV2'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx'];
            $extension = $mimeExtensions[$file['mime']] ?? $extension;
            if (!in_array($extension,['pdf','png','jpg','jpeg','doc','docx'],true)) $extension = '';
            $this->need($extension, 'Не удалось определить формат исходного документа');
            $name = \App\Migration\Core\LegacyVacationDocuments::filename($employeeName,$type,$sourceVacation['from'],$sourceVacation['to'],$documentKey,'document.'.$extension);
            $path = $this->dryRun ? 'attachments/'.$name : $this->documents()->install($file,$name);
            $this->writeRelated('attachments',$id,'absence_attachments',[
                'absence_id'=>$absence,'kind'=>'application','original_name'=>$name,'source_original_name'=>$row['name'],
                'mime_type'=>$file['mime'],'size_bytes'=>$file['size'],'storage_path'=>$path,
                'external_url'=>$url,'uploaded_by_subject'=>'legacy-migration','uploaded_by_employee_id'=>null,
            ],$row);
        });
        foreach (['changes', 'comments', 'business_dates', 'activity_log', 'departments'] as $table) {
            $this->rows($table, fn ($row, $id) => $this->preserve($table, $row, $id,
                'Legacy history/calendar preserved; exact actor actions and timestamps cannot be reconstructed from the schema alone.'));
        }
    }

    private static function vacationAttachment(array $row): bool
    {
        return in_array(mb_strtolower(basename(str_replace('\\','/',(string) ($row['attachmentable_type'] ?? '')))), ['vacation','vacations'], true);
    }

    private function writeRelated(string $entity, string $id, string $table, array $payload, array $row): int
    {
        $mapping = DB::table('migration_mappings')->where('service',$this->key())->where('entity_type',$entity)->where('legacy_id',$id)->first();
        if ($mapping) {
            $existing = DB::connection('target_vacations')->table($table)->find((int) $mapping->target_id);
            $prior = json_decode($mapping->metadata, true)['target_payload'] ?? [];
            foreach ($prior as $key=>$expected) {
                $actual = $existing?->$key;
                $this->need($expected === null ? $actual === null : ($actual !== null && (string) $actual === (string) $expected), 'Перенесённые данные изменены в новом сервисе; повторный импорт не перезаписывает пользовательские изменения');
            }
        }
        return $this->write($entity,$id,$table,$payload,$row);
    }

    private bool $validatingRelated = false;

    protected function extract(): array
    {
        $source = parent::extract();
        if ($this->validatingRelated && config('migration.vacation_documents_enabled', false)) $source['attachments'] = array_values(array_filter($source['attachments'], self::vacationAttachment(...)));
        return $source;
    }

    public function validate(int $runId): array
    {
        $previous = config('migration.imported_tables.vacations');
        config(['migration.imported_tables.vacations'=> $previous + ['approvers'=>'absence_approvals'] + (config('migration.vacation_documents_enabled', false) ? ['attachments'=>'absence_attachments'] : [])]);
        $this->validatingRelated = true;
        try { return parent::validate($runId); }
        finally { $this->validatingRelated = false; config(['migration.imported_tables.vacations'=>$previous]); }
    }
}
