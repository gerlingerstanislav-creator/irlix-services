<?php

namespace App\Http\Controllers;

use App\Support\ClientContourAccess;
use Illuminate\Http\Request;
use App\Services\PartnersService;
use App\Services\PartnerProfileService;
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
            'location' => ['nullable', 'string', 'max:255'],
            'vat_payer' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $data['name'] = trim($data['name']);
        abort_if($data['name'] === '', 422, 'Название партнёра обязательно.');
        return response()->json(['data' => $this->partners()->createPartner($data, $this->actor($request))], 201);
    }

    public function updatePartner(Request $request, int $partner)
    {
        $this->authorizeAction($request, 'clients.manage');
        abort_unless($this->partners()->partnerExists($partner), 404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vat_payer' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
            abort_if($data['name'] === '', 422, 'Название партнёра обязательно.');
        }
        return response()->json(['data' => $this->partners()->updatePartner($partner, $data, $this->actor($request))]);
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

    private function profile(): PartnerProfileService
    {
        return app(PartnerProfileService::class);
    }

    private function actor(Request $request): ?string
    {
        return $request->attributes->get('identity', [])['preferred_username'] ?? null;
    }

    public function showProfile(Request $request, int $partner)
    {
        $this->authorizeAction($request, 'clients.view');
        $resolver = app(ClientContourAccess::class);
        $access = $request->attributes->get('client_contour_access') ?? $resolver->resolve($request);
        $withContacts = ($access['platform_admin'] ?? false)
            || ($resolver->allows($access, 'contacts.view') && $resolver->scope($access, 'contacts.view') === 'all');
        return response()->json(['data' => $this->profile()->profile($partner, $withContacts)]);
    }

    public function contactOptions(Request $request)
    {
        $this->authorizeAction($request, 'clients.view');
        $this->authorizeAction($request, 'contacts.view');
        $query = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($query) > 120, 422);
        return response()->json(['data' => $this->profile()->contactOptions($query)]);
    }

    public function linkContact(Request $request, int $partner)
    {
        $this->authorizeAction($request, 'clients.manage');
        $this->authorizeAction($request, 'contacts.manage');
        abort_unless($this->profile()->exists($partner), 404);
        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', 'min:1'],
            'full_name' => ['required_without:contact_id', 'nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);
        $this->profile()->linkContact($partner, $data, $this->actor($request));
        return response()->json(['data' => $this->profile()->profile($partner, true)], 201);
    }

    public function updateContactRelation(Request $request, int $partner, int $relation)
    {
        $this->authorizeAction($request, 'clients.manage');
        $this->authorizeAction($request, 'contacts.manage');
        abort_unless($this->profile()->exists($partner), 404);
        $data = $request->validate([
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $this->profile()->updateContactRelation($partner, $relation, $data, $this->actor($request));
        return response()->json(['data' => $this->profile()->profile($partner, true)]);
    }

    public function addNote(Request $request, int $partner)
    {
        $this->authorizeAction($request, 'clients.manage');
        abort_unless($this->profile()->exists($partner), 404);
        $data = $request->validate(['text' => ['required', 'string', 'max:20000']]);
        $text = trim($data['text']);
        abort_if($text === '', 422, 'Введите текст комментария.');
        $this->profile()->comment($partner, $text, $this->actor($request));
        return response()->json(['data' => $this->profile()->profile($partner)], 201);
    }

    private function legalRules(bool $partial): array
    {
        $presence = $partial ? 'sometimes' : 'required';
        return [
            'name' => [$presence, 'required', 'string', 'max:255'],
            'inn' => [$presence, 'required', 'string', 'max:32'],
            'full_name' => [$presence, 'required', 'string', 'max:500'],
            'ogrn' => ['sometimes', 'nullable', 'string', 'max:32'],
            'kpp' => ['sometimes', 'nullable', 'string', 'max:32'],
            'registration_date' => ['sometimes', 'nullable', 'date'],
            'okpo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'oktmo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function storeLegalEntity(Request $request, int $partner)
    {
        $this->authorizeAction($request, 'clients.manage');
        abort_unless($this->profile()->exists($partner), 404);
        $this->profile()->addLegalEntity($partner, $request->validate($this->legalRules(false)), $this->actor($request));
        return response()->json(['data' => $this->profile()->profile($partner)], 201);
    }

    public function updateLegalEntity(Request $request, int $partner, int $entity)
    {
        $this->authorizeAction($request, 'clients.manage');
        abort_unless($this->profile()->exists($partner), 404);
        $this->profile()->updateLegalEntity($partner, $entity, $request->validate($this->legalRules(true)), $this->actor($request));
        return response()->json(['data' => $this->profile()->profile($partner)]);
    }

}
