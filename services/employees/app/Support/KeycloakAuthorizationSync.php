<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Employees owns assignments; Keycloak publishes their verified snapshot in
 * realm_access.roles. This class is called by an asynchronous retrying worker,
 * never from the middleware of an end-user service.
 */
final class KeycloakAuthorizationSync
{
    private ?string $token = null;
    private array $roleCache = [];

    public static function grants(array $access): array
    {
        $labels = (array) ($access['roles'] ?? []);
        foreach ((array) ($access['services'] ?? []) as $service => $allowed) {
            if ($allowed === true) $labels[] = 'service:'.$service;
        }
        if (($access['scope'] ?? '') === 'all') $labels[] = 'scope:all';
        elseif (($access['scope'] ?? '') === 'subtree') {
            foreach ((array) ($access['department_ids'] ?? []) as $id) {
                if ((int) $id > 0) $labels[] = 'scope:department-'.(int) $id;
            }
        }
        $labels = array_values(array_unique(array_filter($labels, static fn ($v) =>
            is_string($v) && preg_match('/^[a-z0-9]+(?:[-:][a-z0-9]+)*$/D', $v) === 1
        )));
        sort($labels, SORT_STRING);
        return $labels;
    }

    public function reconcile(int $employeeId): void
    {
        $employee = DB::table('employees')->where('id', $employeeId)->first();
        // Hard-deleted employees have their Keycloak identity deleted first.
        if (!$employee || !$employee->keycloak_user_id) return;

        $roles = [];
        if (($employee->employment_status ?? '') === 'Трудоустроен' || $employee->login === 'admin') {
            $identity = Request::create('/api/access/me', 'GET');
            $identity->attributes->set('identity', [
                'sub' => $employee->keycloak_user_id,
                'preferred_username' => $employee->login,
            ]);
            $access = (new EmployeesAccess())->resolve($identity);
            $roles = self::grants($access);
        }

        $userId = rawurlencode((string) $employee->keycloak_user_id);
        $user = $this->api('GET', "/users/{$userId}")->json();
        if (!is_array($user) || !isset($user['id'])) throw new RuntimeException('Keycloak employee identity not found');
        $attrs = is_array($user['attributes'] ?? null) ? $user['attributes'] : [];
        $old = array_values(array_filter((array) ($attrs['irlix_managed_roles'] ?? []), 'is_string'));

        $mapped = $this->api('GET', "/users/{$userId}/role-mappings/realm")->json();
        if (!is_array($mapped)) throw new RuntimeException('Keycloak role mapping is unavailable');
        $active = [];
        foreach ($mapped as $record) if (isset($record['name'])) $active[$record['name']] = $record;

        // First revoke obsolete IRLIX-owned roles; never touch unrelated realm roles.
        $remove = [];
        foreach ($old as $name) {
            if (!in_array($name, $roles, true) && isset($active[$name])) $remove[] = $active[$name];
        }
        if ($remove) $this->api('DELETE', "/users/{$userId}/role-mappings/realm", $remove);

        $add = [];
        foreach ($roles as $name) {
            if (isset($active[$name])) continue;
            $add[] = $this->ensureRole($name);
        }
        if ($add) $this->api('POST', "/users/{$userId}/role-mappings/realm", $add);

        // Preserve unrelated Keycloak attributes. The marker is the removal
        // allowlist in subsequent reconciliations, so only write after success.
        $attrs['irlix_managed_roles'] = $roles;
        $this->api('PUT', "/users/{$userId}", ['attributes' => $attrs]);
    }

    private function ensureRole(string $name): array
    {
        if (isset($this->roleCache[$name])) return $this->roleCache[$name];
        $encoded = rawurlencode($name);
        $response = $this->raw('GET', "/roles/{$encoded}");
        if ($response->status() === 404) {
            $this->api('POST', '/roles', [
                'name' => $name,
                'description' => 'IRLIX-managed authorization grant',
            ]);
            $response = $this->raw('GET', "/roles/{$encoded}");
        }
        if (!$response->successful() || !is_array($response->json()) || empty($response->json('id'))) {
            throw new RuntimeException('Keycloak could not resolve an IRLIX role');
        }
        return $this->roleCache[$name] = $response->json();
    }

    private function api(string $method, string $path, ?array $payload = null): \Illuminate\Http\Client\Response
    {
        $response = $this->raw($method, $path, $payload);
        if (!$response->successful()) throw new RuntimeException('Keycloak authorization sync failed with HTTP '.$response->status());
        return $response;
    }

    private function raw(string $method, string $path, ?array $payload = null, bool $retried = false): \Illuminate\Http\Client\Response
    {
        if (!$this->token) {
            $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
            $login = Http::asForm()->timeout(8)->post("{$base}/realms/master/protocol/openid-connect/token", [
                'client_id' => 'admin-cli',
                'username' => (string) env('KEYCLOAK_ADMIN_USERNAME'),
                'password' => (string) env('KEYCLOAK_ADMIN_PASSWORD'),
                'grant_type' => 'password',
            ]);
            if (!$login->successful() || !$login->json('access_token')) throw new RuntimeException('Keycloak administrator login unavailable');
            $this->token = (string) $login->json('access_token');
        }
        $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
        $realm = rawurlencode((string) env('KEYCLOAK_REALM', 'irlix'));
        $call = fn () => Http::withToken((string) $this->token)->acceptJson()->timeout(12)->send($method,
            "{$base}/admin/realms/{$realm}{$path}",
            $payload === null ? [] : ['json' => $payload]);
        $response = $call();
        // The worker is long-running; refresh expired master-realm admin JWT.
        if ($response->status() === 401 && !$retried) {
            $this->token = null;
            return $this->raw($method, $path, $payload, true);
        }
        return $response;
    }
}
