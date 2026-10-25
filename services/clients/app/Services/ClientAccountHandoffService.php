<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAccountDelegation;
use App\Support\ClientHistory;
use Illuminate\Support\Facades\DB;

final class ClientAccountHandoffService
{
    public function permanent(int $clientId, int $newAccountId, array $access, ?string $username): object
    {
        return DB::transaction(function () use ($clientId, $newAccountId, $access, $username): object {
            $row = Client::query()->whereKey($clientId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->account_employee_id, 422, 'Для первичной передачи используйте действие назначения аккаунта.');
            $this->assertPrincipal($access, (object) $row->getRawOriginal());
            abort_if((int) $row->account_employee_id === $newAccountId, 422, 'Этот аккаунт уже назначен.');

            Client::query()->whereKey($clientId)->update([
                'account_employee_id' => $newAccountId, 'updated_at' => now(),
            ]);
            ClientAccountDelegation::query()->where('client_id', $clientId)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            ClientHistory::record($clientId, 'account_reassigned', 'Постоянная передача клиента',
                '#'.$row->account_employee_id.' → #'.$newAccountId, $username);
            return (object) Client::query()->findOrFail($clientId)->getRawOriginal();
        });
    }

    public function delegate(int $clientId, array $data, array $access, ?string $username): object
    {
        return DB::transaction(function () use ($clientId, $data, $access, $username): object {
            $row = Client::query()->whereKey($clientId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->account_employee_id, 422, 'Сначала назначьте постоянного аккаунта.');
            $this->assertPrincipal($access, (object) $row->getRawOriginal());
            abort_if((int) $row->account_employee_id === (int) $data['to_employee_id'],
                422, 'Нельзя заместить аккаунта им самим.');

            $overlap = ClientAccountDelegation::query()->where('client_id', $clientId)
                ->whereNull('revoked_at')
                ->where('starts_at', '<=', $data['ends_at'])
                ->where('ends_at', '>=', $data['starts_at'])->exists();
            abort_if($overlap, 422, 'На этот период уже назначено замещение.');

            $id = ClientAccountDelegation::query()->insertGetId([
                'client_id' => $clientId, 'from_employee_id' => $row->account_employee_id,
                'to_employee_id' => (int) $data['to_employee_id'],
                'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                'created_by_employee_id' => (int) ($access['employee']['id'] ?? 0) ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ClientHistory::record($clientId, 'account_delegated', 'Временное замещение аккаунта',
                '#'.$row->account_employee_id.' → #'.$data['to_employee_id']
                .' ('.$data['starts_at'].' — '.$data['ends_at'].')', $username);
            return (object) ClientAccountDelegation::query()->findOrFail($id)->getRawOriginal();
        });
    }

    public function revoke(int $clientId, int $delegationId, array $access, ?string $username): object
    {
        return DB::transaction(function () use ($clientId, $delegationId, $access, $username): object {
            $client = Client::query()->whereKey($clientId)->lockForUpdate()->first();
            abort_unless($client, 404);
            $this->assertPrincipal($access, (object) $client->getRawOriginal());
            $row = ClientAccountDelegation::query()->whereKey($delegationId)
                ->where('client_id', $clientId)->whereNull('revoked_at')->first();
            abort_unless($row, 404);
            ClientAccountDelegation::query()->whereKey($delegationId)
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            ClientHistory::record($clientId, 'account_delegation_revoked', 'Замещение прекращено',
                '#'.$row->to_employee_id, $username);
            return (object) ClientAccountDelegation::query()->findOrFail($delegationId)->getRawOriginal();
        });
    }

    private function assertPrincipal(array $access, object $client): void
    {
        $roles = $access['roles'] ?? [];
        $actorId = (int) ($access['employee']['id'] ?? 0);
        abort_unless(($access['platform_admin'] ?? false)
            || count(array_intersect(['accounting-head', 'client-service-head'], $roles)) > 0
            || ($actorId > 0 && $actorId === (int) $client->account_employee_id),
            403, 'Постоянная передача и назначение замещения доступны основному аккаунту либо руководителю.');
    }
}
