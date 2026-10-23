<?php

namespace App\Http\Controllers;

use App\Support\ClientContourAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnersController extends Controller
{
    private function authorizeDirectory(Request $request, ClientContourAccess $access, bool $write = false): void
    {
        $permission = $write ? 'partners.manage' : 'partners.view';
        $context = $access->resolve($request);
        abort_unless($context['platform_admin'] ||
            (($context['permissions'][$permission]['allowed'] ?? false)
             && ($context['permissions'][$permission]['scope'] ?? 'none') === 'all'),
            403, 'Недостаточно прав на справочник партнёров.');
    }

    public function index(Request $request, ClientContourAccess $access)
    {
        $this->authorizeDirectory($request, $access);
        $partners = DB::table('partners')->orderBy('name')->orderBy('id')->get();
        $specialists = DB::table('partner_specialists')
            ->orderBy('full_name')->orderBy('id')->get();
        return response()->json(['data' => [
            'partners' => $partners->map(fn ($partner) => [...(array) $partner,
                'specialists' => $specialists->filter(fn ($s) => (int) ($s->partner_id ?? 0) === (int) $partner->id)->values()])->values(),
            'unassigned' => $specialists->filter(fn ($s) => $s->partner_id === null)->values(),
        ]]);
    }

    public function store(Request $request, ClientContourAccess $access)
    {
        $this->authorizeDirectory($request, $access, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'comment' => ['nullable', 'string', 'max:10000'],
        ]);
        $data['name'] = trim($data['name']);
        abort_if($data['name'] === '', 422, 'Укажите название партнёра.');
        $id = DB::table('partners')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('partners')->find($id)], 201);
    }

    public function show(Request $request, ClientContourAccess $access, int $partner)
    {
        $this->authorizeDirectory($request, $access);
        $row = DB::table('partners')->find($partner);
        abort_unless($row, 404);
        return response()->json(['data' => [...(array) $row,
            'specialists' => DB::table('partner_specialists')->where('partner_id',$partner)->orderBy('full_name')->get()]]);
    }

    public function update(Request $request, ClientContourAccess $access, int $partner)
    {
        $this->authorizeDirectory($request, $access, true);
        abort_unless(DB::table('partners')->where('id', $partner)->exists(), 404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
            abort_if($data['name'] === '', 422, 'Укажите название партнёра.');
        }
        DB::table('partners')->where('id', $partner)->update([...$data, 'updated_at'=>now()]);
        return $this->show($request, $access, $partner);
    }

    public function storeSpecialist(Request $request, ClientContourAccess $access, int $partner)
    {
        $this->authorizeDirectory($request, $access, true);
        abort_unless(DB::table('partners')->where('id', $partner)->exists(), 404);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:10000'],
        ]);
        $data['full_name'] = trim($data['full_name']);
        abort_if($data['full_name'] === '', 422, 'Укажите ФИО специалиста.');
        $id = DB::table('partner_specialists')->insertGetId([
            ...$data, 'partner_id' => $partner, 'created_at' => now(), 'updated_at' => now()
        ]);
        return response()->json(['data' => DB::table('partner_specialists')->find($id)], 201);
    }

    public function showSpecialist(Request $request, ClientContourAccess $access, int $specialist)
    {
        $this->authorizeDirectory($request, $access);
        $row = DB::table('partner_specialists')->find($specialist);
        abort_unless($row, 404);
        return response()->json(['data' => $row]);
    }

    public function updateSpecialist(Request $request, ClientContourAccess $access, int $specialist)
    {
        $this->authorizeDirectory($request, $access, true);
        abort_unless(DB::table('partner_specialists')->where('id', $specialist)->exists(), 404);
        $data = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'partner_id' => ['sometimes', 'required', 'integer', Rule::exists('partners','id')],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
        if (array_key_exists('full_name', $data)) {
            $data['full_name'] = trim($data['full_name']);
            abort_if($data['full_name'] === '', 422, 'Укажите ФИО специалиста.');
        }
        DB::table('partner_specialists')->where('id', $specialist)->update([...$data, 'updated_at' => now()]);
        // Historic project member display name is a snapshot and must not be
        // silently rewritten when an identity is corrected.
        return $this->showSpecialist($request, $access, $specialist);
    }
}
