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
        abort_unless($actor > 0 && in_array($actor,
            array_map('intval', $access['client_service_employee_ids'] ?? []), true),
            403, 'Только сотрудники клиентской службы могут просматривать специалистов.');
    }

    public function clients(Request $request, ClientSpecialistsService $service)
    {
        $this->verifyMembership($request);
        return response()->json(['data' => $service->clients()]);
    }

    public function members(Request $request, ClientSpecialistsService $service)
    {
        $this->verifyMembership($request);
        $data = $request->validate(['client_id' => ['required', 'integer', 'min:1']]);
        return response()->json(['data' => $service->members((int) $data['client_id'])]);
    }
}
