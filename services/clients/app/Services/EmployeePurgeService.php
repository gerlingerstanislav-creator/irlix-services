<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAccountDelegation;
use App\Models\ClientContourPermissionAudit;
use App\Models\ClientHistoryEvent;
use App\Models\ClientMember;
use App\Models\ClientMemberFeedback;
use App\Models\ClientNote;
use App\Models\ClientRequest;
use App\Models\ConnectionAttempt;
use App\Models\ConnectionAttemptEvent;
use App\Models\EmployeePurgeFile;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LegacyProjectMember;
use App\Models\MemberTerm;
use App\Models\MemberTermLineage;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectMemberFeedback;
use App\Models\ReportingPeriod;
use App\Models\ReportingPeriodApprovedHours;
use App\Models\ReportingPeriodHistory;
use Illuminate\Support\Facades\DB;

final class EmployeePurgeService
{
    /**
     * Delete employee-owned Clients data without erasing other people's business
     * records (including unrelated rows in paid client periods). Database cleanup is atomic locally;
     * pending CV-file removals survive a crash and are retried before success.
     */
    public function purge(int $employeeId, string $username, bool $preflight = false): void
    {
        DB::transaction(static function () use ($employeeId, $username, $preflight): void {
            $members = ClientMember::query()->where('employee_id', $employeeId)->get();
            $memberOwners = $members->pluck('client_id', 'id')->all();
            // Older project-member rows can still be referenced by terms even
            // after the canonical ClientMember migration.
            $legacy = LegacyProjectMember::query()
                ->where('specialist_id', $employeeId)
                ->whereNull('partner_specialist_id')
                ->get();
            $legacyIds = $legacy->pluck('id')->all();
            $projectOwners = Project::query()->whereIn('id', $legacy->pluck('project_id')->all())
                ->pluck('client_id', 'id')->all();
            $legacyOwners = [];
            foreach ($legacy as $row) {
                abort_unless(isset($projectOwners[$row->project_id]), 409,
                    'Legacy project membership has no resolvable client');
                $legacyOwners[$row->id] = (int) $projectOwners[$row->project_id];
            }

            $terms = MemberTerm::query()->whereIn('client_member_id', array_keys($memberOwners))->get()
                ->merge(MemberTerm::query()->whereIn('project_member_id', $legacyIds)->get())
                ->unique('id')->values();
            // An archived term may have no client_member_id but still reference
            // one of this employee's canonical terms.
            $archived = MemberTerm::query()->whereIn('superseded_by_term_id', $terms->pluck('id')->all())->get();
            $terms = $terms->merge($archived)->unique('id')->values();
            $termsById = $terms->keyBy('id');
            $termIds = $terms->pluck('id')->all();

            $ranges = [];
            foreach ($terms as $term) {
                $owners = [];
                if ($term->client_member_id !== null) {
                    abort_unless(isset($memberOwners[$term->client_member_id]), 409,
                        'Commercial term is attached to a different employee');
                    $owners[] = (int) $memberOwners[$term->client_member_id];
                }
                if ($term->project_member_id !== null) {
                    abort_unless(isset($legacyOwners[$term->project_member_id]), 409,
                        'Commercial term has an unrelated legacy project membership');
                    $owners[] = $legacyOwners[$term->project_member_id];
                }
                if ($term->superseded_by_term_id !== null) {
                    $canonical = $termsById->get($term->superseded_by_term_id);
                    abort_unless($canonical && isset($memberOwners[$canonical->client_member_id]), 409,
                        'Archived commercial term belongs to a different employee');
                    $owners[] = (int) $memberOwners[$canonical->client_member_id];
                }
                abort_if(!$owners || count(array_unique($owners)) !== 1, 409,
                    'Commercial term ownership requires reconciliation');

                $ranges[] = [
                    'client_id' => $owners[0],
                    'from' => substr((string) $term->valid_from, 0, 10),
                    'to' => substr((string) ($term->valid_to ?? '9999-12-31'), 0, 10),
                ];
            }

            // Record CV files *before* deleting attempt rows. A failed filesystem
            // removal can then be retried without reconstructing deleted attempts.
            $filenames = ConnectionAttempt::query()->where('specialist_id', $employeeId)
                ->whereNotNull('cv_storage_path')->pluck('cv_storage_path');
            foreach ($filenames as $filename) {
                $filename = (string) $filename;
                abort_unless($filename !== '' && $filename !== '.' && $filename !== '..'
                    && basename($filename) === $filename && !str_contains($filename, '\\'),
                    409, 'Unsafe stored CV filename requires reconciliation');
                if (!$preflight) {
                    EmployeePurgeFile::query()->firstOrCreate([
                        'employee_id' => $employeeId, 'filename' => $filename,
                    ]);
                }
            }

            // Paid and confirmed periods do not shield mistaken/test staff
            // from administrative erasure. Remove their stored personal detail
            // from snapshots without touching other employees' rows.
            EmployeeReportingSnapshotPurge::apply(
                $employeeId, array_keys($memberOwners), $termIds, $ranges, $preflight
            );
            if ($preflight) return;

            ConnectionAttempt::query()->where('specialist_id', $employeeId)->delete();
            foreach ($members as $member) {
                ClientMemberFeedback::query()->where('client_member_id', $member->id)->delete();
                ProjectAssignment::query()->where('client_member_id', $member->id)->delete();
            }
            if ($termIds) {
                // FK order: lineage -> archived terms -> canonical terms -> legacy members.
                MemberTermLineage::query()->whereIn('canonical_term_id', $termIds)->delete();
                MemberTerm::query()->whereIn('id', $termIds)->whereNotNull('superseded_by_term_id')->delete();
                MemberTerm::query()->whereIn('id', $termIds)->delete();
            }
            LegacyProjectMember::query()->whereIn('id', $legacyIds)->delete();
            ClientMember::query()->where('employee_id', $employeeId)->delete();

            Client::query()->where('sales_employee_id', $employeeId)->update(['sales_employee_id' => null]);
            Client::query()->where('account_employee_id', $employeeId)->update(['account_employee_id' => null]);
            Position::query()->where('responsible_rn_employee_id', $employeeId)->update(['responsible_rn_employee_id' => null]);
            Lead::query()->where('responsible_employee_id', $employeeId)->update(['responsible_employee_id' => null]);
            ClientRequest::query()->where('responsible_employee_id', $employeeId)->update(['responsible_employee_id' => null]);
            ConnectionAttempt::query()->where('responsible_employee_id', $employeeId)->update(['responsible_employee_id' => null]);
            ClientContourPermissionAudit::query()->where('actor_employee_id', $employeeId)->update(['actor_employee_id' => null]);
            LeadEvent::query()->where('created_by_employee_id', $employeeId)->update(['created_by_employee_id' => null]);
            ConnectionAttemptEvent::query()->where('actor_employee_id', $employeeId)->update(['actor_employee_id' => null]);
            ClientAccountDelegation::query()->where('from_employee_id', $employeeId)
                ->orWhere('to_employee_id', $employeeId)->delete();
            ClientAccountDelegation::query()->where('created_by_employee_id', $employeeId)
                ->update(['created_by_employee_id' => null]);

            if ($username !== '') {
                ProjectMemberFeedback::query()->where('created_by_username', $username)
                    ->update(['created_by_username' => null]);
                ClientMemberFeedback::query()->where('created_by_username', $username)
                    ->update(['created_by_username' => null]);
                ClientHistoryEvent::query()->where('created_by_username', $username)
                    ->update(['created_by_username' => null]);
                ClientNote::query()->where('created_by_username', $username)
                    ->update(['created_by_username' => null]);
                ReportingPeriodHistory::query()->where('author', $username)->update(['author' => null]);
                ReportingPeriodApprovedHours::query()->where('updated_by', $username)
                    ->update(['updated_by' => null]);
            }
        });

        if ($preflight) return;

        // A failure leaves the file ledger intact and prevents Employees from
        // deleting the identity. Retrying purge drains the ledger idempotently.
        foreach (EmployeePurgeFile::query()->where('employee_id', $employeeId)->get() as $pending) {
            $path = storage_path('app/clients/cv/'.$pending->filename);
            abort_if(is_link($path) || is_dir($path), 409, 'Unsafe CV path requires reconciliation');
            abort_if(is_file($path) && !@unlink($path), 503, 'CV file removal failed; retry purge');
            $pending->delete();
        }
    }
}
