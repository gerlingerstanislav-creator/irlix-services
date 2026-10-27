<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Executes the existing irreversible cross-service purge in the same order
 * as the legacy endpoint. Failures return a structured result; a transaction
 * cannot safely span independent services or Keycloak.
 */
final class EmployeeDeletionService
{
    public function find(int $employeeId): ?Employee
    {
        return Employee::query()->whereKey($employeeId)->first();
    }

    public function purge(Employee $employee): array
    {
        $employeeId = (int) $employee->id;
        $purgeToken = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
        if ($purgeToken === '') return ['status' => 503,
            'message' => 'Cross-service purge is not configured'];

        $payload = [
            'employee_id' => $employeeId,
            'keycloak_subject' => $employee->keycloak_user_id ?: null,
            'username' => $employee->login ?: null,
            'employee_name' => $employee->full_name ?: null,
        ];
        $targets = [
            // Timesheets must resolve ownership against Clients before Clients removes the membership.
            // Keep the financially protected services first, before unrelated data is erased.
            'timesheets' => rtrim((string) env('TIMESHEETS_INTERNAL_URL', 'http://timesheets:8000'), '/'),
            'clients' => rtrim((string) env('CLIENTS_INTERNAL_URL', 'http://clients:8000'), '/'),
            'vacations' => rtrim((string) env('VACATIONS_INTERNAL_URL', 'http://vacations:8000'), '/'),
            'specialists' => rtrim((string) env('SPECIALISTS_INTERNAL_URL', 'http://specialists:8000'), '/'),
            'recruitment' => rtrim((string) env('RECRUITMENT_INTERNAL_URL', 'http://recruitment:8000'), '/'),
        ];

        foreach ($targets as $service => $base) {
            try {
                $response = Http::acceptJson()
                    ->withHeaders(['X-Irlix-Internal-Purge-Token' => $purgeToken])
                    ->retry(2, 200, throw: false)
                    ->timeout(15)
                    ->post("{$base}/internal/system/employees/{$employeeId}/purge", $payload);
            } catch (Throwable $error) {
                return [
                    'status' => 502, 'message' => 'Cross-service employee purge failed',
                    'service' => $service, 'reason' => $error->getMessage(),
                ];
            }
            if (!$response->successful()) return [
                'status' => 502, 'message' => 'Cross-service employee purge failed',
                'service' => $service, 'upstream_status' => $response->status(),
            ];
        }

        if ($employee->keycloak_user_id) {
            $base = rtrim((string) env('KEYCLOAK_URL'), '/');
            $realm = (string) env('KEYCLOAK_REALM');
            $token = Http::asForm()->timeout(8)
                ->post("{$base}/realms/master/protocol/openid-connect/token", [
                    'client_id' => 'admin-cli',
                    'username' => (string) env('KEYCLOAK_ADMIN_USERNAME'),
                    'password' => (string) env('KEYCLOAK_ADMIN_PASSWORD'),
                    'grant_type' => 'password',
                ]);
            if (!$token->successful() || !$token->json('access_token')) {
                return ['status' => 502, 'message' => 'Keycloak unavailable'];
            }
            $deleted = Http::withToken((string) $token->json('access_token'))->timeout(8)
                ->delete("{$base}/admin/realms/{$realm}/users/{$employee->keycloak_user_id}");
            if (!in_array($deleted->status(), [204, 404], true)) {
                return ['status' => 502, 'message' => 'Failed to delete Keycloak identity'];
            }
        }

        $employee->delete();
        return ['status' => 204];
    }
}
