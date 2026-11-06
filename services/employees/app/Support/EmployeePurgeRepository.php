<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

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
        $reviews = DB::table('salary_reviews')->where('employee_id', $id)->pluck('id')->all();

        // A review belongs to its employee, including all historical decisions.
        DB::table('salary_review_approvals')->whereIn('review_id', $reviews)->delete();
        DB::table('salary_review_versions')->whereIn('review_id', $reviews)->delete();
        DB::table('salary_reviews')->whereIn('id', $reviews)->delete();

        // Someone else's review, however, must not be removed just because the
        // deleted employee participated in creating or approving it.
        DB::table('salary_reviews')->where('created_by', $id)
            ->update(['created_by' => self::ERASED_ACTOR]);
        DB::table('salary_review_approvals')->where('approver_id', $id)
            ->update(['approver_id' => self::ERASED_ACTOR]);
        DB::table('salary_review_versions')->where('changed_by', $id)
            ->update(['changed_by' => self::ERASED_ACTOR]);

        DB::table('audit_log')->where('actor_employee_id', $id)
            ->orWhere(static function ($query) use ($id): void {
                $query->where('target_type', 'employee')->where('target_id', (string) $id);
            })->delete();
        if ($employee->keycloak_user_id) {
            DB::table('audit_log')->where('actor_sub', (string) $employee->keycloak_user_id)->delete();
        }
        // Externally shared identifiers in audit payloads are removed when
        // exactly attributed to the employee, not through broad text matching.
        foreach (['before', 'after', 'metadata'] as $column) {
            DB::table('audit_log')->where($column.'->employee_id', $id)->delete();
        }

        // Deleting Employees itself emits an employee.deleted outbox event,
        // so cleanup MUST also run again after the DELETE trigger.
        $this->eraseEventAndSyncRows($id);
        $employee->delete();
        $this->eraseEventAndSyncRows($id);
    }

    private function eraseEventAndSyncRows(int $id): void
    {
        DB::table('outbox_events')->where(static function ($query) use ($id): void {
            $query->where('aggregate_type', 'employee')
                ->where('aggregate_id', (string) $id);
        })->orWhere('payload->employee_id', $id)->delete();
        DB::table('keycloak_auth_sync')->where('employee_id', $id)->delete();
    }
}
