<?php

namespace App\Http\Middleware;

use App\Support\EmployeesAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeesAuthorization
{
    public function __construct(private readonly EmployeesAccess $accessResolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health')) return $next($request);

        $access = $this->accessResolver->resolve($request);
        $request->attributes->set('employees_access', $access);

        // Own profile lookup is a platform integration contract and is available
        // to every authenticated employee even when the Employees UI itself is not.
        if ($request->is('api/self') || $request->is('api/access/me')) return $next($request);

        if (!($access['allowed'] ?? false)) {
            return response()->json(['message' => 'Employees access is not granted for this account'], 403);
        }

        $method = strtoupper($request->method());
        $path = $request->path();

        if ($request->is('api/audit*') && !($access['permissions']['audit.read'] ?? false)) {
            return response()->json(['message' => 'Audit permission required'], 403);
        }

        if ($request->is('api/access/company-admins*') && !($access['permissions']['access.manage'] ?? false)) {
            return response()->json(['message' => 'Company administrator permission required'], 403);
        }

        if (preg_match('#^api/employees/(\d+)#', $path, $matches)) {
            $employeeId = (int) $matches[1];
            if (!$this->accessResolver->canSeeEmployee($access, $employeeId)) {
                return response()->json(['message' => 'Employee is outside your access scope'], 403);
            }
        }

        $isSalaryMutation = preg_match('#^api/employees/\d+/salary-history(?:/\d+)?$#', $path) && $method !== 'GET';
        if ($isSalaryMutation && !($access['permissions']['employees.salary.manage'] ?? false)) {
            return response()->json(['message' => 'Salary management permission required'], 403);
        }

        $isEmployeeMutation = str_starts_with($path, 'api/employees') && $method !== 'GET' && !$isSalaryMutation;
        if ($isEmployeeMutation && !($access['permissions']['employees.manage'] ?? false)) {
            return response()->json(['message' => 'Employee management permission required'], 403);
        }

        $isDepartmentMutation = str_starts_with($path, 'api/departments') && $method !== 'GET';
        if ($isDepartmentMutation && !($access['permissions']['organization.manage'] ?? false)) {
            return response()->json(['message' => 'Organization management permission required'], 403);
        }

        $response = $next($request);
        if (!$response instanceof JsonResponse) return $response;

        $payload = $response->getData(true);

        if ($method === 'GET' && $path === 'api/employees' && isset($payload['data']) && is_array($payload['data']) && ($access['scope'] ?? 'none') !== 'all') {
            $allowedDepartments = $access['department_ids'] ?? [];
            $payload['data'] = array_values(array_filter($payload['data'], fn ($employee) => isset($employee['department_id']) && in_array((int) $employee['department_id'], $allowedDepartments, true)));
            if (isset($payload['meta'])) $payload['meta']['count'] = count($payload['data']);
            $response->setData($payload);
        }

        if ($method === 'GET' && $path === 'api/departments' && isset($payload['data']) && is_array($payload['data']) && ($access['scope'] ?? 'none') !== 'all') {
            $allowedDepartments = $access['department_ids'] ?? [];
            $payload['data'] = array_values(array_map(function ($department) use ($allowedDepartments) {
                if (isset($department['parent_id']) && $department['parent_id'] !== null && !in_array((int) $department['parent_id'], $allowedDepartments, true)) {
                    $department['parent_id'] = null;
                    $department['parent_name'] = null;
                }
                return $department;
            }, array_filter($payload['data'], fn ($department) => isset($department['id']) && in_array((int) $department['id'], $allowedDepartments, true))));
            $response->setData($payload);
        }

        if ($method === 'GET' && preg_match('#^api/employees/\d+$#', $path) && isset($payload['data']) && is_array($payload['data']) && !($access['permissions']['employees.salary.read'] ?? false)) {
            $payload['data']['salary_history'] = [];
            $payload['meta']['salary_visible'] = false;
            $response->setData($payload);
        }

        return $response;
    }
}
