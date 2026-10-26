<?php

namespace App\Http\Controllers;

use App\Services\ClientAccountHandoffService;
use Illuminate\Http\Request;

final class ClientAccountHandoffsController extends Controller
{
    public function permanent(Request $request, int $client)
    {
        $data = $request->validate(['account_employee_id' => ['required', 'integer', 'min:1']]);
        $access = (array) $request->attributes->get('client_contour_access', []);
        $this->assertAccount($access, (int) $data['account_employee_id']);
        return response()->json(['data' => app(ClientAccountHandoffService::class)->permanent(
            $client, (int) $data['account_employee_id'], $access, $this->username($request)
        )]);
    }

    public function delegate(Request $request, int $client)
    {
        $data = $request->validate([
            'to_employee_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        ]);
        $access = (array) $request->attributes->get('client_contour_access', []);
        $this->assertAccount($access, (int) $data['to_employee_id']);
        return response()->json(['data' => app(ClientAccountHandoffService::class)->delegate(
            $client, $data, $access, $this->username($request)
        )], 201);
    }

    public function revoke(Request $request, int $client, int $delegation)
    {
        $access = (array) $request->attributes->get('client_contour_access', []);
        return response()->json(['data' => app(ClientAccountHandoffService::class)->revoke(
            $client, $delegation, $access, $this->username($request)
        )]);
    }

    private function assertAccount(array $access, int $id): void
    {
        abort_unless(($access['platform_admin'] ?? false) ||
            in_array($id, array_map('intval', $access['account_employee_ids'] ?? []), true),
            422, 'Выберите аккаунт-менеджера.');
    }

    private function username(Request $request): ?string
    {
        return $request->attributes->get('identity', [])['preferred_username'] ?? null;
    }
}
