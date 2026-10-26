<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContactPerson;
use App\Models\ContactRelation;
use App\Models\Lead;

final class LegacyContactRelationService
{
    public function contactExists(int $id): bool
    {
        return ContactPerson::query()->whereKey($id)->exists();
    }

    public function relatedEntityExists(string $type, int $id): bool
    {
        return match ($type) {
            'client' => Client::query()->whereKey($id)->exists(),
            'lead' => Lead::query()->whereKey($id)->exists(),
            default => false,
        };
    }

    public function create(array $data): object
    {
        $id = ContactPerson::query()->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        return (object) ContactPerson::query()->find($id)->getRawOriginal();
    }

    public function attach(int $contactId, array $data): object
    {
        abort_unless($this->contactExists($contactId), 404, 'Contact not found');
        abort_unless($this->relatedEntityExists((string) $data['entity_type'], (int) $data['entity_id']),
            404, 'Related entity not found');
        $id = ContactRelation::query()->insertGetId([
            'contact_person_id' => $contactId, ...$data,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (object) ContactRelation::query()->find($id)->getRawOriginal();
    }
}
