<?php

namespace App\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class PlatformAdminAuthorizer
{
    public static function allowed(mixed $access): bool
    {
        return is_array($access) && is_array($access['roles'] ?? null)
            && count(array_intersect(['platform-admin', 'system-admin'], $access['roles'])) > 0;
    }

    public function authorize(Request $request): ?JsonResponse
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (!preg_match('/^Bearer\s+\S+$/i', $authorization)) {
            return response()->json(['message' => 'Требуется авторизация'], 401);
        }
        try {
            // Employees validates JWT and resolves current effective roles. Never trust browser/JWT role hints.
            $response = Http::timeout(6)->acceptJson()->withHeaders(['Authorization' => $authorization])
                ->get(rtrim(env('EMPLOYEES_URL', 'http://employees:8000/api'), '/').'/access/me');
        } catch (\Throwable $error) {
            return response()->json(['message' => 'Проверка доступа временно недоступна'], 503);
        }
        if ($response->status() === 401) return response()->json(['message' => 'Требуется авторизация'], 401);
        if ($response->status() === 403) return response()->json(['message' => 'Доступ запрещён'], 403);
        if (!$response->successful()) return response()->json(['message' => 'Проверка доступа временно недоступна'], 503);
        if (!self::allowed($response->json('data'))) {
            return response()->json(['message' => 'Доступно администратору платформы и системному администратору'], 403);
        }
        return null;
    }
}
