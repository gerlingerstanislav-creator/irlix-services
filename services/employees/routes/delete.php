<?php

use App\Support\SpecialRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::delete('/employees/{employee}', function (Request $request, int $employee) {
    $access = (array) $request->attributes->get('employees_access', []);
    if (!SpecialRoles::isPlatformPrivileged((array) ($access['roles'] ?? []))) {
        return response()->json(['message' => 'Platform administrator role required'], 403);
    }

    $row = DB::table('employees')->where('id', $employee)->first();
    if (!$row) return response()->json(['message' => 'Employee not found'], 404);
    if ($row->login === 'admin') return response()->json(['message' => 'Системный аккаунт IRLIX нельзя удалить.'], 409);

    $purgeToken = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
    if ($purgeToken === '') {
        return response()->json(['message' => 'Cross-service purge is not configured'], 503);
    }

    $payload = [
        'employee_id' => $employee,
        'keycloak_subject' => $row->keycloak_user_id ?: null,
        'username' => $row->login ?: null,
        'employee_name' => $row->full_name ?: null,
    ];
    $targets = [
        'vacations' => rtrim((string) env('VACATIONS_INTERNAL_URL', 'http://vacations:8000'), '/'),
        'clients' => rtrim((string) env('CLIENTS_INTERNAL_URL', 'http://clients:8000'), '/'),
        'timesheets' => rtrim((string) env('TIMESHEETS_INTERNAL_URL', 'http://timesheets:8000'), '/'),
        'specialists' => rtrim((string) env('SPECIALISTS_INTERNAL_URL', 'http://specialists:8000'), '/'),
        'recruitment' => rtrim((string) env('RECRUITMENT_INTERNAL_URL', 'http://recruitment:8000'), '/'),
    ];

    foreach ($targets as $service => $base) {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-Irlix-Internal-Purge-Token' => $purgeToken])
                ->retry(2, 200, throw: false)
                ->timeout(15)
                ->post("{$base}/internal/system/employees/{$employee}/purge", $payload);
        } catch (\Throwable $error) {
            return response()->json([
                'message' => 'Cross-service employee purge failed',
                'service' => $service,
                'reason' => $error->getMessage(),
            ], 502);
        }
        if (!$response->successful()) {
            return response()->json([
                'message' => 'Cross-service employee purge failed',
                'service' => $service,
                'status' => $response->status(),
            ], 502);
        }
    }

    if ($row->keycloak_user_id) {
        $base = rtrim((string) env('KEYCLOAK_URL'), '/');
        $realm = (string) env('KEYCLOAK_REALM');
        $token = Http::asForm()->timeout(8)->post("{$base}/realms/master/protocol/openid-connect/token", [
            'client_id' => 'admin-cli',
            'username' => (string) env('KEYCLOAK_ADMIN_USERNAME'),
            'password' => (string) env('KEYCLOAK_ADMIN_PASSWORD'),
            'grant_type' => 'password',
        ]);
        if (!$token->successful() || !$token->json('access_token')) return response()->json(['message' => 'Keycloak unavailable'], 502);
        $deleted = Http::withToken((string) $token->json('access_token'))->timeout(8)->delete("{$base}/admin/realms/{$realm}/users/{$row->keycloak_user_id}");
        if (!in_array($deleted->status(), [204, 404], true)) return response()->json(['message' => 'Failed to delete Keycloak identity'], 502);
    }

    DB::table('employees')->where('id', $employee)->delete();
    return response()->noContent();
});
