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

    public function selfApprovalContext(Request $request): array
    {
        return $this->get($request, '/self/absence-approval-context');
    }

    public function employeeApprovalContext(Request $request, int $employeeId): array
    {
        return $this->get($request, "/absence-approval-context/{$employeeId}");
    }

    private function get(Request $request, string $path): array
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

        $data = $response->json('data');
        if (!is_array($data)) throw new RuntimeException('Employees lookup returned invalid data');
        return $data;
    }
}
