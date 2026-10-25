<?php

namespace App\Application;

use App\Models\AbsenceAttachment;
use App\Models\AbsenceAuditLog;
use App\Support\VacationDocumentNames;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AttachmentService
{
    public function list(int $absenceId): array
    {
        return AbsenceAttachment::query()->where('absence_id', $absenceId)
            ->select(['id', 'absence_id', 'kind', 'original_name', 'mime_type',
                'size_bytes', 'uploaded_by_employee_id', 'created_at'])
            ->orderBy('id')->get()
            ->map(static fn (AbsenceAttachment $row) => $row->getRawOriginal())->all();
    }

    public function exists(int $absenceId): bool
    {
        return AbsenceAttachment::query()->where('absence_id', $absenceId)->exists();
    }

    public function find(int $absenceId, int $attachmentId): ?AbsenceAttachment
    {
        return AbsenceAttachment::query()->whereKey($attachmentId)
            ->where('absence_id', $absenceId)->first();
    }

    public function store(
        UploadedFile $file, array $absence, int $actorId, string $subject,
        string $kind, string $personName
    ): array {
        $directory = storage_path('app/vacations/attachments');
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить хранилище документов');
        }
        $extension = strtolower($file->guessExtension() ?: '');
        if (!in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'], true)) {
            $extension = strtolower($file->getClientOriginalExtension());
        }
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $sourceName = $file->getClientOriginalName();
        $storedName = VacationDocumentNames::filename(
            $personName, $absence['type'], $absence['starts_on'],
            $absence['ends_on'] ?? 'открытый период', Str::uuid()->toString(), 'document.'.$extension
        );
        $file->move($directory, $storedName);
        $relativePath = 'attachments/'.$storedName;

        $id = AbsenceAttachment::query()->insertGetId([
            'absence_id' => (int) $absence['id'],
            'kind' => $kind ?: 'application',
            'original_name' => $storedName,
            'source_original_name' => $sourceName,
            'mime_type' => $mime,
            'size_bytes' => filesize($directory.'/'.$storedName) ?: 0,
            'storage_path' => $relativePath,
            'uploaded_by_subject' => $subject,
            'uploaded_by_employee_id' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = AbsenceAttachment::query()->whereKey($id)->first()?->getRawOriginal() ?? [];
        AbsenceAuditLog::query()->insert([
            'absence_id' => (int) $absence['id'],
            'event' => 'attachment_uploaded',
            'actor_subject' => $subject,
            'actor_employee_id' => $actorId,
            'before' => null,
            'after' => json_encode(['attachment_id' => $id, 'name' => $created['original_name']],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
        unset($created['storage_path'], $created['uploaded_by_subject']);
        return $created;
    }

    public function remove(AbsenceAttachment $attachment, int $actorId, string $actorSubject): void
    {
        DB::transaction(static function () use ($attachment, $actorId, $actorSubject): void {
            AbsenceAttachment::query()->whereKey($attachment->id)->delete();
            AbsenceAuditLog::query()->insert([
                'absence_id' => $attachment->absence_id,
                'event' => 'attachment_deleted',
                'actor_subject' => $actorSubject,
                'actor_employee_id' => $actorId,
                'before' => json_encode([
                    'attachment_id' => $attachment->id, 'name' => $attachment->original_name,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after' => null,
                'created_at' => now(),
            ]);
            $path = storage_path('app/vacations/'.$attachment->storage_path);
            if (is_file($path)) @unlink($path);
        });
    }
}
