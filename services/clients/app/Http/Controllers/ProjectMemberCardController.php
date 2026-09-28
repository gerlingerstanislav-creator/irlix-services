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
            'specialist_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'specialist_name' => ['sometimes', 'required', 'string', 'max:255'],
            'source_attempt_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $targetProjectId = (int) ($data['project_id'] ?? $memberRow->project_id);
        $targetProject = DB::table('projects')->find($targetProjectId);
        abort_unless($targetProject, 404, 'Project not found');
        abort_if(
            (int) $targetProject->client_id !== (int) $sourceProject->client_id,
            422,
            'ProjectMember можно перепривязать только внутри одного клиента.'
        );

        $targetSpecialistId = (int) ($data['specialist_id'] ?? $memberRow->specialist_id);

        abort_if(
            DB::table('project_members')
                ->where('project_id', $targetProjectId)
                ->where('specialist_id', $targetSpecialistId)
                ->where('id', '<>', $member)
                ->exists(),
            422,
            'На выбранном проекте уже есть этот специалист.'
        );

        if ($targetSpecialistId !== (int) $memberRow->specialist_id) {
            $terms = DB::table('member_terms')->where('project_member_id', $member)->get();
            foreach ($terms as $term) {
                $this->assertSpecialistAvailable(
                    (int) $sourceProject->client_id,
                    $targetSpecialistId,
                    (string) $term->valid_from,
                    $term->valid_to ? (string) $term->valid_to : null,
                    $member,
                );
            }
        }

        DB::table('project_members')->where('id', $member)->update([
            ...$data,
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

    private function assertSpecialistAvailable(int $clientId, int $specialistId, string $from, ?string $to, int $exceptMember): void
    {
        $conflict = DB::table('member_terms as mt')
            ->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->where('p.client_id', $clientId)
            ->where('pm.specialist_id', $specialistId)
            ->where('pm.id', '<>', $exceptMember)
            ->where('mt.valid_from', '<=', $to ?? '9999-12-31')
            ->where(function ($query) use ($from) {
                $query->whereNull('mt.valid_to')->orWhere('mt.valid_to', '>=', $from);
            })
            ->exists();

        abort_if($conflict, 422, 'У специалиста уже есть пересекающееся подключение у этого клиента.');
    }
}
