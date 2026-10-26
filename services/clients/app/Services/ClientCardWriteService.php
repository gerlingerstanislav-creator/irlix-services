<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\LegacyProjectMember;
use App\Models\ProjectAssignment;
use App\Models\ClientMemberFeedback;
use App\Models\ContactPerson;
use App\Models\ClientLegalEntity;
use App\Models\ClientNote;
use App\Support\ClientHistory;
use Illuminate\Support\Facades\DB;

final class ClientCardWriteService
{
    private static function raw($model): ?object
    {
        return $model ? (object) $model->getRawOriginal() : null;
    }

    public function project(int $id): ?object
    {
        return self::raw(Project::query()->find($id));
    }

    public function clientExists(int $id): bool
    {
        return Client::query()->whereKey($id)->exists();
    }

    public function contactExists(int $id): bool
    {
        return ContactPerson::query()->whereKey($id)->exists();
    }

    public function legalEntityExists(int $id): bool
    {
        return ClientLegalEntity::query()->whereKey($id)->exists();
    }

    public function renameProject(int $project, string $name): object
    {
        Project::query()->whereKey($project)->update(['name' => $name, 'updated_at' => now()]);
        return self::raw(Project::query()->find($project));
    }

    public function deleteProject(int $project): void
    {
        DB::transaction(function () use ($project): void {
            $row = Project::query()->whereKey($project)->lockForUpdate()->first();
            abort_unless($row, 404, 'Project not found');
            abort_if((bool) $row->is_default, 422, 'Основной проект нельзя удалить.');
            abort_if(
                LegacyProjectMember::query()->where('project_id', $project)->exists()
                || ProjectAssignment::query()->where('project_id', $project)->exists()
                || ClientMemberFeedback::query()->where('project_id', $project)->exists(),
                422, 'Нельзя удалить проект с участниками.'
            );
            Project::query()->whereKey($project)->delete();
        });
    }

    public function updateClient(int $client, array $data, ?string $username): object
    {
        DB::transaction(static function () use ($client, $data, $username): void {
            $model = Client::query()->whereKey($client)->lockForUpdate()->first();
            abort_unless($model, 404, 'Client not found');
            $before = (object) $model->getRawOriginal();
            Client::query()->whereKey($client)->update([...$data, 'updated_at' => now()]);
            $labels = [
                'name' => 'Название клиента', 'type' => 'Тип клиента',
                'sector' => 'Сектор клиента', 'sales_employee_id' => 'Sales-менеджер',
                'description' => 'Описание клиента',
                'act_approval_days' => 'Срок согласования акта',
                'payment_days' => 'Срок оплаты',
                'overtime_multiplier' => 'Коэффициент переработок',
                'act_approval_day_type' => 'Тип дней согласования',
                'payment_day_type' => 'Тип дней оплаты',
                'technologies' => 'Технологии',
            ];
            foreach ($data as $key => $value) {
                if ((string) ($before->$key ?? '') === (string) ($value ?? '')) continue;
                ClientHistory::record($client, 'client_updated', 'Изменение клиента',
                    ($labels[$key] ?? $key).': данные обновлены', $username);
            }
        });
        return self::raw(Client::query()->find($client));
    }

    public function updateContact(int $contact, array $data): object
    {
        ContactPerson::query()->whereKey($contact)->update([...$data, 'updated_at' => now()]);
        return self::raw(ContactPerson::query()->find($contact));
    }

    public function storeLegalEntity(int $client, array $data): object
    {
        $id = ClientLegalEntity::query()->insertGetId([
            'client_id' => $client, ...$data, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return self::raw(ClientLegalEntity::query()->find($id));
    }

    public function updateLegalEntity(int $entity, array $data): object
    {
        ClientLegalEntity::query()->whereKey($entity)->update([...$data, 'updated_at' => now()]);
        return self::raw(ClientLegalEntity::query()->find($entity));
    }

    public function storeNote(int $client, string $text, ?string $username): object
    {
        $id = ClientNote::query()->insertGetId([
            'client_id' => $client, 'text' => $text, 'created_by_username' => $username,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return self::raw(ClientNote::query()->find($id));
    }
}
