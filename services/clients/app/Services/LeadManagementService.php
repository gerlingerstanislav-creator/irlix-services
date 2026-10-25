<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientLegalEntity;
use App\Models\ClientRequest;
use App\Models\ContactMethod;
use App\Models\ContactPerson;
use App\Models\ContactRelation;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadLegalEntity;
use Illuminate\Support\Facades\DB;

final class LeadManagementService
{
    private static function raw($model): ?object
    {
        return $model ? (object) $model->getRawOriginal() : null;
    }

    public function lead(int $id): ?object
    {
        return self::raw(Lead::query()->find($id));
    }

    public function assertEditable(int $id): void
    {
        abort_if(Lead::query()->whereKey($id)->whereNotNull('converted_client_id')->exists(), 409,
            'Лид уже конвертирован и доступен только для чтения.');
    }

    public function create(array $data, int $responsibleId): object
    {
        $id = DB::transaction(static function () use ($data, $responsibleId): int {
            $createdAt = now();
            $id = Lead::query()->insertGetId([
                'name' => trim($data['name']), 'source' => $data['source'] ?? null,
                'responsible_employee_id' => $responsibleId, 'status' => 'Новый лид',
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            LeadEvent::query()->insert([
                'lead_id' => $id, 'type' => 'status', 'status' => 'Новый лид',
                'created_by_employee_id' => $responsibleId,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            return $id;
        });
        return $this->lead($id);
    }

    public function update(int $id, array $data, int $actorId): object
    {
        DB::transaction(function () use ($id, $data, $actorId): void {
            $current = Lead::query()->whereKey($id)->lockForUpdate()->first();
            abort_unless($current, 404, 'Lead not found');
            abort_if($current->converted_client_id, 409,
                'Лид уже конвертирован и доступен только для чтения.');
            Lead::query()->whereKey($id)->update([...$data, 'updated_at' => now()]);
            if (isset($data['status']) && $data['status'] !== $current->status) {
                LeadEvent::query()->insert([
                    'lead_id' => $id, 'type' => 'status', 'status' => $data['status'],
                    'created_by_employee_id' => $actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        return $this->lead($id);
    }

    public function comment(int $id, string $comment, int $actorId): object
    {
        $this->assertEditable($id);
        $eventId = LeadEvent::query()->insertGetId([
            'lead_id' => $id, 'type' => 'comment', 'comment' => trim($comment),
            'created_by_employee_id' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return self::raw(LeadEvent::query()->find($eventId));
    }

    public function legalEntity(int $leadId, array $data): object
    {
        $id = LeadLegalEntity::query()->insertGetId([
            ...$data, 'lead_id' => $leadId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return self::raw(LeadLegalEntity::query()->find($id));
    }

    public function attachContact(int $leadId, array $data): object
    {
        $contactId = DB::transaction(static function () use ($leadId, $data): int {
            $contactId = (int) ($data['contact_id'] ?? 0);
            $created = !$contactId;
            if ($created) {
                $contactId = ContactPerson::query()->insertGetId([
                    'full_name' => trim($data['full_name']),
                    'position' => $data['position'] ?? null,
                    'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ([['Телефон', $data['phone'] ?? null], ['Email', $data['email'] ?? null]] as [$type, $value]) {
                    if (!$value) continue;
                    ContactMethod::query()->insert([
                        'contact_person_id' => $contactId, 'type' => $type, 'contact' => $value,
                        'is_active' => true, 'is_preferred' => false,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            ContactRelation::query()->updateOrInsert(
                ['contact_person_id' => $contactId, 'entity_type' => 'lead', 'entity_id' => $leadId],
                ['relation_role' => $data['position'] ?? null, 'active' => true,
                    'updated_at' => now(), 'created_at' => now()]
            );
            return $contactId;
        });
        return self::raw(ContactPerson::query()->find($contactId));
    }

    public function convert(int $leadId, object $expectedLead, array $data): object
    {
        $salesId = (int) $expectedLead->responsible_employee_id;
        $clientId = DB::transaction(static function () use ($leadId, $expectedLead, $data, $salesId): int {
            $locked = Lead::query()->whereKey($leadId)->lockForUpdate()->first();
            abort_unless($locked, 404, 'Lead not found');
            abort_if($locked->converted_client_id, 422, 'Лид уже конвертирован.');
            abort_unless($locked->status === 'Сделка закрыта - Успех', 422, 'Лид не находится в статусе успеха.');
            abort_unless((int) $locked->responsible_employee_id === $salesId, 409,
                'Ответственный по лиду изменился. Повторите конвертацию.');
            $clientId = Client::query()->insertGetId([
                'name' => $data['name'] ?? $expectedLead->name,
                'type' => $data['type'] ?? null, 'sector' => $data['sector'] ?? null,
                'sales_employee_id' => $salesId,
                'account_employee_id' => $data['account_employee_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // The client does not need a project to retain memberships.
            $contactIds = ContactRelation::query()->where('entity_type', 'lead')
                ->where('entity_id', $leadId)->where('active', true)->pluck('contact_person_id');
            foreach ($contactIds as $contactId) {
                ContactRelation::query()->insert([
                    'contact_person_id' => $contactId,
                    'entity_type' => 'client', 'entity_id' => $clientId, 'active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (LeadLegalEntity::query()->where('lead_id', $leadId)->get() as $entity) {
                ClientLegalEntity::query()->insert([
                    'client_id' => $clientId, 'name' => $entity->name, 'inn' => $entity->inn,
                    'full_name' => $entity->full_name, 'ogrn' => $entity->ogrn, 'kpp' => $entity->kpp,
                    'registration_date' => $entity->registration_date, 'okpo' => $entity->okpo,
                    'oktmo' => $entity->oktmo, 'address' => $entity->address,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            Lead::query()->whereKey($leadId)->update([
                'converted_client_id' => $clientId, 'updated_at' => now(),
            ]);
            ClientRequest::query()->where('lead_id', $leadId)->whereNull('client_id')
                ->update(['client_id' => $clientId, 'updated_at' => now()]);
            LeadEvent::query()->insert([
                'lead_id' => $leadId, 'type' => 'converted',
                'comment' => 'Создан клиент #'.$clientId,
                'created_by_employee_id' => $salesId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $clientId;
        });
        return self::raw(Client::query()->find($clientId));
    }
}
