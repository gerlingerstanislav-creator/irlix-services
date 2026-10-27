<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMember;
use App\Models\ClientMemberFeedback;
use App\Models\MemberTerm;
use App\Models\MemberTermLineage;
use App\Models\ProjectAssignment;
use App\Models\ReportingPeriod;
use App\Models\ClientAccountDelegation;
use App\Models\ClientHistoryEvent;
use App\Models\LeadEvent;
use App\Models\ConnectionAttemptEvent;
use App\Models\LegacyProjectMember;
use App\Models\ConnectionAttempt;
use App\Models\Lead;
use App\Models\ClientRequest;
use App\Models\Position;
use App\Models\ClientContourPermissionAudit;
use App\Models\ProjectMemberFeedback;
use Illuminate\Support\Facades\DB;

final class EmployeePurgeService
{
    /**
     * Hard delete is an administrator-only emergency operation. Preserve other
     * clients and people, but remove the selected employee's complete memberships.
     * Never mutate commercial terms that overlap locked reporting periods.
     */
    public function purge(int $employeeId, string $username): void
    {
        DB::transaction(static function () use ($employeeId, $username): void {
            $members = ClientMember::query()->where('employee_id', $employeeId)->get();
            foreach ($members as $member) {
                $terms = MemberTerm::query()->where('client_member_id', $member->id)->get();
                foreach ($terms as $term) {
                    abort_if(ReportingPeriod::query()
                        ->where('client_id', $member->client_id)
                        ->where('status', '<>', 'Новый')
                        ->whereDate('period_start', '<=', $term->valid_to ?? '9999-12-31')
                        ->whereDate('period_end', '>=', $term->valid_from)
                        ->exists(), 423, 'Protected client reporting history requires a dedicated purge procedure');
                }
            }

            // Historical legacy memberships can reference archived MemberTerms;
            // delete dependent lineage before canonical terms and legacy members.
            ConnectionAttempt::query()->where('specialist_id', $employeeId)->delete();
            foreach ($members as $member) {
                $termIds = MemberTerm::query()->where('client_member_id', $member->id)->pluck('id')->all();
                ClientMemberFeedback::query()->where('client_member_id', $member->id)->delete();
                ProjectAssignment::query()->where('client_member_id', $member->id)->delete();
                if ($termIds) {
                    MemberTermLineage::query()->whereIn('canonical_term_id', $termIds)->delete();
                    MemberTerm::query()->whereIn('superseded_by_term_id', $termIds)->delete();
                    MemberTerm::query()->whereIn('id', $termIds)->delete();
                }
                ClientMember::query()->whereKey($member->id)->delete();
            }
            LegacyProjectMember::query()->where('specialist_id', $employeeId)->delete();

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
            }
        });
    }
}
