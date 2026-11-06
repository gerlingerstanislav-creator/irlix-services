<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\SalaryReview;
use App\Models\SalaryReviewApproval;
use App\Models\SalaryReviewVersion;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\KeycloakAuthSync;

/**
 * Local employee hard-delete infrastructure adapter.
 *
 * Staff payroll review tables, authentication synchronization, outbox and audit
 * are intentionally not all declared with employee foreign keys. Explicit
 * erasure is required in addition to Employees' FK cascades.
 */
final class EmployeePurgeRepository
{
    private const ERASED_ACTOR = 9223372036854775807;

    public function erase(Employee $employee): void
    {
        $id = (int) $employee->id;
        $reviews = SalaryReview::query()->where('employee_id', $id)->pluck('id')->all();

        // A review belongs to its employee, including all historical decisions.
        SalaryReviewApproval::query()->whereIn('review_id', $reviews)->delete();
        SalaryReviewVersion::query()->whereIn('review_id', $reviews)->delete();
        SalaryReview::query()->whereIn('id', $reviews)->delete();

        // Someone else's review, however, must not be removed just because the
        // deleted employee participated in creating or approving it.
        SalaryReview::query()->where('created_by', $id)
            ->update(['created_by' => self::ERASED_ACTOR]);
        SalaryReviewApproval::query()->where('approver_id', $id)
            ->update(['approver_id' => self::ERASED_ACTOR]);
        SalaryReviewVersion::query()->where('changed_by', $id)
            ->update(['changed_by' => self::ERASED_ACTOR]);

        AuditLog::query()->where('actor_employee_id', $id)
            ->orWhere(static function ($query) use ($id): void {
                $query->where('target_type', 'employee')->where('target_id', (string) $id);
            })->delete();
        if ($employee->keycloak_user_id) {
            AuditLog::query()->where('actor_sub', (string) $employee->keycloak_user_id)->delete();
        }
        // Externally shared identifiers in audit payloads are removed when
        // exactly attributed to the employee, not through broad text matching.
        foreach (['before', 'after', 'metadata'] as $column) {
            AuditLog::query()->where($column.'->employee_id', $id)->delete();
        }

        // Deleting Employees itself emits an employee.deleted outbox event,
        // so cleanup MUST also run again after the DELETE trigger.
        $this->eraseEventAndSyncRows($id);
        $employee->delete();
        $this->eraseEventAndSyncRows($id);
    }

    private function eraseEventAndSyncRows(int $id): void
    {
        OutboxEvent::query()->where(static function ($query) use ($id): void {
            $query->where('aggregate_type', 'employee')
                ->where('aggregate_id', (string) $id);
        })->orWhere('payload->employee_id', $id)->delete();
        KeycloakAuthSync::query()->where('employee_id', $id)->delete();
    }
}
