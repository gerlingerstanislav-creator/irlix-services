<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContactPerson;
use App\Models\ContactMethod;
use App\Models\ContactRelation;
use Illuminate\Support\Facades\DB;

final class ContactPeopleService
{
    private static function raw($model): ?object
    {
        return $model ? (object) $model->getRawOriginal() : null;
    }

    public function exists(int $contactId): bool
    {
        return ContactPerson::query()->whereKey($contactId)->exists();
    }

    public function hasMethod(int $contactId, int $methodId): bool
    {
        return ContactMethod::query()->whereKey($methodId)
            ->where('contact_person_id', $contactId)->exists();
    }

    public function hasClient(int $clientId): bool
    {
        return Client::query()->whereKey($clientId)->exists();
    }

    public function hasRelation(int $contactId, int $relationId): bool
    {
        return ContactRelation::query()->whereKey($relationId)
            ->where('contact_person_id', $contactId)->where('entity_type', 'client')->exists();
    }

    public function show(int $contactId): array
    {
        $person = self::raw(ContactPerson::query()->find($contactId));
        abort_unless($person, 404, 'Contact not found');
        return $this->serialize($person);
    }

    public function create(array $data): array
    {
        $id = DB::transaction(function () use ($data): int {
            $id = ContactPerson::query()->insertGetId([
                'full_name' => trim($data['full_name']),
                'position' => $data['position'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $methods = $data['methods'] ?? [];
            if (!empty($data['phone'])) {
                $methods[] = ['type' => 'Телефон', 'contact' => $data['phone'],
                    'is_active' => true, 'is_preferred' => false];
            }
            if (!empty($data['email'])) {
                $methods[] = ['type' => 'Email', 'contact' => $data['email'],
                    'is_active' => true, 'is_preferred' => false];
            }
            foreach ($methods as $method) $this->insertMethod($id, $method);
            foreach ($data['client_relations'] ?? [] as $relation) {
                $this->upsertClientRelation($id, (int) $relation['client_id'], $relation['position'] ?? null);
            }
            return $id;
        });
        return $this->show($id);
    }

    public function update(int $contactId, array $data): array
    {
        ContactPerson::query()->whereKey($contactId)->update([
            'full_name' => trim($data['full_name']), 'updated_at' => now(),
        ]);
        return $this->show($contactId);
    }

    public function createMethod(int $contactId, array $data): object
    {
        $id = DB::transaction(fn () => $this->insertMethod($contactId, $data));
        return self::raw(ContactMethod::query()->find($id));
    }

    public function updateMethod(int $contactId, int $methodId, array $data): object
    {
        DB::transaction(static function () use ($contactId, $methodId, $data): void {
            if (($data['is_preferred'] ?? false) === true) {
                ContactMethod::query()->where('contact_person_id', $contactId)
                    ->update(['is_preferred' => false]);
                $data['is_active'] = true;
            }
            if (($data['is_active'] ?? true) === false) $data['is_preferred'] = false;
            ContactMethod::query()->whereKey($methodId)->update([...$data, 'updated_at' => now()]);
        });
        return self::raw(ContactMethod::query()->find($methodId));
    }

    public function deleteMethod(int $contactId, int $methodId): void
    {
        $deleted = ContactMethod::query()->whereKey($methodId)
            ->where('contact_person_id', $contactId)->delete();
        abort_unless($deleted, 404, 'Contact method not found');
    }

    public function createClientRelation(int $contactId, int $clientId, ?string $position): object
    {
        $id = $this->upsertClientRelation($contactId, $clientId, $position);
        return $this->serializeRelation($id);
    }

    public function updateClientRelation(int $relationId, array $data): object
    {
        ContactRelation::query()->whereKey($relationId)->update([...$data, 'updated_at' => now()]);
        return $this->serializeRelation($relationId);
    }

    public function deleteClientRelation(int $contactId, int $relationId): void
    {
        $deleted = ContactRelation::query()->whereKey($relationId)
            ->where('contact_person_id', $contactId)->where('entity_type', 'client')->delete();
        abort_unless($deleted, 404, 'Contact relation not found');
    }

    private function insertMethod(int $contactId, array $method): int
    {
        $type = trim((string) ($method['type'] ?? ''));
        $contact = trim((string) ($method['contact'] ?? ''));
        abort_if($type === '' || $contact === '', 422, 'Type and contact are required');
        $active = $method['is_active'] ?? true;
        $preferred = ($method['is_preferred'] ?? false) && $active;
        if ($preferred) ContactMethod::query()->where('contact_person_id', $contactId)
            ->update(['is_preferred' => false]);
        return ContactMethod::query()->insertGetId([
            'contact_person_id' => $contactId, 'type' => $type, 'contact' => $contact,
            'is_active' => $active, 'is_preferred' => $preferred,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function upsertClientRelation(int $contactId, int $clientId, ?string $position): int
    {
        abort_unless($this->hasClient($clientId), 404, 'Client not found');
        $role = trim((string) ($position ?? '')) ?: null;
        $existing = ContactRelation::query()->where('contact_person_id', $contactId)
            ->where('entity_type', 'client')->where('entity_id', $clientId)->first();
        if ($existing) {
            ContactRelation::query()->whereKey($existing->id)->update([
                'relation_role' => $role, 'active' => true, 'updated_at' => now(),
            ]);
            return (int) $existing->id;
        }
        return ContactRelation::query()->insertGetId([
            'contact_person_id' => $contactId, 'entity_type' => 'client',
            'entity_id' => $clientId, 'relation_role' => $role, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function serializeRelation(int $relationId): object
    {
        $model = ContactRelation::query()->from('contact_relations as relation')
            ->join('clients as client', 'client.id', '=', 'relation.entity_id')
            ->where('relation.id', $relationId)->where('relation.entity_type', 'client')
            ->select([
                'relation.id', 'relation.entity_id as client_id', 'client.name as client_name',
                'relation.relation_role as position', 'relation.active',
            ])->first();
        return self::raw($model);
    }

    private function serialize(object $person): array
    {
        $methods = ContactMethod::query()->where('contact_person_id', $person->id)
            ->orderByDesc('is_active')->orderByDesc('is_preferred')->orderBy('id')->get()
            ->map(static fn ($x) => (object) $x->getRawOriginal());

        $relations = ContactRelation::query()->from('contact_relations as relation')
            ->join('clients as client', 'client.id', '=', 'relation.entity_id')
            ->where('relation.contact_person_id', $person->id)->where('relation.entity_type', 'client')
            ->orderByDesc('relation.active')->orderBy('client.name')
            ->select([
                'relation.id', 'relation.entity_id as client_id', 'client.name as client_name',
                'relation.relation_role as position', 'relation.active',
            ])->get()->map(static fn ($x) => (object) $x->getRawOriginal());

        return [
            'id' => $person->id, 'full_name' => $person->full_name,
            'methods' => $methods, 'client_relations' => $relations,
            'created_at' => $person->created_at, 'updated_at' => $person->updated_at,
        ];
    }
}
