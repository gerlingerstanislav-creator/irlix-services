<?php

namespace App\Services;

/**
 * UI action projections never substitute for backend permission checks,
 * but they must not advertise actions the local policy would reject.
 * Business status and approver restrictions are intersected separately.
 */
final class VacationsActionPolicy
{
    private const REQUIRED = [
        'edit' => 'vacations.mine.manage',
        'submit' => 'vacations.mine.manage',
        'delete' => 'vacations.manage.manage',
        'approve' => 'vacations.approvals.manage',
        'provide' => 'vacations.approvals.manage',
        'reject' => 'vacations.approvals.manage',
        'withdraw' => 'vacations.approvals.manage',
        'return_to_planned' => 'vacations.approvals.manage',
        'view_attachments' => 'vacations.documents.view',
        'upload_attachment' => 'vacations.documents.manage',
    ];

    public static function visible(array $actions, ?array $permissions): array
    {
        if ($permissions === null) return array_values(array_unique($actions));
        return array_values(array_unique(array_filter($actions,
            static function ($action) use ($permissions): bool {
                $permission = self::REQUIRED[$action] ?? null;
                return $permission === null
                    ? in_array($action, ['view', 'history'], true)
                    : (($permissions[$permission]['allowed'] ?? false) === true);
            }
        )));
    }
}
