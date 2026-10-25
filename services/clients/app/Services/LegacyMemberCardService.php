<?php

namespace App\Services;

use App\Models\LegacyProjectMember;
use App\Models\MemberTerm;
use App\Support\MemberIdentity;

final class LegacyMemberCardService
{
    public function payload(int $member): array
    {
        $row = LegacyProjectMember::query()->from('project_members as pm')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->join('clients as c', 'c.id', '=', 'p.client_id')
            ->where('pm.id', $member)
            ->select([
                'pm.id', 'pm.project_id', 'pm.specialist_id', 'pm.specialist_name',
                'pm.partner_specialist_id', 'pm.source_attempt_id',
                'pm.created_at', 'pm.updated_at', 'p.client_id',
                'p.name as project_name', 'p.is_default as project_is_default',
                'c.name as client_name',
            ])->first();
        abort_unless($row, 404, 'Project member not found');

        $raw = (object) $row->getRawOriginal();
        $canonicalId = (int) MemberIdentity::legacyMember($member)->id;
        $terms = MemberTerm::query()->where('client_member_id', $canonicalId)
            ->orderByDesc('valid_from')->orderByDesc('id')->get()
            ->map(static fn ($term) => (object) $term->getRawOriginal());

        return [
            ...(array) $raw,
            'client_member_id' => $canonicalId,
            'project_display_name' => $raw->project_is_default
                ? 'Основной проект' : ($raw->project_name ?: 'Основной проект'),
            'terms' => $terms,
        ];
    }
}
