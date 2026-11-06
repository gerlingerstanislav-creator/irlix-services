<?php

namespace App\Services;

use App\Models\LegacyProjectMember;
use App\Models\ProjectAssignment;
use App\Models\ClientMemberFeedback;

final class SpecialistsProjectDirectory
{
    /**
     * Read-only, minimal specialist project projection. Clients owns assignments
     * and legacy member records; no terms, rates, contacts or HR data leave this API.
     */

    /**
     * Only project assignment milestones and project feedback text are exported.
     * Terms, hourly rates, contact details and client access scopes stay here.
     * Each assignment is preserved, including repeat project connections.
     */
    public function historyForEmployee(int $employeeId): array
    {
        $events = [];
        $at = static fn ($value): ?string => $value
            ? substr((string) $value, 0, 10).'T12:00:00' : null;
        $assignments = ProjectAssignment::query()->from('project_assignments as a')
            ->join('client_members as cm', 'cm.id', '=', 'a.client_member_id')
            ->join('projects as p', 'p.id', '=', 'a.project_id')
            ->join('clients as c', 'c.id', '=', 'p.client_id')
            ->where('cm.employee_id', $employeeId)
            ->select(['a.id', 'a.valid_from', 'a.valid_to',
                'p.name as project_name', 'c.name as client_name'])
            ->get();
        foreach ($assignments as $row) {
            $caption = trim(($row->client_name ?: 'Клиент').' · '.($row->project_name ?: 'Проект'));
            if ($at($row->valid_from)) $events[] = [
                'id' => 'clients-connect-'.$row->id, 'type' => 'project',
                'title' => 'Подключение к проекту', 'text' => $caption,
                'created_at' => $at($row->valid_from),
            ];
            if ($at($row->valid_to)) $events[] = [
                'id' => 'clients-disconnect-'.$row->id, 'type' => 'project',
                'title' => 'Отключение от проекта', 'text' => $caption,
                'created_at' => $at($row->valid_to),
            ];
        }

        $feedbacks = ClientMemberFeedback::query()->from('client_member_feedbacks as f')
            ->join('client_members as cm', 'cm.id', '=', 'f.client_member_id')
            ->leftJoin('projects as p', 'p.id', '=', 'f.project_id')
            ->where('cm.employee_id', $employeeId)
            ->select(['f.id', 'f.text', 'f.occurred_on', 'f.created_at',
                'f.created_by_username', 'p.name as project_name'])
            ->get();
        foreach ($feedbacks as $feedback) {
            $when = $feedback->occurred_on
                ? $at($feedback->occurred_on) : (string) $feedback->created_at;
            if (!$when) continue;
            $events[] = [
                'id' => 'clients-feedback-'.$feedback->id,
                'type' => 'feedback', 'title' => 'Фидбек с проекта',
                'text' => ($feedback->project_name ? $feedback->project_name.' — ' : '').$feedback->text,
                'author' => $feedback->created_by_username,
                'created_at' => $when,
            ];
        }
        usort($events, static fn ($a, $b) => strcmp($a['created_at'], $b['created_at'])
            ?: strcmp($a['id'], $b['id']));
        return $events;
    }

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
