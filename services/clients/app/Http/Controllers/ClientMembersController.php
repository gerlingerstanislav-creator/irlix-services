<?php

namespace App\Http\Controllers;

use App\Services\ClientMembershipService;
use App\Support\ClientContourAccess;
use App\Support\MemberIdentity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ClientMembersController extends Controller
{
    private function members(): ClientMembershipService
    {
        return app(ClientMembershipService::class);
    }

    private function allow(Request $request, string $permission, ?int $clientId = null): void
    {
        if ($request->attributes->get('clients_scope_authorized') === true) return;
        $permission = str_replace('clients.', 'members.', $permission);
        $resolver = app(ClientContourAccess::class);
        $access = $request->attributes->get('client_contour_access') ?? $resolver->resolve($request);
        if ($access['platform_admin'] ?? false) return;
        abort_unless($resolver->allows($access, $permission), 403, 'Недостаточно прав.');
        $scope = $resolver->scope($access, $permission);
        if ($scope === 'all') return;
        if ($clientId !== null && $scope === 'own') {
            $actor = (int) ($access['employee']['id'] ?? 0);
            $client = $this->members()->client($clientId);
            abort_unless($client && ($actor === (int) $client->account_employee_id
                || $actor === (int) $client->sales_employee_id),
                403, 'Клиент вне области доступа.');
            return;
        }
        abort(403, 'Доступ к подключению вне области доступа.');
    }

    public function index(Request $request, int $client)
    {
        $this->allow($request, 'clients.view', $client);
        abort_unless($this->members()->client($client), 404);
        return response()->json(['data' => $this->members()->listForClient($client)]);
    }

    public function show(Request $request, int $member)
    {
        $canonical = $this->members()->member($member);
        $this->allow($request, 'clients.view', (int) $canonical->client_id);
        return response()->json(['data' => $this->members()->payload($canonical)]);
    }

    public function store(Request $request, int $client)
    {
        $this->allow($request, 'clients.manage', $client);
        abort_unless($this->members()->client($client), 404);
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'partner_specialist_id' => ['nullable', 'integer', Rule::exists('partner_specialists', 'id')],
            'specialist_name' => ['nullable', 'string', 'max:255'],
            'source_attempt_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $id = $this->members()->create($client, $data);
        return response()->json(['data' => $this->members()->payload($this->members()->member($id))], 201);
    }

    public function addTerm(Request $request, int $member)
    {
        $canonical = $this->members()->member($member);
        $this->allow($request, 'clients.manage', (int) $canonical->client_id);
        $data = $request->validate([
            'technology' => ['required', 'string', 'max:100'],
            'level' => ['required', 'string', 'max:100'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        return response()->json(['data' => $this->members()->addTerm($canonical, $data)], 201);
    }

    public function addProjectAssignment(Request $request, int $member)
    {
        $canonical = $this->members()->member($member);
        $this->allow($request, 'clients.manage', (int) $canonical->client_id);
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        return response()->json(['data' => $this->members()->addProjectAssignment($canonical, $data)], 201);
    }

    public function addFeedback(Request $request, int $member)
    {
        $canonical = $this->members()->member($member);
        $this->allow($request, 'clients.manage', (int) $canonical->client_id);
        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'min:1'],
            'text' => ['required', 'string', 'max:10000'],
        ]);
        $username = $request->attributes->get('identity', [])['preferred_username'] ?? null;
        return response()->json(['data' => $this->members()->addFeedback($canonical, $data, $username)], 201);
    }

    public function updateTerm(Request $request, int $term)
    {
        $term = MemberIdentity::canonicalTermId($term);
        $row = $this->members()->term($term);
        abort_unless($row && $row->client_member_id, 404, 'Коммерческие условия не найдены.');
        $canonical = $this->members()->member((int) $row->client_member_id);
        $this->allow($request, 'clients.manage', (int) $canonical->client_id);
        $data = $request->validate([
            'technology' => ['sometimes', 'required', 'string', 'max:100'],
            'level' => ['sometimes', 'required', 'string', 'max:100'],
            'hourly_rate' => ['sometimes', 'required', 'numeric', 'min:0'],
            'hours_per_day' => ['sometimes', 'required', 'numeric', 'min:0', 'max:24'],
            'valid_from' => ['sometimes', 'required', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
        ]);
        return response()->json(['data' => $this->members()->updateTerm($canonical, $row, $term, $data)]);
    }

    public function updateProjectAssignment(Request $request, int $assignment)
    {
        $row = $this->members()->assignment($assignment);
        abort_unless($row, 404);
        $canonical = $this->members()->member((int) $row->client_member_id);
        $this->allow($request, 'clients.manage', (int) $canonical->client_id);
        $data = $request->validate([
            'project_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'valid_from' => ['sometimes', 'required', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
        ]);
        return response()->json(['data' => $this->members()->updateProjectAssignment(
            $canonical, $row, $assignment, $data
        )]);
    }
}
