<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Support\ClientHistory;
use Illuminate\Support\Facades\DB;

final class ClientRegistryService
{
    /** Keep the raw attributes and numeric types exposed by the existing API. */
    public function client(int $id): ?object
    {
        $model = Client::query()->find($id);
        return $model ? (object) $model->getRawOriginal() : null;
    }

    public function create(array $data): object
    {
        $id = Client::query()->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        // Projects are optional; membership is bound directly to the client.
        return $this->client($id);
    }

    public function update(int $id, array $data): object
    {
        abort_unless(Client::query()->whereKey($id)->exists(), 404, 'Client not found');
        Client::query()->whereKey($id)->update([...$data, 'updated_at' => now()]);
        return $this->client($id);
    }

    public function transfer(int $id, int $accountEmployeeId, ?string $username): object
    {
        DB::transaction(static function () use ($id, $accountEmployeeId, $username): void {
            $current = Client::query()->whereKey($id)->lockForUpdate()->first();
            abort_unless($current, 404, 'Client not found');
            // Serialize concurrent initial handoffs on the same client.
            abort_if($current->account_employee_id, 422, 'Клиент уже передан аккаунт-менеджеру.');
            Client::query()->whereKey($id)->update([
                'account_employee_id' => $accountEmployeeId, 'updated_at' => now(),
            ]);
            ClientHistory::record($id, 'account_assigned', 'Первичная передача клиента',
                '#'.$current->sales_employee_id.' → #'.$accountEmployeeId, $username);
        });
        return $this->client($id);
    }

    public function createProject(int $clientId, string $name): object
    {
        abort_unless(Client::query()->whereKey($clientId)->exists(), 404, 'Client not found');
        $id = Project::query()->insertGetId([
            'client_id' => $clientId, 'name' => trim($name), 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $model = Project::query()->find($id);
        return (object) $model->getRawOriginal();
    }
}
