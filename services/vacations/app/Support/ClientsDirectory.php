<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class ClientsDirectory
{
    public function absenceApprovers(Request $request, int $employeeId, string $from, string $to): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) throw new RuntimeException('Authentication token missing');

        $base = rtrim((string) env('CLIENTS_URL', 'http://clients:8000/api'), '/');
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(5)
                ->get($base.'/absence-approvers', [
                    'employee_id' => $employeeId,
                    'from' => $from,
                    'to' => $to,
                ]);
        } catch (Throwable) {
            throw new RuntimeException('Clients service unavailable');
        }

        if (!$response->successful()) throw new RuntimeException('Clients lookup failed: '.$response->status());
        $payload = $response->json();
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        if (!is_array($data)) throw new RuntimeException('Clients lookup returned invalid data');

        return [
            'account_manager_ids' => array_values(array_unique(array_filter(array_map('intval', $data['account_manager_ids'] ?? []), fn ($id) => $id > 0))),
            'connection_count' => max(0, (int) ($data['connection_count'] ?? 0)),
        ];
    }
}
