<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * HTTP-independent business permission ownership stays with Recruitment.
 * Routes may delegate here while the existing controller migration proceeds.
 *
 * A role that manages a candidate or an offer cannot implicitly view or
 * modify compensation. This policy is opt-in until verified V2 cutover.
 */
final class RecruitmentCompensationPolicy
{
    public function enforced(Request $request): bool
    {
        return filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function permissions(Request $request): array
    {
        return (array) $request->attributes->get('recruitment_local_permissions', []);
    }

    public function assertWrite(Request $request, array $validated, string $field): void
    {
        if (!$this->enforced($request)) return;
        abort_unless(RecruitmentRoleAuthorization::canWriteSensitiveField(
            $this->permissions($request), $validated, $field
        ), 403, 'Additional permission required for this field');
    }

    public function redact(Request $request, object $record, string $field): object
    {
        if ($this->enforced($request)
            && !($this->permissions($request)['recruitment.compensation.view'] ?? false)) {
            $record->{$field} = null;
        }
        return $record;
    }
}
