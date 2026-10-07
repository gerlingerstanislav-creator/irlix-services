<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class EmployeesDirectory
{
    public function access(Request $request): array
    {
        return $this->get($request, '/access/me');
    }

    public function vacationsAccess(Request $request, int $employeeId): array
    {
        $access = $this->access($request);
        $roles = array_map(
            fn ($role) => str_replace('_', '-', mb_strtolower(trim((string) $role))),
            $access['roles'] ?? []
        );
        if (in_array('personnel-officer', $roles, true)) return $access;

        $context = $this->selfApprovalContext($request);
        foreach ($context['personnel_officers'] ?? [] as $person) {
            if ((int) ($person['employee_id'] ?? 0) !== $employeeId) continue;
            $access['roles'] = array_values(array_unique(array_merge($access['roles'] ?? [], ['personnel-officer'])));
            break;
        }

        return $access;
    }

    public function selfApprovalContext(Request $request): array
    {
        return $this->get($request, '/self/absence-approval-context');
    }

    public function employeeApprovalContext(Request $request, int $employeeId): array
    {
        return $this->get($request, "/absence-approval-context/{$employeeId}");
    }

    public function vacationsDirectory(Request $request): array
    {
        return $this->get($request, '/vacations-directory');
    }

    public function employees(Request $request): array
    {
        return $this->getList($request, '/employees');
    }

    public function departments(Request $request): array
    {
        return $this->getList($request, '/departments');
    }

    private function get(Request $request, string $path): array
    {
        $payload = $this->request($request, $path);
        $data = $payload['data'] ?? null;
        if (!is_array($data)) throw new RuntimeException('Employees lookup returned invalid data');
        return $data;
    }

    private function getList(Request $request, string $path): array
    {
        $payload = $this->request($request, $path);
        $data = $payload['data'] ?? null;
        if (!is_array($data)) throw new RuntimeException('Employees directory returned invalid data');
        return array_values($data);
    }

    private function request(Request $request, string $path): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) throw new RuntimeException('Authentication token missing');

        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(5)->get($base.$path);
        } catch (Throwable) {
            throw new RuntimeException('Employees service unavailable');
        }

        if ($response->status() === 403) throw new DomainException('Недостаточно прав для этого действия');
        if ($response->status() === 404) throw new DomainException('Данные сотрудника не найдены');
        if (!$response->successful()) throw new RuntimeException('Employees lookup failed: '.$response->status());

        $payload = $response->json();
        if (!is_array($payload)) throw new RuntimeException('Employees lookup returned invalid data');
        return $payload;
    }
}
