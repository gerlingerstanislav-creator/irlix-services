<?php

namespace App\Http\Controllers;

use App\Services\ClientSpecialistsService;
use App\Support\ClientContourAccess;
use Illuminate\Http\Request;

final class ClientSpecialistsController extends Controller
{
    private function verifyMembership(Request $request): void
    {
        $access = (array) ($request->attributes->get('client_contour_access')
            ?? app(ClientContourAccess::class)->resolve($request));
        $actor = (int) ($access['employee']['id'] ?? 0);
        abort_unless($actor > 0 && (($access['platform_admin'] ?? false)
            || in_array($actor, array_map('intval', $access['client_service_employee_ids'] ?? []), true)),
            403, 'Календарь доступен только клиентской службе и администраторам платформы.');
    }

    public function clients(Request $request, ClientSpecialistsService $service)
    {
        $this->verifyMembership($request);
        return response()->json(['data' => $service->clients()]);
    }

    public function members(Request $request, ClientSpecialistsService $service)
    {
        $this->verifyMembership($request);
        $data = $request->validate([
            'client_id' => ['required_without:client_ids', 'integer', 'min:1'],
            'client_ids' => ['required_without:client_id', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $ids = isset($data['client_ids']) ? array_map('intval', $data['client_ids']) : [(int) $data['client_id']];
        return response()->json(['data' => $service->membersForClients($ids)]);
    }
}
