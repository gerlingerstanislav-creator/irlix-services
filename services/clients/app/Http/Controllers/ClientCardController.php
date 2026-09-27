<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientCardController extends Controller
{
    public function show(int $client)
    {
        $clientRow = DB::table('clients')->find($client);
        abort_unless($clientRow, 404, 'Client not found');

        $contacts = DB::table('contact_people')
            ->join('contact_relations', 'contact_relations.contact_person_id', '=', 'contact_people.id')
            ->where('contact_relations.entity_type', 'client')
            ->where('contact_relations.entity_id', $client)
            ->where('contact_relations.active', true)
            ->orderBy('contact_people.full_name')
            ->select([
                'contact_people.id',
                'contact_people.full_name',
                'contact_people.position',
                'contact_people.phone',
                'contact_people.email',
                'contact_relations.id as relation_id',
                'contact_relations.relation_role',
                'contact_relations.comment as relation_comment',
            ])
            ->get();

        return response()->json(['data' => [
            'client' => $this->serializeClient($clientRow),
            'contacts' => $contacts,
            'reporting_periods' => DB::table('reporting_periods')
                ->where('client_id', $client)
                ->orderByDesc('period_start')
                ->get(),
            'legal_entities' => DB::table('client_legal_entities')
                ->where('client_id', $client)
                ->orderBy('name')
                ->get(),
            'notes' => DB::table('client_notes')
                ->where('client_id', $client)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        ]]);
    }

    public function update(Request $request, int $client)
    {
        abort_unless(DB::table('clients')->where('id', $client)->exists(), 404, 'Client not found');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sector' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sales_employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'account_employee_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'act_approval_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'payment_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'technologies' => ['sometimes', 'array'],
            'technologies.*' => ['string', 'max:100'],
        ]);

        if (array_key_exists('technologies', $data)) {
            $data['technologies'] = json_encode(array_values(array_unique(array_filter(array_map('trim', $data['technologies'])))), JSON_UNESCAPED_UNICODE);
        }
        DB::table('clients')->where('id', $client)->update([...$data, 'updated_at' => now()]);

        return response()->json(['data' => $this->serializeClient(DB::table('clients')->find($client))]);
    }

    public function updateContact(Request $request, int $contact)
    {
        abort_unless(DB::table('contact_people')->where('id', $contact)->exists(), 404, 'Contact not found');
        $data = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);
        DB::table('contact_people')->where('id', $contact)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('contact_people')->find($contact)]);
    }

    public function storeLegalEntity(Request $request, int $client)
    {
        abort_unless(DB::table('clients')->where('id', $client)->exists(), 404, 'Client not found');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'max:32'],
        ]);
        $id = DB::table('client_legal_entities')->insertGetId([
            'client_id' => $client,
            'name' => trim($data['name']),
            'inn' => isset($data['inn']) ? trim((string) $data['inn']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['data' => DB::table('client_legal_entities')->find($id)], 201);
    }

    public function updateLegalEntity(Request $request, int $entity)
    {
        abort_unless(DB::table('client_legal_entities')->where('id', $entity)->exists(), 404, 'Legal entity not found');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'inn' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        DB::table('client_legal_entities')->where('id', $entity)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('client_legal_entities')->find($entity)]);
    }

    public function storeNote(Request $request, int $client)
    {
        abort_unless(DB::table('clients')->where('id', $client)->exists(), 404, 'Client not found');
        $data = $request->validate(['text' => ['required', 'string', 'max:20000']]);
        $text = trim($data['text']);
        abort_if($text === '', 422, 'Note text is required');
        $identity = $request->attributes->get('identity', []);
        $id = DB::table('client_notes')->insertGetId([
            'client_id' => $client,
            'text' => $text,
            'created_by_username' => $identity['preferred_username'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['data' => DB::table('client_notes')->find($id)], 201);
    }

    private function serializeClient(object $client): array
    {
        $row = (array) $client;
        $row['technologies'] = $client->technologies ? (json_decode($client->technologies, true) ?: []) : [];
        return $row;
    }
}
