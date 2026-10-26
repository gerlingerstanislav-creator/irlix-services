<?php

namespace App\Http\Controllers;

use App\Services\ContactPeopleService;
use Illuminate\Http\Request;

final class ContactPeopleController extends Controller
{
    private function contacts(): ContactPeopleService
    {
        return app(ContactPeopleService::class);
    }

    public function show(int $contact)
    {
        return response()->json(['data' => $this->contacts()->show($contact)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'methods' => ['sometimes', 'array'],
            'methods.*.type' => ['required_with:methods', 'string', 'max:100'],
            'methods.*.contact' => ['required_with:methods', 'string', 'max:500'],
            'methods.*.is_active' => ['sometimes', 'boolean'],
            'methods.*.is_preferred' => ['sometimes', 'boolean'],
            'client_relations' => ['sometimes', 'array'],
            'client_relations.*.client_id' => ['required_with:client_relations', 'integer', 'min:1'],
            'client_relations.*.position' => ['nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);
        return response()->json(['data' => $this->contacts()->create($data)], 201);
    }

    public function update(Request $request, int $contact)
    {
        abort_unless($this->contacts()->exists($contact), 404, 'Contact not found');
        $data = $request->validate(['full_name' => ['required', 'string', 'max:255']]);
        return response()->json(['data' => $this->contacts()->update($contact, $data)]);
    }

    public function storeMethod(Request $request, int $contact)
    {
        abort_unless($this->contacts()->exists($contact), 404, 'Contact not found');
        $data = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_preferred' => ['sometimes', 'boolean'],
        ]);
        return response()->json(['data' => $this->contacts()->createMethod($contact, $data)], 201);
    }

    public function updateMethod(Request $request, int $contact, int $method)
    {
        abort_unless($this->contacts()->hasMethod($contact, $method), 404, 'Contact method not found');
        $data = $request->validate([
            'type' => ['sometimes', 'required', 'string', 'max:100'],
            'contact' => ['sometimes', 'required', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_preferred' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('type', $data)) $data['type'] = trim($data['type']);
        if (array_key_exists('contact', $data)) $data['contact'] = trim($data['contact']);
        return response()->json(['data' => $this->contacts()->updateMethod($contact, $method, $data)]);
    }

    public function destroyMethod(int $contact, int $method)
    {
        $this->contacts()->deleteMethod($contact, $method);
        return response()->json(['data' => ['deleted' => true, 'id' => $method]]);
    }

    public function storeClientRelation(Request $request, int $contact)
    {
        abort_unless($this->contacts()->exists($contact), 404, 'Contact not found');
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);
        abort_unless($this->contacts()->hasClient((int) $data['client_id']), 404, 'Client not found');
        return response()->json(['data' => $this->contacts()->createClientRelation(
            $contact, (int) $data['client_id'], $data['position'] ?? null
        )], 201);
    }

    public function updateClientRelation(Request $request, int $contact, int $relation)
    {
        abort_unless($this->contacts()->hasRelation($contact, $relation), 404, 'Contact relation not found');
        $data = $request->validate([
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('position', $data)) {
            $value = trim((string) ($data['position'] ?? ''));
            $data['relation_role'] = $value === '' ? null : $value;
            unset($data['position']);
        }
        return response()->json(['data' => $this->contacts()->updateClientRelation($relation, $data)]);
    }

    public function destroyClientRelation(int $contact, int $relation)
    {
        $this->contacts()->deleteClientRelation($contact, $relation);
        return response()->json(['data' => ['deleted' => true, 'id' => $relation]]);
    }
}
