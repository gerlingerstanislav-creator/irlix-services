<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class EmployeesDirectory
{
    public function access(Request $request): array
    {
        if (filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            // The API's KeycloakBearer verified the signature and issuer.
            $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
            $ids = array_values(array_filter($roles, fn ($role) =>
                is_string($role) && preg_match('/^employee-id:[1-9][0-9]*$/D', $role)));
            $local = filter_var(env('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
                && is_array($request->attributes->get('vacations_local_permissions'));
            // The authenticated local matrix owns service admission. Legacy
            // service:vacations is a transition marker, never a V2 grant.
            if (count($ids) !== 1 || (!$local && !in_array('service:vacations', $roles, true)))
                throw new DomainException('Недостаточно прав для отпуска');
            $employeeId = (int) substr($ids[0], strlen('employee-id:'));
            // A previously signed privileged role is not enough to grant
            // another person's sensitive absence data after immediate
            // revocation in Employees. Check CURRENT persisted assignments,
            // fail closed on source outage, and never trust previewed roles.
            $roles = $this->reconcilePrivilegedAccess($request, $roles, $employeeId);
            if ($local) {
                // Never reimport legacy global scope markers into V2.
                return ['roles' => $roles, 'employee_id' => $employeeId,
                    'scope' => 'none', 'department_ids' => []];
            }
            $scopeIds = array_values(array_map(fn ($role) => (int) substr($role, strlen('scope:department-')),
                array_filter($roles, fn ($role) => is_string($role) && preg_match('/^scope:department-[1-9][0-9]*$/D', $role))));
            return [
                'roles' => $roles, 'employee_id' => $employeeId,
                'scope' => in_array('scope:all', $roles, true) ? 'all' : ($scopeIds ? 'subtree' : 'none'),
                'department_ids' => $scopeIds,
            ];
        }
        return $this->get($request, '/access/me');
    }

    /**
     * Return only privilege labels that are BOTH present in a verified JWT
     * and still assigned by Employees. No new rights are conferred by the
     * lookup; an unrelated Employees permission never authorizes Vacations.
     */
    public static function limitToCurrentPrivilegedRoles(
        array $jwtRoles, int $actorId, array $current
    ): array {
        if ($actorId <= 0 || (int) ($current['employee_id'] ?? 0) !== $actorId
            || !is_array($current['roles'] ?? null)) {
            throw new RuntimeException('Current employee privileges cannot be verified');
        }
        $currentRoles = (array) $current['roles'];
        $guarded = ['personnel-officer', 'special:personnel-officer',
            'platform-admin', 'special:platform-admin'];
        $safe = [];
        foreach ($jwtRoles as $role) {
            if (!is_string($role)) continue;
            $canonical = str_starts_with($role, 'special:') ? substr($role, 8) : $role;
            if (in_array($role, $guarded, true) && !in_array($canonical, $currentRoles, true)) {
                continue;
            }
            $safe[] = $role;
        }
        return $safe;
    }

    private function reconcilePrivilegedAccess(Request $request, array $roles, int $employeeId): array
    {
        if (!array_intersect($roles, ['personnel-officer','special:personnel-officer',
            'platform-admin','special:platform-admin'])) return $roles;
        // Even GET previews must not authorize download or privilege reuse.
        if ($request->header('X-Irlix-Role-Preview') !== null) {
            // Role preview is not an access token with current privileges;
            // never reuse it to read another employee's sensitive records.
            throw new DomainException('Просмотр роли не разрешает доступ к кадровым данным.');
        }
        // Employees /access/me is available to authenticated users even
        // without employees.read. Do not forward any preview headers.
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) throw new RuntimeException('Authentication token missing');
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(5)->get($base.'/access/me');
        } catch (Throwable) {
            throw new RuntimeException('Current privileged roles could not be checked');
        }
        if (!$response->successful()) {
            throw new RuntimeException('Current privileged roles could not be checked');
        }
        $current = $response->json('data');
        if (!is_array($current)) {
            throw new RuntimeException('Invalid current privileged role response');
        }
        $verified = self::limitToCurrentPrivilegedRoles($roles, $employeeId, $current);
        // A revoked superuser/personnel token may also carry an old
        // scope:all claim. Deny the entire stale token immediately instead
        // of merely removing role labels while retaining stale scope grants.
        if (count($verified) !== count($roles)) {
            throw new DomainException('Привилегии изменены. Обновите авторизацию.');
        }
        return $verified;
    }

    public function vacationsAccess(Request $request, int $employeeId): array
    {
        $access = $this->access($request);
        // In JWT mode Employees currently verifies privileged assignments
        // against its persisted source-of-truth; it never adds local rights.
        if (filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return $access;
        }
        $roles = array_map(
            fn ($role) => str_replace('_', '-', mb_strtolower(trim((string) $role))),
            $access['roles'] ?? []
        );
        if (in_array('personnel-officer', $roles, true)) return $access;

        $context = $this->selfApprovalContext($request);
        foreach ($context['personnel_officers'] ?? [] as $person) {
            if ((int) ($person['employee_id'] ?? 0) !== $employeeId) continue;
            $access['roles'] = array_values(array_unique(array_merge($access['roles'] ?? [], ['personnel-officer'])));
            break;
        }

        return $access;
    }

    public function selfApprovalContext(Request $request): array
    {
        return $this->get($request, '/self/absence-approval-context');
    }

    public function employeeApprovalContext(Request $request, int $employeeId): array
    {
        return $this->get($request, "/absence-approval-context/{$employeeId}");
    }

    public function clientsDirectory(Request $request): array
    {
        return $this->get($request, '/clients-directory');
    }

    public function vacationsDirectory(Request $request): array
    {
        // The old /vacations-directory projection is already filtered by
        // legacy Employees permission/scope and does not contain manager_id.
        // Reusing it for V2 would silently shrink team access, and sometimes
        // make ALL appear to be OWN. The current organization projection
        // provides authoritative IDs and manager assignments; Vacations
        // independently applies its own effective permission + object scope.
        $local = filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && is_array($request->attributes->get('vacations_local_permissions'));
        if ($local) {
            $directory = $this->clientsDirectory($request);
            if (!is_array($directory['employees'] ?? null)
                || !is_array($directory['departments'] ?? null)) {
                throw new RuntimeException('Current organization directory is incomplete');
            }
            return [
                'employees' => array_values($directory['employees']),
                'departments' => array_values($directory['departments']),
            ];
        }
        return $this->get($request, '/vacations-directory');
    }

    public function vacationsHistoricalDirectory(Request $request, int $year): array
    {
        return $this->get($request, '/vacations-directory?historical_year='.$year);
    }

    public function employees(Request $request): array
    {
        return $this->getList($request, '/employees');
    }

    public function departments(Request $request): array
    {
        return $this->getList($request, '/departments');
    }

    private function get(Request $request, string $path): array
    {
        $payload = $this->request($request, $path);
        $data = $payload['data'] ?? null;
        if (!is_array($data)) throw new RuntimeException('Employees lookup returned invalid data');
        return $data;
    }

    private function getList(Request $request, string $path): array
    {
        $payload = $this->request($request, $path);
        $data = $payload['data'] ?? null;
        if (!is_array($data)) throw new RuntimeException('Employees directory returned invalid data');
        return array_values($data);
    }

    private function request(Request $request, string $path): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) throw new RuntimeException('Authentication token missing');

        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        try {
            $response = Http::withToken($token)->acceptJson()
                ->withHeaders($request->header('X-Irlix-Role-Preview') ? ['X-Irlix-Role-Preview' => $request->header('X-Irlix-Role-Preview')] : [])
                ->timeout(5)->get($base.$path);
        } catch (Throwable) {
            throw new RuntimeException('Employees service unavailable');
        }

        if ($response->status() === 403) throw new DomainException('Недостаточно прав для этого действия');
        if ($response->status() === 404) throw new DomainException('Данные сотрудника не найдены');
        if (!$response->successful()) throw new RuntimeException('Employees lookup failed: '.$response->status());

        $payload = $response->json();
        if (!is_array($payload)) throw new RuntimeException('Employees lookup returned invalid data');
        return $payload;
    }
}
