<?php

namespace App\Reports;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class ReporterIdentity
{
    public function resolve(Request $request): array|JsonResponse
    {
        if (trim((string) $request->header('X-Irlix-Role-Preview')) !== '') {
            return response()->json(['message' => 'Отправка недоступна в режиме просмотра роли'], 403);
        }
        $bearer = $request->bearerToken();
        if (!$bearer) return response()->json(['message' => 'Требуется авторизация'], 401);
        try {
            $response = Http::timeout(6)->acceptJson()->withToken($bearer)
                ->get(rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/').'/access/me');
        } catch (\Throwable) {
            return response()->json(['message' => 'Проверка сотрудника временно недоступна'], 503);
        }
        if ($response->status() === 401) return response()->json(['message' => 'Требуется авторизация'], 401);
        if ($response->status() === 403) return response()->json(['message' => 'Доступ запрещён'], 403);
        if (!$response->successful()) return response()->json(['message' => 'Проверка сотрудника временно недоступна'], 503);
        $data = $response->json('data');
        if (!is_array($data) || empty($data['employee_id']) || !is_numeric($data['employee_id'])) {
            return response()->json(['message' => 'Сотрудник не определён'], 403);
        }
        // The trusted Employees API owns employee identity. Browser-supplied identity is never accepted.
        return ['employee_id' => (int) $data['employee_id'], 'employee_name' => (string) ($data['employee_name'] ?? 'Сотрудник #'.$data['employee_id']), 'subject' => (string) ($data['keycloak_user_id'] ?? $data['sub'] ?? $data['employee_id'])];
    }
}
