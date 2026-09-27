<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AbsenceApproversController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $rows = DB::table('project_members as member')
            ->join('member_terms as terms', 'terms.project_member_id', '=', 'member.id')
            ->join('projects as project', 'project.id', '=', 'member.project_id')
            ->join('clients as client', 'client.id', '=', 'project.client_id')
            ->where('member.specialist_id', (int) $data['employee_id'])
            ->whereDate('terms.valid_from', '<=', $data['to'])
            ->where(function ($query) use ($data) {
                $query->whereNull('terms.valid_to')->orWhereDate('terms.valid_to', '>=', $data['from']);
            })
            ->select([
                'member.id as project_member_id',
                'project.id as project_id',
                'client.id as client_id',
                'client.account_employee_id as account_employee_id',
            ])
            ->distinct()
            ->get();

        $accountManagerIds = $rows
            ->pluck('account_employee_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return response()->json(['data' => [
            'account_manager_ids' => $accountManagerIds,
            'connection_count' => $rows->pluck('project_member_id')->unique()->count(),
        ]]);
    }
}
