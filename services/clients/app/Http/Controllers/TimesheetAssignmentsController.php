<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TimesheetAssignmentsController extends Controller
{
    public function __invoke(Request $request)
    {
        $expected = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Timesheets-Token', '');
        abort_unless($expected !== '' && $provided !== '' && hash_equals($expected, $provided), 403);

        // One query supplies the complete historical directory. No user scope or commercial rates.
        $rows = DB::table('member_terms as terms')
            ->join('project_members as members', 'members.id', '=', 'terms.project_member_id')
            ->join('projects', 'projects.id', '=', 'members.project_id')
            ->join('clients', 'clients.id', '=', 'projects.client_id')
            ->whereNotNull('terms.valid_from')
            ->select([
                'members.specialist_id as employee_id', 'members.specialist_name as employee_name',
                'clients.id as client_id', 'clients.name as client_name',
                'projects.id as project_id', 'projects.name as project_name',
                'clients.account_employee_id', 'terms.valid_from', 'terms.valid_to',
            ])->orderBy('terms.id')->get()->map(fn ($row) => [
                ...(array) $row,
                'employee_id' => (int) $row->employee_id,
                'client_id' => (int) $row->client_id,
                'project_id' => (int) $row->project_id,
                'project_name' => $row->project_name ?: $row->client_name,
                'account_employee_id' => $row->account_employee_id === null ? null : (int) $row->account_employee_id,
            ])->values()->all();

        $lockedPeriods = DB::table('reporting_periods')->where('status', '<>', 'Новый')
            ->select(['id', 'client_id', 'period_start', 'period_end', 'status', 'timesheets_sent_at'])
            ->orderBy('id')->get()->map(fn ($period) => [
                ...(array) $period, 'id' => (int) $period->id, 'client_id' => (int) $period->client_id,
            ])->values()->all();
        return response()->json(['data' => $rows, 'complete' => true, 'count' => count($rows),
            'locked_reporting_periods' => $lockedPeriods, 'locked_reporting_periods_complete' => true,
            'locked_reporting_periods_count' => count($lockedPeriods)]);
    }
}
