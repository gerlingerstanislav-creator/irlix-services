<?php

namespace App\Services;

use App\Models\LegacyProjectMember;
use App\Models\ProjectAssignment;

final class SpecialistsProjectDirectory
{
    /**
     * Read-only, minimal specialist project projection. Clients owns assignments
     * and legacy member records; no terms, rates, contacts or HR data leave this API.
     */
    public function forEmployee(int $employeeId): array
    {
        $current = ProjectAssignment::query()->from('project_assignments as a')
            ->join('client_members as cm', 'cm.id', '=', 'a.client_member_id')
            ->join('projects as p', 'p.id', '=', 'a.project_id')
            ->join('clients as c', 'c.id', '=', 'p.client_id')
            ->where('cm.employee_id', $employeeId)
            ->select(['p.id as project_id', 'p.name as project_name',
                'c.name as client_name', 'a.valid_from', 'a.valid_to'])
            ->orderByDesc('a.valid_from')->get()
            ->map(static fn ($item) => (array) $item->getRawOriginal());

        // Records predating the canonical client_members model remain discoverable.
        $legacy = LegacyProjectMember::query()->from('project_members as pm')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->join('clients as c', 'c.id', '=', 'p.client_id')
            ->where('pm.specialist_id', $employeeId)
            ->select(['p.id as project_id', 'p.name as project_name', 'c.name as client_name'])
            ->orderBy('p.name')->get()
            ->map(static fn ($item): array => [
                'project_id' => (int) $item->project_id,
                'project_name' => (string) $item->project_name,
                'client_name' => (string) $item->client_name,
                'valid_from' => null,
                'valid_to' => null,
            ]);

        return $current->merge($legacy)
            ->unique('project_id')->values()->all();
    }
}
