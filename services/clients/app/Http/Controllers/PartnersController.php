<?php

namespace App\Http\Controllers;

use App\Support\ClientContourAccess;
use Illuminate\Http\Request;
use App\Services\PartnersService;
use Illuminate\Validation\Rule;

class PartnersController extends Controller
{
    private function partners(): PartnersService
    {
        return app(PartnersService::class);
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        $resolver = app(ClientContourAccess::class);
        $access = ($request->attributes->get('client_contour_access') ?? $resolver->resolve($request));
        abort_unless($access['platform_admin'] || ($resolver->allows($access, $permission)
            && $resolver->scope($access, $permission) === 'all'), 403, 'Недостаточно прав на справочник партнёров.');
    }

    public function catalog(Request $request)
    {
        $resolver=app(ClientContourAccess::class);
        $access=($request->attributes->get('client_contour_access') ?? $resolver->resolve($request));
        abort_unless(($access['platform_admin']??false)
            || $resolver->allows($access,'clients.view')
            || $resolver->allows($access,'clients.manage')
            || $resolver->allows($access,'members.view') || $resolver->allows($access,'members.manage'),403);
        $rows = $this->partners()->catalog();
        return response()->json(['data'=>$rows]);
    }

    public function index(Request $request)
    {
        $this->authorizeAction($request, 'clients.view');
        return response()->json(['data' => $this->partners()->registry()]);
    }

    public function storePartner(Request $request)
    {
        $this->authorizeAction($request, 'clients.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $data['name'] = trim($data['name']);
        abort_if($data['name'] === '', 422, 'Название партнёра обязательно.');
        return response()->json(['data' => $this->partners()->createPartner($data)], 201);
    }

    public function updatePartner(Request $request, int $partner)
    {
        $this->authorizeAction($request, 'clients.manage');
        abort_unless($this->partners()->partnerExists($partner), 404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
            abort_if($data['name'] === '', 422, 'Название партнёра обязательно.');
        }
        return response()->json(['data' => $this->partners()->updatePartner($partner, $data)]);
    }

    public function storeSpecialist(Request $request)
    {
        $this->authorizeAction($request, 'clients.manage');
        $data = $request->validate([
            'partner_id' => ['required', 'integer', Rule::exists('partners', 'id')],
            'full_name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['full_name'] = trim($data['full_name']);
        abort_if($data['full_name'] === '', 422, 'ФИО специалиста обязательно.');
        return response()->json(['data' => $this->partners()->createSpecialist($data)], 201);
    }

    public function updateSpecialist(Request $request, int $specialist)
    {
        $this->authorizeAction($request, 'clients.manage');
        abort_unless($this->partners()->specialistExists($specialist), 404);
        $data = $request->validate([
            'partner_id' => ['sometimes', 'required', 'integer', Rule::exists('partners', 'id')],
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if (array_key_exists('full_name', $data)) {
            $data['full_name'] = trim($data['full_name']);
            abort_if($data['full_name'] === '', 422, 'ФИО специалиста обязательно.');
        }
        return response()->json(['data' => $this->partners()->updateSpecialist($specialist, $data)]);
    }
}
