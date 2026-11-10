<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Single source of truth for corporate login and authentication email:
 * Employees, not the onboarding delivery address. Always address Keycloak
 * by the stable verified sub; never look up or attach a person by email.
 *
 * Intentionally independent from realm-role synchronization, which has a
 * separate rollout gate and must not be activated to sync personal identity.
 */
final class KeycloakCorporateProfileSync
{
    public function reconcile(int $employeeId): void
    {
        $employee = Employee::query()->whereKey($employeeId)->first();
        if (!$employee || !$employee->keycloak_user_id
            || $employee->account_type !== Employee::TYPE_EMPLOYEE) return;

        $expected = [
            'username' => trim((string) $employee->login),
            'email' => trim((string) $employee->work_email),
            'firstName' => trim((string) $employee->first_name),
            'lastName' => trim((string) $employee->last_name),
        ];
        if ($expected['username'] === ''
            || !filter_var($expected['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Employees login and corporate email require reconciliation');
        }

        $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
        $realm = rawurlencode((string) env('KEYCLOAK_REALM', 'irlix'));
        $admin = Http::asForm()->timeout(8)->post(
            "{$base}/realms/master/protocol/openid-connect/token", [
                'client_id' => 'admin-cli',
                'username' => (string) env('KEYCLOAK_ADMIN_USERNAME'),
                'password' => (string) env('KEYCLOAK_ADMIN_PASSWORD'),
                'grant_type' => 'password',
            ]
        );
        if (!$admin->successful() || !$admin->json('access_token')) {
            throw new RuntimeException('Keycloak administrator token unavailable');
        }
        $accessToken = (string) $admin->json('access_token');
        $userId = rawurlencode((string) $employee->keycloak_user_id);
        $endpoint = "{$base}/admin/realms/{$realm}/users/{$userId}";
        $current = Http::withToken($accessToken)->acceptJson()->timeout(8)->get($endpoint);
        if (!$current->successful() || $current->json('id') !== $employee->keycloak_user_id) {
            throw new RuntimeException('Keycloak identity lookup or subject verification failed');
        }

        $updates = [];
        foreach ($expected as $name => $value) {
            if ((string) $current->json($name) !== $value) $updates[$name] = $value;
        }
        if (!$updates) return;

        // Verification of the previous email does not establish verification
        // of the newly selected corporate email.
        if (array_key_exists('email', $updates)) $updates['emailVerified'] = false;
        $updated = Http::withToken($accessToken)->acceptJson()->timeout(8)->put($endpoint, $updates);
        if ($updated->status() === 409) {
            throw new RuntimeException('Keycloak login/email collision: operator reconciliation required');
        }
        if ($updated->status() !== 204) {
            throw new RuntimeException('Keycloak corporate profile update failed: HTTP '.$updated->status());
        }
        $verified = Http::withToken($accessToken)->acceptJson()->timeout(8)->get($endpoint);
        if (!$verified->successful()) {
            throw new RuntimeException('Keycloak corporate profile read-back failed');
        }
        foreach ($expected as $name => $value) {
            if ((string) $verified->json($name) !== $value) {
                throw new RuntimeException('Keycloak corporate profile read-back differs from Employees');
            }
        }
        // Concurrent mutation of Employees while the network call was in
        // flight must be re-queued by versioned DB trigger, not lost.
    }
}
