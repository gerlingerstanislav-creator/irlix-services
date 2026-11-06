<?php

namespace App\Services;

use App\Models\Employee;
use App\Support\EmployeePurgeRepository;
use Illuminate\Support\Facades\DB;
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
            'finance' => rtrim((string) env('FINANCE_INTERNAL_URL', 'http://finance:8000'), '/'),
            'equipment' => rtrim((string) env('EQUIPMENT_INTERNAL_URL', 'http://equipment:8000'), '/'),
            'platform-core' => rtrim((string) env('PLATFORM_CORE_INTERNAL_URL', 'http://platform-core:8000'), '/'),
            // Storage is last: it also erases S3 bytes, not only DB references.
            'storage' => rtrim((string) env('STORAGE_INTERNAL_URL', 'http://storage-api:8000'), '/'),
        ];

        // Before Timesheets deletes even an unlocked hour, ensure Clients will
        // not reject the eventual term/membership cleanup as a protected period.
        // This is a read-only early guard, not a distributed ACID transaction:
        // services can still change between preflight and their purge calls.
        try {
            $check = Http::acceptJson()
                ->withHeaders(['X-Irlix-Internal-Purge-Token' => $purgeToken])
                ->retry(2, 200, throw: false)
                ->timeout(60)
                ->post($targets['clients']."/internal/system/employees/{$employeeId}/purge/preflight", $payload);
        } catch (Throwable $error) {
            return [
                'status' => 502, 'message' => 'Cross-service employee preflight failed',
                'service' => 'clients', 'reason' => $error->getMessage(),
            ];
        }
        if (!$check->successful()) return [
            'status' => in_array($check->status(), [409, 423], true) ? $check->status() : 502,
            'message' => 'Cross-service employee preflight failed',
            'service' => 'clients', 'upstream_status' => $check->status(),
        ];

        $memberIds = $check->json('client_member_ids');
        if (!is_array($memberIds)
            || count($memberIds) > 10000
            || collect($memberIds)->contains(static fn ($id) =>
                !((is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0))) {
            return ['status' => 502, 'service' => 'clients',
                'message' => 'Incomplete Clients membership preflight'];
        }
        $payload['client_member_ids'] = array_values(array_unique(array_map('intval', $memberIds)));

        foreach ($targets as $service => $base) {
            try {
                $response = Http::acceptJson()
                    ->withHeaders(['X-Irlix-Internal-Purge-Token' => $purgeToken])
                    ->retry(2, 200, throw: false)
                    ->timeout(60)
                    ->post("{$base}/internal/system/employees/{$employeeId}/purge", $payload);
            } catch (Throwable $error) {
                return [
                    'status' => 502, 'message' => 'Cross-service employee purge failed',
                    'service' => $service, 'reason' => $error->getMessage(),
                ];
            }
            if (!$response->successful()) return [
                // Protected financial history is an expected business refusal, not
                // an unavailable upstream. Keep the 423 status for the admin UI.
                'status' => in_array($response->status(), [409, 423], true) ? $response->status() : 502,
                'message' => 'Cross-service employee purge failed',
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

        // Synthetic API contract tests pass a non-persisted model, while the
        // real controller always looks up an existing persisted employee.
        if ($employee->exists) {
            DB::transaction(static function () use ($employee): void {
                (new EmployeePurgeRepository())->erase($employee);
            });
        }
        return ['status' => 204];
    }
}
