<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Support\Facades\Http;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class EmployeeIdentityService
{
    private function base(): string
    {
        return rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/auth'), '/');
    }

    private function realm(): string
    {
        return (string) env('KEYCLOAK_REALM', 'irlix');
    }

    private function token(): string
    {
        $response = Http::asForm()->retry(5, 500)->timeout(8)->post(
            $this->base().'/realms/master/protocol/openid-connect/token',
            [
                'client_id' => 'admin-cli',
                'username' => (string) env('KEYCLOAK_ADMIN_USERNAME', 'admin'),
                'password' => (string) env('KEYCLOAK_ADMIN_PASSWORD', 'irlix_keycloak_local'),
                'grant_type' => 'password',
            ]
        );
        if (!$response->successful() || !$response->json('access_token')) {
            throw new RuntimeException('Keycloak admin token request failed: '.$response->status());
        }
        return (string) $response->json('access_token');
    }

    private function call(string $method, string $path, array $json = []): \Illuminate\Http\Client\Response
    {
        $request = Http::withToken($this->token())->acceptJson()->retry(2, 300)->timeout(8);
        $url = $this->base().'/admin/realms/'.$this->realm().$path;
        return match (strtoupper($method)) {
            'GET' => $request->get($url, $json),
            'POST' => $request->post($url, $json),
            'PUT' => $request->put($url, $json),
            'DELETE' => $request->delete($url, $json),
            default => throw new InvalidArgumentException('Unsupported Keycloak method'),
        };
    }

    private function group(string $name): ?object
    {
        $response = $this->call('GET', '/groups', ['search' => $name, 'exact' => 'true']);
        if (!$response->successful()) {
            throw new RuntimeException('Keycloak group lookup failed: '.$response->status());
        }
        foreach ($response->json() ?? [] as $group) {
            if (($group['name'] ?? null) === $name) return (object) $group;
        }
        return null;
    }

    public function syncDepartment(string $userId, ?int $departmentId): void
    {
        $knownGroups = Department::query()->whereNotNull('ldap_group')
            ->pluck('ldap_group')->filter()->values()->all();
        $response = $this->call('GET', "/users/{$userId}/groups");
        if (!$response->successful()) {
            throw new RuntimeException('Keycloak user group lookup failed: '.$response->status());
        }
        foreach ($response->json() ?? [] as $group) {
            if (in_array($group['name'] ?? null, $knownGroups, true)) {
                $removed = $this->call('DELETE', "/users/{$userId}/groups/{$group['id']}");
                if (!in_array($removed->status(), [204, 404], true)) {
                    throw new RuntimeException('Keycloak group removal failed: '.$removed->status());
                }
            }
        }
        if (!$departmentId) return;
        $groupName = Department::query()->whereKey($departmentId)->value('ldap_group');
        if (!$groupName) return;
        $group = $this->group((string) $groupName);
        if (!$group?->id) throw new RuntimeException("Keycloak group {$groupName} not found");
        $added = $this->call('PUT', "/users/{$userId}/groups/{$group->id}");
        if ($added->status() !== 204) {
            throw new RuntimeException('Keycloak group assignment failed: '.$added->status());
        }
    }

    public function provision(object $employee): array
    {
        $password = bin2hex(random_bytes(8)).'Aa1!';
        $response = $this->call('POST', '/users', [
            'username' => $employee->login,
            'email' => $employee->work_email,
            'firstName' => $employee->first_name,
            'lastName' => $employee->last_name,
            'enabled' => true,
            'emailVerified' => false,
            'requiredActions' => ['UPDATE_PASSWORD'],
            'credentials' => [[
                'type' => 'password', 'value' => $password, 'temporary' => true,
            ]],
        ]);
        if ($response->status() === 409) {
            throw new DomainException('Такой логин уже существует в Keycloak. Выберите другой логин.');
        }
        if ($response->status() !== 201) {
            throw new RuntimeException('Keycloak user creation failed: '.$response->status());
        }
        $location = (string) $response->header('Location');
        $userId = basename(parse_url($location, PHP_URL_PATH) ?: '');
        if ($userId === '') throw new RuntimeException('Keycloak did not return created user id');
        $this->syncDepartment($userId, $employee->department_id ? (int) $employee->department_id : null);
        return ['user_id' => $userId, 'temporary_password' => $password];
    }

    public function update(object $employee, array $changes = []): void
    {
        if (!$employee->keycloak_user_id) return;
        $payload = [
            'username' => $changes['login'] ?? $employee->login,
            'email' => isset($changes['login'])
                ? $changes['login'].'@irlix.ru' : $employee->work_email,
            'firstName' => $changes['first_name'] ?? $employee->first_name,
            'lastName' => $changes['last_name'] ?? $employee->last_name,
        ];
        $response = $this->call('PUT', "/users/{$employee->keycloak_user_id}", $payload);
        if ($response->status() === 409) {
            throw new DomainException('Такой логин или рабочая почта уже существуют в Keycloak.');
        }
        if ($response->status() !== 204) {
            throw new RuntimeException('Keycloak user update failed: '.$response->status());
        }
    }

    public function setEnabled(object $employee, bool $enabled): void
    {
        if (!$employee->keycloak_user_id) return;
        $response = $this->call('PUT', "/users/{$employee->keycloak_user_id}", ['enabled' => $enabled]);
        if ($response->status() !== 204) {
            throw new RuntimeException('Keycloak enable/disable failed: '.$response->status());
        }
    }

    public function health(): string
    {
        try {
            $response = Http::timeout(3)->get(
                $this->base().'/realms/'.$this->realm().'/.well-known/openid-configuration'
            );
            return $response->successful() ? 'ok' : 'error';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
