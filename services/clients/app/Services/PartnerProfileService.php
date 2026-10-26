<?php

namespace App\Services;

use App\Models\ContactPerson;
use App\Models\Partner;
use App\Models\PartnerContactRelation;
use App\Models\PartnerHistoryEvent;
use App\Models\PartnerLegalEntity;
use Illuminate\Support\Facades\DB;

final class PartnerProfileService
{
    public function exists(int $partner): bool
    {
        return Partner::query()->whereKey($partner)->exists();
    }

    public function profile(int $partner, bool $includeContacts = false): array
    {
        $row = Partner::query()->findOrFail($partner);
        $contacts = $includeContacts ? PartnerContactRelation::query()
            ->from('partner_contact_relations as rel')
            ->join('contact_people as person', 'person.id', '=', 'rel.contact_person_id')
            ->where('rel.partner_id', $partner)
            ->select(['rel.id', 'rel.contact_person_id', 'rel.position', 'rel.active',
                'person.full_name'])
            ->orderByDesc('rel.active')->orderBy('person.full_name')
            ->get()->map(static fn ($model) => $model->getRawOriginal())->all() : [];
        $entities = PartnerLegalEntity::query()->where('partner_id', $partner)
            ->orderBy('name')->get()->map(static fn ($model) => $model->getRawOriginal())->all();
        $events = PartnerHistoryEvent::query()->where('partner_id', $partner)
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(static fn ($model) => [
                'id' => $model->id,
                'type' => $model->type,
                'title' => $model->title,
                'text' => $model->text,
                'author' => $model->created_by_username,
                'created_at' => $model->created_at,
            ])->all();

        array_unshift($events, [
            'id' => 'partner-created-'.$partner, 'type' => 'partner_created',
            'title' => 'Создание партнёра', 'text' => (string) $row->name,
            'created_at' => $row->created_at,
        ]);
        return ['partner' => $row->getRawOriginal(), 'contacts' => $contacts,
            'legal_entities' => $entities, 'history' => $events,
            'contacts_available' => $includeContacts];
    }

    public function contactOptions(string $needle): array
    {
        return ContactPerson::query()->select(['id', 'full_name'])
            ->where('full_name', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $needle).'%')
            ->orderBy('full_name')->limit(50)->get()
            ->map(static fn ($row) => $row->getRawOriginal())->all();
    }

    public function record(int $partner, string $type, string $title, ?string $text, ?string $actor): void
    {
        PartnerHistoryEvent::query()->create([
            'partner_id' => $partner, 'type' => $type, 'title' => $title,
            'text' => $text, 'created_by_username' => $actor,
        ]);
    }

    public function comment(int $partner, string $text, ?string $actor): void
    {
        $this->record($partner, 'comment', 'Комментарий', $text, $actor);
    }

    public function linkContact(int $partner, array $data, ?string $actor): void
    {
        DB::transaction(function () use ($partner, $data, $actor) {
            $id = (int) ($data['contact_id'] ?? 0);
            if (!$id) {
                $name = trim((string) ($data['full_name'] ?? ''));
                abort_if($name === '', 422, 'Укажите ФИО контактного лица.');
                $id = (int) ContactPerson::query()->insertGetId([
                    'full_name' => $name, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                abort_unless(ContactPerson::query()->whereKey($id)->exists(), 404, 'Контакт не найден.');
            }
            $relation = PartnerContactRelation::query()->where('partner_id', $partner)
                ->where('contact_person_id', $id)->lockForUpdate()->first();
            $values = ['position' => $data['position'] ?? null, 'active' => true];
            if ($relation) $relation->update($values);
            else PartnerContactRelation::query()->create(['partner_id' => $partner,
                'contact_person_id' => $id, ...$values]);
            $this->record($partner, 'contact', 'Контактное лицо', 'Связь добавлена или обновлена', $actor);
        });
    }

    public function updateContactRelation(int $partner, int $relationId, array $data, ?string $actor): void
    {
        DB::transaction(function () use ($partner, $relationId, $data, $actor) {
            $row = PartnerContactRelation::query()->where('partner_id', $partner)
                ->whereKey($relationId)->lockForUpdate()->firstOrFail();
            $row->update($data);
            $this->record($partner, 'contact', 'Контактное лицо', 'Изменена связь с контактом', $actor);
        });
    }

    public function addLegalEntity(int $partner, array $data, ?string $actor): void
    {
        DB::transaction(function () use ($partner, $data, $actor) {
            PartnerLegalEntity::query()->create(['partner_id' => $partner, ...$data]);
            $this->record($partner, 'legal', 'Юридическое лицо', 'Добавлено юридическое лицо', $actor);
        });
    }

    public function updateLegalEntity(int $partner, int $entityId, array $data, ?string $actor): void
    {
        DB::transaction(function () use ($partner, $entityId, $data, $actor) {
            $row = PartnerLegalEntity::query()->where('partner_id', $partner)
                ->whereKey($entityId)->lockForUpdate()->firstOrFail();
            $row->update($data);
            $this->record($partner, 'legal', 'Юридическое лицо', 'Изменены реквизиты юридического лица', $actor);
        });
    }
}
