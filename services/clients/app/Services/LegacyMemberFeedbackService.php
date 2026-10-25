<?php

namespace App\Services;

use App\Models\LegacyProjectMember;
use App\Models\ClientMemberFeedback;

final class LegacyMemberFeedbackService
{
    public function projectId(int $legacyProjectMemberId): ?int
    {
        $id = LegacyProjectMember::query()->whereKey($legacyProjectMemberId)->value('project_id');
        return $id === null ? null : (int) $id;
    }

    public function list(int $clientMemberId, ?int $projectId): array
    {
        return ClientMemberFeedback::query()->where('client_member_id', $clientMemberId)
            ->where('project_id', $projectId)
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(static fn ($row) => (object) $row->getRawOriginal())->all();
    }
}
