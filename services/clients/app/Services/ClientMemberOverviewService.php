<?php

namespace App\Services;

use App\Models\ClientMember;

final class ClientMemberOverviewService
{
    /** Canonical connections, including clients without projects; eager-loaded in batches. */
    public function groupedByClient(?array $clientIds = null): array
    {
        return ClientMember::query()
            ->when($clientIds !== null, fn ($query) => $query->whereIn('client_id', $clientIds))
            ->with([
                'terms' => fn ($query) => $query->orderBy('valid_from'),
                'assignments' => fn ($query) => $query->orderBy('valid_from'),
                'assignments.project',
            ])
            ->orderBy('specialist_name')
            ->get()
            ->groupBy('client_id')
            ->map(fn ($members) => $members->map(fn (ClientMember $member) => [
                ...$member->getAttributes(),
                'terms' => $member->terms->map(fn ($term) => $term->getAttributes())->all(),
                'projects' => $member->assignments->map(fn ($assignment) => [
                    ...$assignment->getAttributes(),
                    'project_name' => $assignment->project?->name,
                ])->all(),
            ])->all())
            ->all();
    }
}
