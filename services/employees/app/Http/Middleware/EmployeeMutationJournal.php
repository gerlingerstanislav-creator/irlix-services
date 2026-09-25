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

        $this->writeAudit([
            'occurred_at' => now(),
            'request_id' => $requestId,
            'actor_sub' => $identity['sub'] ?? null,
            'actor_employee_id' => $access['employee_id'] ?? null,
            'actor_name' => $access['employee_name'] ?? ($identity['preferred_username'] ?? $identity['email'] ?? null),
            'action' => $success ? $operation : $operation.'.failed',
            'status' => $success ? 'success' : 'failed',
            'target_type' => $target['type'],
            'target_id' => $target['id'],
            'target_label' => $target['label'],
            'before' => $this->json($before),
            'after' => $this->json($after),
            'metadata' => $this->json([
                'method' => $method,
                'path' => $path,
                'http_status' => $statusCode,
                'changed_fields' => $this->changedFields($before, $after),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($method === 'POST' && $path === 'api/employees' && $success && $after !== null) {
            $onboardingStatus = $after['onboarding_email_status'] ?? null;
            if (in_array($onboardingStatus, ['sent', 'send_failed'], true)) {
                $this->writeAudit([
                    'occurred_at' => now(),
                    'request_id' => $requestId,
                    'actor_sub' => $identity['sub'] ?? null,
                    'actor_employee_id' => $access['employee_id'] ?? null,
                    'actor_name' => $access['employee_name'] ?? ($identity['preferred_username'] ?? $identity['email'] ?? null),
                    'action' => $onboardingStatus === 'sent' ? 'employee.onboarding_email_sent' : 'employee.onboarding_email_sent.failed',
                    'status' => $onboardingStatus === 'sent' ? 'success' : 'failed',
                    'target_type' => 'employee',
                    'target_id' => $target['id'],
                    'target_label' => $target['label'],
                    'before' => null,
                    'after' => $this->json(['onboarding_email_status' => $onboardingStatus]),
                    'metadata' => $this->json(['source' => 'automatic_on_create', 'http_status' => $statusCode]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $response;
    }

    private function writeAudit(array $row): void
    {
        try {
            DB::table('audit_log')->insert($row);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function operation(string $method, string $path): ?string
    {
        if ($method === 'POST' && $path === 'api/departments') return 'department.created';
        if ($method === 'PUT' && preg_match('#^api/departments/\d+$#', $path)) return 'department.updated';
        if ($method === 'POST' && $path === 'api/employees') return 'employee.created';
        if ($method === 'PATCH' && preg_match('#^api/employees/\d+$#', $path)) return 'employee.updated';
        if ($method === 'DELETE' && preg_match('#^api/employees/\d+$#', $path)) return 'employee.deleted';
        if ($method === 'POST' && preg_match('#^api/employees/\d+/dismiss$#', $path)) return 'employee.dismissed';
        if ($method === 'POST' && preg_match('#^api/employees/\d+/rehire$#', $path)) return 'employee.rehired';
        if ($method === 'POST' && preg_match('#^api/employees/\d+/change-cooperation$#', $path)) return 'employee.cooperation_changed';
        if ($method === 'POST' && preg_match('#^api/employees/\d+/salary-history$#', $path)) return 'employee.salary_changed';
        if ($method === 'DELETE' && preg_match('#^api/employees/\d+/salary-history/\d+$#', $path)) return 'employee.salary_changed';
        if ($method === 'POST' && preg_match('#^api/employees/\d+/onboarding-email$#', $path)) return 'employee.onboarding_email_sent';
        if (in_array($method, ['PUT', 'DELETE'], true) && preg_match('#^api/access/company-admins/\d+$#', $path)) return 'employee.access_changed';
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

    private function json(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
