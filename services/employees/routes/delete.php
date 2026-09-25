<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::delete('/employees/{employee}', function (Request $request, int $employee) {
    $row = DB::table('employees')->where('id', $employee)->first();
    if (!$row) return response()->json(['message' => 'Employee not found'], 404);

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
