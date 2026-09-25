<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EmployeeOnboardingService
{
    public function sendPasswordSetup(int $employeeId): array
    {
        $employee = DB::table('employees')->where('id', $employeeId)->first();
        if (!$employee) throw new RuntimeException('Employee not found');
        if (!$employee->keycloak_user_id) throw new RuntimeException('Keycloak identity is not provisioned');
        if (!$employee->personal_email) throw new RuntimeException('Personal email is required for onboarding');

        $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
        $realm = (string) env('KEYCLOAK_REALM', 'irlix');
        $token = $this->adminToken($base);
        $userId = (string) $employee->keycloak_user_id;
        $userUrl = "{$base}/admin/realms/{$realm}/users/{$userId}";

        $current = Http::withToken($token)->acceptJson()->timeout(8)->get($userUrl);
        if (!$current->successful()) throw new RuntimeException('Keycloak user lookup failed: '.$current->status());

        $representation = $current->json() ?: [];
        $representation['email'] = $employee->personal_email;
        $representation['emailVerified'] = false;
        $representation['requiredActions'] = array_values(array_unique([...(array) ($representation['requiredActions'] ?? []), 'UPDATE_PASSWORD']));
        $updated = Http::withToken($token)->acceptJson()->timeout(8)->put($userUrl, $representation);
        if ($updated->status() !== 204) throw new RuntimeException('Keycloak onboarding email update failed: '.$updated->status());

        $clientId = rawurlencode((string) env('KEYCLOAK_CLIENT_ID', 'irlix-services-web'));
        $redirect = rawurlencode(rtrim((string) env('IRLIX_PUBLIC_URL', 'http://192.168.90.100'), '/').'/');
        $lifespan = max(300, (int) env('ONBOARDING_LINK_LIFESPAN', 86400));
        $actionUrl = "{$userUrl}/execute-actions-email?client_id={$clientId}&redirect_uri={$redirect}&lifespan={$lifespan}";
        $sent = Http::withToken($token)->acceptJson()->timeout(12)->put($actionUrl, ['UPDATE_PASSWORD']);
        if ($sent->status() !== 204) throw new RuntimeException('Keycloak onboarding email failed: '.$sent->status());

        DB::table('employees')->where('id', $employeeId)->update([
            'onboarding_email_status' => 'sent',
            'onboarding_email_sent_at' => now(),
            'onboarding_email_error' => null,
            'updated_at' => now(),
        ]);

        return ['status' => 'sent', 'recipient' => $employee->personal_email];
    }

    public function markFailure(int $employeeId, \Throwable $error): void
    {
        DB::table('employees')->where('id', $employeeId)->update([
            'onboarding_email_status' => 'send_failed',
            'onboarding_email_error' => mb_substr($error->getMessage(), 0, 2000),
            'updated_at' => now(),
        ]);
    }

    private function adminToken(string $base): string
    {
        $response = Http::asForm()->retry(3, 400)->timeout(8)->post("{$base}/realms/master/protocol/openid-connect/token", [
            'client_id' => 'admin-cli',
            'username' => (string) env('KEYCLOAK_ADMIN_USERNAME', 'admin'),
            'password' => (string) env('KEYCLOAK_ADMIN_PASSWORD', 'irlix_keycloak_local'),
            'grant_type' => 'password',
        ]);
        if (!$response->successful() || !$response->json('access_token')) throw new RuntimeException('Keycloak admin token request failed: '.$response->status());
        return (string) $response->json('access_token');
    }
}
