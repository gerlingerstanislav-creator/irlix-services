<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientMemberAssignmentsController extends Controller
{
    /**
     * An authenticated service-to-service snapshot. No browser can obtain this
     * directory. Partner people have a separate namespace from Employees.
     */
    public function __invoke(Request $request)
    {
        $secret = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
        $actual = (string) $request->header('X-Irlix-Timesheets-Token', '');
        abort_unless($secret !== '' && $actual !== '' && hash_equals($secret, $actual), 403);

        $delegations = DB::table('client_account_delegations')
            ->whereNull('revoked_at')->whereDate('starts_at','<=',today())
            ->whereDate('ends_at','>=',today())->get()->groupBy('client_id');

        // Send every connection, including gaps and those without any term,
        // to make the catalogue complete for write authorization and history.
        $members = DB::table('client_members as cm')
            ->join('clients as c','c.id','=','cm.client_id')
            ->leftJoin('partner_specialists as p','p.id','=','cm.partner_specialist_id')
            ->select('cm.id as client_member_id','cm.client_id','cm.employee_id',
                'cm.partner_specialist_id','cm.specialist_name',
                'c.name as client_name','c.account_employee_id',
                'p.partner_id')
            ->orderBy('cm.id')->get();

        $terms = DB::table('member_terms')->whereNotNull('client_member_id')
            ->select('id','client_member_id','valid_from','valid_to','hourly_rate',
                'hours_per_day','technology','level')
            ->orderBy('valid_from')->orderBy('id')->get()->groupBy('client_member_id');

        $rows = $members->map(function($row) use ($terms,$delegations) {
            return [
                'client_member_id'=>(int)$row->client_member_id,
                'client_id'=>(int)$row->client_id,
                'client_name'=>(string)$row->client_name,
                'employee_id'=>$row->employee_id === null ? null : (int)$row->employee_id,
                'partner_specialist_id'=>$row->partner_specialist_id===null ? null : (int)$row->partner_specialist_id,
                'partner_id'=>$row->partner_id===null ? null : (int)$row->partner_id,
                'specialist_name'=>(string)$row->specialist_name,
                'account_employee_id'=>$row->account_employee_id===null ? null : (int)$row->account_employee_id,
                'delegated_account_employee_ids'=>($delegations->get($row->client_id) ?? collect())
                    ->pluck('to_employee_id')->map(fn($id)=>(int)$id)->values()->all(),
                'terms'=>($terms->get($row->client_member_id) ?? collect())->map(fn($term)=>[
                    'id'=>(int)$term->id,
                    'valid_from'=>substr((string)$term->valid_from,0,10),
                    'valid_to'=>$term->valid_to===null?null:substr((string)$term->valid_to,0,10),
                    'hourly_rate'=>$term->hourly_rate,
                    'hours_per_day'=>$term->hours_per_day,
                    'technology'=>$term->technology,
                    'level'=>$term->level,
                ])->values()->all(),
            ];
        })->values()->all();

        $locks = DB::table('reporting_periods')->where('status','<>','Новый')
            ->select('id','client_id','period_start','period_end','status')
            ->get()->map(fn($row)=>[
                'id'=>(int)$row->id, 'client_id'=>(int)$row->client_id,
                'period_start'=>substr((string)$row->period_start,0,10),
                'period_end'=>substr((string)$row->period_end,0,10),
                'status'=>(string)$row->status
            ])->values()->all();

        return response()->json([
            'complete'=>true,'count'=>count($rows),'data'=>$rows,
            'locked_reporting_periods_complete'=>true,
            'locked_reporting_periods_count'=>count($locks),
            'locked_reporting_periods'=>$locks,
        ]);
    }
}
