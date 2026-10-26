<?php
namespace App\Services;

use App\Models\TimesheetAudit;
use Illuminate\Http\Request;

final class AuditTrailService
{
    public function __construct(private readonly TimesheetsContext $context) {}

    public function history(Request $request, array $current)
    {
        [$employees, $departments] = $this->context->directory($request);
    $allAssignments = $this->context->assignments($request);
    $access = $this->context->accessInfo($request, $current, $allAssignments, $departments);
    $this->context->assertContourPermission($access, 'timesheets.audit.view');

    $query = TimesheetAudit::query()->orderByDesc('created_at')->limit(500);

    if (!$access['platformAdmin']) {
        $memberIds = collect($request->attributes->get('all_client_members', []))->filter(function ($member) use ($access, $current, $employees) {
            if ($access['scope'] === 'all') return true;
            if ($access['scope'] === 'none') return false;
            if ((int) ($member['account_employee_id'] ?? 0) === (int) $current['id']
                || in_array((int) $current['id'], $member['delegated_account_employee_ids'], true)) return true;
            if ($member['employee_id'] === null || $access['scope'] !== 'team') return false;
            $employee = $employees->first(fn ($e) => (int) $e['id'] === $member['employee_id']);
            return $employee && in_array((int) ($employee['department_id'] ?? 0), $access['managedDepartmentIds'], true);
        })->pluck('client_member_id')->unique()->all();
        $query->where(fn ($q) => $q->whereIn('client_member_id', $memberIds ?: [0])
            ->orWhere(fn ($own) => $own->whereNull('client_member_id')->where('employee_id', $access['scope'] === 'none' ? 0 : (int) $current['id'])));
    }

    return $query->get()->map(static fn ($row) => (object) $row->getRawOriginal());
    }
}
