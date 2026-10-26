<?php

namespace App\Application;

use App\Models\Absence;
use App\Models\AbsenceApproval;
use App\Models\AbsenceAttachment;
use App\Models\AbsenceAuditLog;
use App\Models\AbsenceStatusHistory;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AbsenceDeletionService
{
    /**
     * Permanently remove an absence and every record owned by it.
     * The caller must have authenticated and authorized platform-admin.
     *
     * Files are removed before database deletion. Missing files are tolerated,
     * making the operation retryable if a previous attempt was interrupted.
     */
    public function delete(int $absenceId): void
    {
        DB::transaction(static function () use ($absenceId): void {
            $absence = Absence::query()->whereKey($absenceId)->lockForUpdate()->first();
            if (!$absence) throw new DomainException('Отсутствие не найдено');

            $attachments = AbsenceAttachment::query()->where('absence_id', $absenceId)
                ->lockForUpdate()->get(['storage_path']);

            foreach ($attachments as $attachment) {
                $relativePath = (string) ($attachment->storage_path ?? '');
                // External imported links are not files owned by this service.
                if (!str_starts_with($relativePath, 'attachments/')
                    || str_contains($relativePath, '..')
                    || str_contains($relativePath, '\\')) {
                    continue;
                }

                $path = storage_path('app/vacations/'.$relativePath);
                if (is_file($path) && !@unlink($path)) {
                    throw new RuntimeException('Не удалось удалить файл отсутствия');
                }
            }

            AbsenceApproval::query()->where('absence_id', $absenceId)->delete();
            AbsenceStatusHistory::query()->where('absence_id', $absenceId)->delete();
            AbsenceAuditLog::query()->where('absence_id', $absenceId)->delete();
            AbsenceAttachment::query()->where('absence_id', $absenceId)->delete();
            $absence->delete();
        });
    }
}
