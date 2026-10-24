<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectMemberCardController extends Controller
{
    public function show(int $member)
    {
        return response()->json(['data' => $this->payload($member)]);
    }

    public function update(Request $request, int $member)
    {
        // Mutating a legacy project pointer would rewrite feedback provenance.
        // The new dated M:N route makes each project participation explicit.
        return app(ClientsController::class)->moveMember($request,$member);
    }

    private function payload(int $member): array
    {
        $row = DB::table('project_members as pm')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->join('clients as c', 'c.id', '=', 'p.client_id')
            ->where('pm.id', $member)
            ->select([
                'pm.id',
                'pm.project_id',
                'pm.specialist_id',
                'pm.specialist_name',
                'pm.partner_specialist_id',
                'pm.source_attempt_id',
                'pm.created_at',
                'pm.updated_at',
                'p.client_id',
                'p.name as project_name',
                'p.is_default as project_is_default',
                'c.name as client_name',
            ])
            ->first();

        abort_unless($row, 404, 'Project member not found');

        $terms = DB::table('member_terms')
            ->where('client_member_id', \App\Support\MemberIdentity::legacyMember($member)->id)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        return [
            ...(array) $row,
            'client_member_id'=>(int)\App\Support\MemberIdentity::legacyMember($member)->id,
            'project_display_name' => $row->project_is_default ? 'Основной проект' : ($row->project_name ?: 'Основной проект'),
            'terms' => $terms,
        ];
    }
}
