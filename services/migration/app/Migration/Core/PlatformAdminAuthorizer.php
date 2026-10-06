<?php

namespace App\Migration\Core;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class PlatformAdminAuthorizer
{
    public function authorize(Request $request): array|JsonResponse
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if ($authorization === '') return response()->json(['message' => 'Authentication required'], 401);

        try {
            $response = Http::timeout(6)->acceptJson()->withHeaders(['Authorization' => $authorization])
                ->get(config('migration.employees_url').'/access/me');
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Employees access service is unavailable'], 503);
        }

        if ($response->status() === 401) return response()->json(['message' => 'Authentication required'], 401);
        if (! $response->successful()) return response()->json(['message' => 'Unable to resolve platform permissions'], 503);

        $access = (array) data_get($response->json(), 'data', []);
        $roles = array_values(array_unique(array_map('strval', $access['roles'] ?? [])));
        if (! in_array('platform-admin', $roles, true)) {
            return response()->json(['message' => 'Migration Service is available to platform-admin only'], 403);
        }
        if (! $request->isMethod('GET') && ! $request->is('api/migration/console/*')) {
            try {
                [$code, $console] = app(MigrationOperationsClient::class)->request('GET', '/console/state');
                if ($code !== 200) return response()->json(['message' => 'Очередь переноса недоступна.'], 503);
                if (in_array($console['operation']['status'] ?? '', ['queued', 'running'], true)) {
                    return response()->json(['message' => 'Дождитесь завершения операции в пульте переноса.'], 409);
                }
            } catch (\Throwable $e) { return response()->json(['message' => 'Очередь переноса недоступна.'], 503); }
        }
        return $access;
    }
}
