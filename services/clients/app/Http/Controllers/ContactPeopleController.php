<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactPeopleController extends Controller
{
    public function show(int $contact)
    {
        $person = DB::table('contact_people')->find($contact);
        abort_unless($person, 404, 'Contact not found');

        return response()->json(['data' => $this->serialize($person)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'methods' => ['sometimes', 'array'],
            'methods.*.type' => ['required_with:methods', 'string', 'max:100'],
            'methods.*.contact' => ['required_with:methods', 'string', 'max:500'],
            'methods.*.is_active' => ['sometimes', 'boolean'],
            'client_relations' => ['sometimes', 'array'],
            'client_relations.*.client_id' => ['required_with:client_relations', 'integer', 'min:1'],
            'client_relations.*.position' => ['nullable', 'string', 'max:255'],
            // Backward compatibility for the first MVP contract.
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $id = DB::transaction(function () use ($data) {
            $id = DB::table('contact_people')->insertGetId([
                'full_name' => trim($data['full_name']),
                'position' => $data['position'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods = $data['methods'] ?? [];
            if (!empty($data['phone'])) {
                $methods[] = ['type' => 'Телефон', 'contact' => $data['phone'], 'is_active' => true];
            }
            if (!empty($data['email'])) {
                $methods[] = ['type' => 'Email', 'contact' => $data['email'], 'is_active' => true];
            }
            foreach ($methods as $method) {
                $this->insertMethod($id, $method);
            }

            foreach ($data['client_relations'] ?? [] as $relation) {
                $this->upsertClientRelation($id, (int) $relation['client_id'], $relation['position'] ?? null);
            }

            return $id;
        });

        return response()->json(['data' => $this->serialize(DB::table('contact_people')->find($id))], 201);
    }

    public function update(Request $request, int $contact)
    {
        abort_unless(DB::table('contact_people')->where('id', $contact)->exists(), 404, 'Contact not found');
        $data = $request->validate(['full_name' => ['required', 'string', 'max:255']]);
        DB::table('contact_people')->where('id', $contact)->update([
            'full_name' => trim($data['full_name']),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->serialize(DB::table('contact_people')->find($contact))]);
    }

    public function storeMethod(Request $request, int $contact)
    {
        abort_unless(DB::table('contact_people')->where('id', $contact)->exists(), 404, 'Contact not found');
        $data = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $id = $this->insertMethod($contact, $data);
        return response()->json(['data' => DB::table('contact_methods')->find($id)], 201);
    }

    public function updateMethod(Request $request, int $contact, int $method)
    {
        abort_unless(DB::table('contact_methods')->where('id', $method)->where('contact_person_id', $contact)->exists(), 404, 'Contact method not found');
        $data = $request->validate([
            'type' => ['sometimes', 'required', 'string', 'max:100'],
            'contact' => ['sometimes', 'required', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('type', $data)) $data['type'] = trim($data['type']);
        if (array_key_exists('contact', $data)) $data['contact'] = trim($data['contact']);
        DB::table('contact_methods')->where('id', $method)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('contact_methods')->find($method)]);
    }

    public function storeClientRelation(Request $request, int $contact)
    {
        abort_unless(DB::table('contact_people')->where('id', $contact)->exists(), 404, 'Contact not found');
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);
        abort_unless(DB::table('clients')->where('id', $data['client_id'])->exists(), 404, 'Client not found');
        $id = $this->upsertClientRelation($contact, (int) $data['client_id'], $data['position'] ?? null);
        return response()->json(['data' => DB::table('contact_relations')->find($id)], 201);
    }

    public function updateClientRelation(Request $request, int $contact, int $relation)
    {
        abort_unless(DB::table('contact_relations')->where('id', $relation)->where('contact_person_id', $contact)->where('entity_type', 'client')->exists(), 404, 'Contact relation not found');
        $data = $request->validate([
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('position', $data)) {
            $value = trim((string) ($data['position'] ?? ''));
            $data['relation_role'] = $value === '' ? null : $value;
            unset($data['position']);
        }
        DB::table('contact_relations')->where('id', $relation)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('contact_relations')->find($relation)]);
    }

    private function insertMethod(int $contactId, array $method): int
    {
        $type = trim((string) ($method['type'] ?? ''));
        $contact = trim((string) ($method['contact'] ?? ''));
        abort_if($type === '' || $contact === '', 422, 'Type and contact are required');
        return DB::table('contact_methods')->insertGetId([
            'contact_person_id' => $contactId,
            'type' => $type,
            'contact' => $contact,
            'is_active' => $method['is_active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function upsertClientRelation(int $contactId, int $clientId, ?string $position): int
    {
        abort_unless(DB::table('clients')->where('id', $clientId)->exists(), 404, 'Client not found');
        $role = trim((string) ($position ?? '')) ?: null;
        $existing = DB::table('contact_relations')
            ->where('contact_person_id', $contactId)
            ->where('entity_type', 'client')
            ->where('entity_id', $clientId)
            ->first();
        if ($existing) {
            DB::table('contact_relations')->where('id', $existing->id)->update([
                'relation_role' => $role,
                'active' => true,
                'updated_at' => now(),
            ]);
            return (int) $existing->id;
        }
        return DB::table('contact_relations')->insertGetId([
            'contact_person_id' => $contactId,
            'entity_type' => 'client',
            'entity_id' => $clientId,
            'relation_role' => $role,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function serialize(object $person): array
    {
        $methods = DB::table('contact_methods')
            ->where('contact_person_id', $person->id)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();
        $relations = DB::table('contact_relations as relation')
            ->join('clients as client', 'client.id', '=', 'relation.entity_id')
            ->where('relation.contact_person_id', $person->id)
            ->where('relation.entity_type', 'client')
            ->orderByDesc('relation.active')
            ->orderBy('client.name')
            ->select([
                'relation.id',
                'relation.entity_id as client_id',
                'client.name as client_name',
                'relation.relation_role as position',
                'relation.active',
            ])->get();

        return [
            'id' => $person->id,
            'full_name' => $person->full_name,
            'methods' => $methods,
            'client_relations' => $relations,
            'created_at' => $person->created_at,
            'updated_at' => $person->updated_at,
        ];
    }
}
