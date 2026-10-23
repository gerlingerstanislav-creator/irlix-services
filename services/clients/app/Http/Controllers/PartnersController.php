<?php

namespace App\Http\Controllers;

use App\Support\ClientContourAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnersController extends Controller
{
    private function authorizeAction(Request $request, string $permission): void
    {
        $resolver = app(ClientContourAccess::class);
        $access = $resolver->resolve($request);
        abort_unless($access['platform_admin'] || ($resolver->allows($access, $permission)
            && $resolver->scope($access, $permission) === 'all'), 403, 'Недостаточно прав на справочник партнёров.');
    }

    public function index(Request $request)
    {
        $this->authorizeAction($request, 'clients.view');
        $partners = DB::table('partners')->orderBy('name')->get();
        $specialists = DB::table('partner_specialists')->orderBy('full_name')->get();
        $groups = $partners->map(fn ($partner) => [...(array) $partner,
            'specialists' => $specialists->where('partner_id', $partner->id)->values()->all()]);
        return response()->json(['data' => ['partners' => $groups,
            'unassigned' => $specialists->whereNull('partner_id')->values()->all()]]);
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
        $id = DB::table('partners')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('partners')->find($id)], 201);
    }

    public function updatePartner(Request $request, int $partner)
    {
        $this->authorizeAction($request, 'clients.manage');
        abort_unless(DB::table('partners')->where('id', $partner)->exists(), 404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
            abort_if($data['name'] === '', 422, 'Название партнёра обязательно.');
        }
        DB::table('partners')->where('id', $partner)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('partners')->find($partner)]);
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
        $id = DB::table('partner_specialists')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('partner_specialists')->find($id)], 201);
    }

    public function updateSpecialist(Request $request, int $specialist)
    {
        $this->authorizeAction($request, 'clients.manage');
        abort_unless(DB::table('partner_specialists')->where('id', $specialist)->exists(), 404);
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
        DB::table('partner_specialists')->where('id', $specialist)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('partner_specialists')->find($specialist)]);
    }
}
