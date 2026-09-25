<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EmployeeMutationJournal
{
    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return $next($request);

        $path = $request->path();
        if (!str_starts_with($path, 'api/')) return $next($request);

        return DB::transaction(function () use ($request, $next, $method, $path) {
            $operation = $this->operation($method, $path);
            if (!$operation) return $next($request);

            $before = $this->snapshot($path);
            $response = $next($request);
            $statusCode = $response->getStatusCode();
            $success = $statusCode < 400;
            $responseData = $this->responseData($response);
            $after = $success ? $this->snapshot($path, $responseData) : null;
            $target = $this->target($path, $responseData, $before, $after);
            $requestId = (string) ($request->headers->get('X-Request-ID') ?: Str::uuid());
            $access = (array) $request->attributes->get('employees_access', []);
            $identity = (array) $request->attributes->get('identity', []);

            DB::table('audit_log')->insert([
                'occurred_at' => now(),
                'request_id' => $requestId,
                'actor_sub' => $identity['sub'] ?? null,
                'actor_employee_id' => $access['employee_id'] ?? null,
                'actor_name' => $access['employee_name'] ?? ($identity['preferred_username'] ?? $identity['email'] ?? null),
                'action' => $success ? $operation['action'] : $operation['action'].'.failed',
                'status' => $success ? 'success' : 'failed',
                'target_type' => $target['type'],
                'target_id' => $target['id'],
                'target_label' => $target['label'],
                'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'metadata' => json_encode([
                    'method' => $method,
                    'path' => $path,
                    'http_status' => $statusCode,
                    'changed_fields' => $this->changedFields($before, $after),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($success) {
                foreach ($this->events($operation['event'] ?? null, $before, $after, $target, $requestId, $method) as $event) {
                    DB::table('outbox_events')->insert([
                        'id' => (string) Str::uuid(),
                        'event_type' => $event['type'],
                        'event_version' => 1,
                        'aggregate_type' => 'employee',
                        'aggregate_id' => (string) ($target['id'] ?? ''),
                        'occurred_at' => now(),
                        'payload' => json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'status' => 'pending',
                        'attempts' => 0,
                        'available_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return $response;
        });
    }

    private function operation(string $method, string $path): ?array
    {
        if ($method === 'POST' && $path === 'api/departments') return ['action' => 'department.created'];
        if ($method === 'PUT' && preg_match('#^api/departments/\d+$#', $path)) return ['action' => 'department.updated'];
        if ($method === 'POST' && $path === 'api/employees') return ['action' => 'employee.created', 'event' => 'employee.created'];
        if ($method === 'PATCH' && preg_match('#^api/employees/\d+$#', $path)) return ['action' => 'employee.updated', 'event' => 'employee.updated'];
        if ($method === 'DELETE' && preg_match('#^api/employees/\d+$#', $path)) return ['action' => 'employee.deleted', 'event' => 'employee.deleted'];
        if ($method === 'POST' && preg_match('#^api/employees/\d+/dismiss$#', $path)) return ['action' => 'employee.dismissed', 'event' => 'employee.dismissed'];
        if ($method === 'POST' && preg_match('#^api/employees/\d+/rehire$#', $path)) return ['action' => 'employee.rehired', 'event' => 'employee.rehired'];
        if ($method === 'POST' && preg_match('#^api/employees/\d+/change-cooperation$#', $path)) return ['action' => 'employee.cooperation_changed', 'event' => 'employee.updated'];
        if ($method === 'POST' && preg_match('#^api/employees/\d+/salary-history$#', $path)) return ['action' => 'employee.salary_changed'];
        if ($method === 'DELETE' && preg_match('#^api/employees/\d+/salary-history/\d+$#', $path)) return ['action' => 'employee.salary_changed'];
        if ($method === 'POST' && preg_match('#^api/employees/\d+/onboarding-email$#', $path)) return ['action' => 'employee.onboarding_email_sent'];
        if (in_array($method, ['PUT', 'DELETE'], true) && preg_match('#^api/access/company-admins/\d+$#', $path)) return ['action' => 'employee.access_changed', 'event' => 'employee.access_changed'];
        return null;
    }

    private function snapshot(string $path, array $responseData = []): ?array
    {
        if ($path === 'api/employees') {
            $id = (int) ($responseData['data']['id'] ?? 0);
            return $id ? $this->employeeSnapshot($id) : null;
        }

        if (preg_match('#^api/employees/(\d+)#', $path, $matches)) {
            return $this->employeeSnapshot((int) $matches[1]);
        }

        if ($path === 'api/departments') {
            $id = (int) ($responseData['data']['id'] ?? 0);
            return $id ? $this->departmentSnapshot($id) : null;
        }

        if (preg_match('#^api/departments/(\d+)$#', $path, $matches)) {
            return $this->departmentSnapshot((int) $matches[1]);
        }

        if (preg_match('#^api/access/company-admins/(\d+)$#', $path, $matches)) {
            return $this->employeeSnapshot((int) $matches[1]);
        }

        return null;
    }

    private function employeeSnapshot(int $employeeId): ?array
    {
        $employee = DB::table('employees')->where('id', $employeeId)->first();
        if (!$employee) return null;
        $data = (array) $employee;
        $data['access_roles'] = DB::table('employee_access_roles')->where('employee_id', $employeeId)->orderBy('role')->pluck('role')->all();
        $salary = DB::table('salary_history')->where('employee_id', $employeeId)->whereNull('effective_to')->first();
        $data['active_salary'] = $salary ? (array) $salary : null;
        return $data;
    }

    private function departmentSnapshot(int $departmentId): ?array
    {
        $department = DB::table('departments')->where('id', $departmentId)->first();
        return $department ? (array) $department : null;
    }

    private function responseData(Response $response): array
    {
        if ($response instanceof JsonResponse) return (array) $response->getData(true);
        return [];
    }

    private function target(string $path, array $responseData, ?array $before, ?array $after): array
    {
        $type = str_contains($path, 'departments') ? 'department' : 'employee';
        $id = null;
        if (preg_match('#^api/(?:employees|departments)/(\d+)#', $path, $matches)) $id = $matches[1];
        if (preg_match('#^api/access/company-admins/(\d+)$#', $path, $matches)) $id = $matches[1];
        $id ??= isset($responseData['data']['id']) ? (string) $responseData['data']['id'] : null;
        $source = $after ?? $before ?? [];
        $label = $type === 'employee' ? ($source['full_name'] ?? $source['work_email'] ?? null) : ($source['name'] ?? null);
        return ['type' => $type, 'id' => $id, 'label' => $label];
    }

    private function changedFields(?array $before, ?array $after): array
    {
        if ($before === null || $after === null) return [];
        $ignored = ['created_at', 'updated_at'];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        return array_values(array_filter($keys, function ($key) use ($before, $after, $ignored) {
            if (in_array($key, $ignored, true)) return false;
            return json_encode($before[$key] ?? null) !== json_encode($after[$key] ?? null);
        }));
    }

    private function events(?string $eventType, ?array $before, ?array $after, array $target, string $requestId, string $method): array
    {
        if (!$eventType || $target['type'] !== 'employee' || !$target['id']) return [];
        $events = [];
        $source = $after ?? $before ?? [];
        $payload = $this->publicEmployeePayload($source, $requestId);

        if ($eventType === 'employee.access_changed') {
            $beforeRoles = $before['access_roles'] ?? [];
            $afterRoles = $after['access_roles'] ?? [];
            $payload['role'] = 'company-admin';
            $payload['granted'] = in_array('company-admin', $afterRoles, true) && !in_array('company-admin', $beforeRoles, true);
        }

        $events[] = ['type' => $eventType, 'payload' => $payload];

        if ($eventType === 'employee.updated' && ($before['department_id'] ?? null) !== ($after['department_id'] ?? null)) {
            $events[] = ['type' => 'employee.department_changed', 'payload' => $payload + [
                'previous_department_id' => $before['department_id'] ?? null,
                'department_id' => $after['department_id'] ?? null,
            ]];
        }

        return $events;
    }

    private function publicEmployeePayload(array $employee, string $requestId): array
    {
        return [
            'request_id' => $requestId,
            'employee_id' => $employee['id'] ?? null,
            'keycloak_user_id' => $employee['keycloak_user_id'] ?? null,
            'login' => $employee['login'] ?? null,
            'work_email' => $employee['work_email'] ?? null,
            'full_name' => $employee['full_name'] ?? null,
            'department_id' => $employee['department_id'] ?? null,
            'position' => $employee['position'] ?? null,
            'employment_status' => $employee['employment_status'] ?? null,
            'cooperation_type' => $employee['cooperation_type'] ?? null,
            'work_format' => $employee['work_format'] ?? null,
        ];
    }
}
