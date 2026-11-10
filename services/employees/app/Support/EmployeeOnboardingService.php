<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EmployeeOnboardingService
{
    public function sendPasswordSetup(int $employeeId): array
    {
        $employee = Employee::query()->whereKey($employeeId)->first();
        if (!$employee) throw new RuntimeException('Employee not found');
        if (!$employee->keycloak_user_id) throw new RuntimeException('Keycloak identity is not provisioned');
        if (!$employee->personal_email) throw new RuntimeException('Personal email is required for onboarding');

        // This email must temporarily become the Keycloak delivery address.
        // Claim the operation so the authorization worker does not concurrently
        // restore work_email before Keycloak sends the action-link message.
        $claimed = Employee::query()->whereKey($employeeId)
            ->where(function ($query): void {
                $query->where('onboarding_email_status', '<>', 'sending')
                    ->orWhereNull('onboarding_email_status')
                    ->orWhere('updated_at', '<', now()->subMinutes(2));
            })
            ->update(['onboarding_email_status' => 'sending', 'updated_at' => now()]);
        if ($claimed !== 1) throw new RuntimeException('Onboarding email delivery is already running');

        $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
        $realm = (string) env('KEYCLOAK_REALM', 'irlix');
        $token = $this->adminToken($base);
        $userId = (string) $employee->keycloak_user_id;
        $userUrl = "{$base}/admin/realms/{$realm}/users/{$userId}";

        $current = Http::withToken($token)->acceptJson()->timeout(8)->get($userUrl);
        if (!$current->successful()) throw new RuntimeException($this->keycloakError('Keycloak user lookup failed', $current));

        // Keycloak executes the password-setup email against the user's current
        // address. The recipient is personal_email, but the authoritative login
        // email is work_email. Never leave the temporary delivery address as the
        // authentication email (the previous implementation did).
        $representation = $current->json() ?: [];
        $originalVerified = (bool) ($representation['emailVerified'] ?? false);
        $corporateEmail = (string) $employee->work_email;
        if ($corporateEmail === '') {
            throw new RuntimeException('Corporate email is required for onboarding');
        }
        $representation['email'] = $employee->personal_email;
        $representation['emailVerified'] = false;
        $representation['requiredActions'] = array_values(array_unique([
            ...(array) ($representation['requiredActions'] ?? []), 'UPDATE_PASSWORD',
        ]));
        $updated = Http::withToken($token)->acceptJson()->timeout(8)->put($userUrl, $representation);
        if ($updated->status() !== 204) {
            throw new RuntimeException($this->keycloakError('Keycloak onboarding email update failed', $updated));
        }

        try {
            $clientId = rawurlencode((string) env('KEYCLOAK_CLIENT_ID', 'irlix-services-web'));
            $redirect = rawurlencode(rtrim((string) env('IRLIX_PUBLIC_URL', 'http://192.168.90.100'), '/').'/');
            $lifespan = max(300, (int) env('ONBOARDING_LINK_LIFESPAN', 86400));
            $actionUrl = "{$userUrl}/execute-actions-email?client_id={$clientId}&redirect_uri={$redirect}&lifespan={$lifespan}";
            $sent = Http::withToken($token)->acceptJson()->timeout(12)->put($actionUrl, ['UPDATE_PASSWORD']);
            if ($sent->status() !== 204) {
                throw new RuntimeException($this->keycloakError('Keycloak onboarding email failed', $sent));
            }
        } finally {
            // Even if SMTP fails or Keycloak times out, recover the corporate
            // identity. If recovery fails the onboarding operation fails too;
            // the UI must not report delivery success for a divergent identity.
            $representation['email'] = $corporateEmail;
            $representation['emailVerified'] = $originalVerified
                && strcasecmp((string) ($current->json('email') ?? ''), $corporateEmail) === 0;
            $restored = Http::withToken($token)->acceptJson()->timeout(8)->put($userUrl, $representation);
            if ($restored->status() !== 204) {
                throw new RuntimeException($this->keycloakError('Keycloak corporate email restoration failed', $restored));
            }
            $verified = Http::withToken($token)->acceptJson()->timeout(8)->get($userUrl);
            if (!$verified->successful() || strcasecmp((string) $verified->json('email'), $corporateEmail) !== 0) {
                throw new RuntimeException('Keycloak corporate email restoration could not be verified');
            }
        }

        Employee::query()->whereKey($employeeId)->update([
            'onboarding_email_status' => 'sent',
            'onboarding_email_sent_at' => now(),
            'onboarding_email_error' => null,
            'updated_at' => now(),
        ]);

        return ['status' => 'sent', 'recipient' => $employee->personal_email];
    }

    public function markFailure(int $employeeId, \Throwable $error): void
    {
        Employee::query()->whereKey($employeeId)->update([
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
        if (!$response->successful() || !$response->json('access_token')) throw new RuntimeException($this->keycloakError('Keycloak admin token request failed', $response));
        return (string) $response->json('access_token');
    }

    private function keycloakError(string $prefix, $response): string
    {
        $detail = trim((string) $response->json('errorMessage', ''));
        if ($detail === '') $detail = trim((string) $response->json('error', ''));
        if ($detail === '') $detail = trim((string) $response->body());
        $detail = preg_replace('/\s+/', ' ', $detail) ?? '';
        $suffix = $detail !== '' ? ': '.mb_substr($detail, 0, 500) : '';
        return $prefix.': '.$response->status().$suffix;
    }
}
