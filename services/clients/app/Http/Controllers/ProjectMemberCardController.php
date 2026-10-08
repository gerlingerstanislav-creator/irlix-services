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
        $memberRow = DB::table('project_members')->find($member);
        abort_unless($memberRow, 404, 'Project member not found');

        $sourceProject = DB::table('projects')->find($memberRow->project_id);
        abort_unless($sourceProject, 404, 'Project not found');

        $data = $request->validate([
            'project_id' => ['sometimes', 'required', 'integer', 'exists:projects,id'],
        ]);

        if (!$data) {
            return response()->json(['data' => $this->payload($member)]);
        }

        $targetProjectId = (int) ($data['project_id'] ?? $memberRow->project_id);
        $targetProject = DB::table('projects')->find($targetProjectId);
        abort_unless($targetProject, 404, 'Project not found');
        abort_if(
            (int) $targetProject->client_id !== (int) $sourceProject->client_id,
            422,
            'ProjectMember можно перепривязать только внутри одного клиента.'
        );

        abort_if(
            DB::table('project_members')
                ->where('project_id', $targetProjectId)
                ->where(\App\Support\MemberIdentity::field($memberRow), $memberRow->{\App\Support\MemberIdentity::field($memberRow)})
                ->where('id', '<>', $member)
                ->exists(),
            422,
            'На выбранном проекте уже есть этот специалист.'
        );

        DB::table('project_members')->where('id', $member)->update([
            'project_id' => $targetProjectId,
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->payload($member)]);
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
            ->where('project_member_id', $member)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        return [
            ...(array) $row,
            'project_display_name' => $row->project_is_default ? 'Основной проект' : ($row->project_name ?: 'Основной проект'),
            'terms' => $terms,
        ];
    }
}
