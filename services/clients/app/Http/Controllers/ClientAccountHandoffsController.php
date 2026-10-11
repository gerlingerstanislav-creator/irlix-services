<?php

namespace App\Http\Controllers;

use App\Support\ClientHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientAccountHandoffsController extends Controller
{
    public function permanent(Request $request, int $client)
    {
        $data = $request->validate(['account_employee_id' => ['required', 'integer', 'min:1']]);
        $access = (array) $request->attributes->get('client_contour_access', []);
        $this->assertAccount($access, (int) $data['account_employee_id']);
        return DB::transaction(function () use ($request, $client, $data) {
            $row = DB::table('clients')->where('id', $client)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->account_employee_id, 422, 'Для первичной передачи используйте действие назначения аккаунта.');
            abort_if((int)$row->account_employee_id === (int)$data['account_employee_id'], 422, 'Этот аккаунт уже назначен.');
            DB::table('clients')->where('id', $client)->update(['account_employee_id' => (int)$data['account_employee_id'], 'updated_at' => now()]);
            DB::table('client_account_delegations')->where('client_id', $client)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            ClientHistory::record($client, 'account_reassigned', 'Постоянная передача клиента', '#'.$row->account_employee_id.' → #'.$data['account_employee_id'], $this->username($request));
            return response()->json(['data' => DB::table('clients')->find($client)]);
        });
    }

    public function delegate(Request $request, int $client)
    {
        $data = $request->validate([
            'to_employee_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        ]);
        $access = (array) $request->attributes->get('client_contour_access', []);
        $this->assertAccount($access, (int) $data['to_employee_id']);
        return DB::transaction(function () use ($request, $client, $data) {
            $row = DB::table('clients')->where('id', $client)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->account_employee_id, 422, 'Сначала назначьте постоянного аккаунта.');
            abort_if((int)$row->account_employee_id === (int)$data['to_employee_id'], 422, 'Нельзя заместить аккаунта им самим.');
            $overlap = DB::table('client_account_delegations')->where('client_id', $client)
                ->whereNull('revoked_at')->where('starts_at', '<=', $data['ends_at'])->where('ends_at', '>=', $data['starts_at'])
                ->exists();
            abort_if($overlap, 422, 'На этот период уже назначено замещение.');
            $id = DB::table('client_account_delegations')->insertGetId([
                'client_id' => $client, 'from_employee_id' => $row->account_employee_id,
                'to_employee_id' => (int)$data['to_employee_id'], 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                'created_by_employee_id' => (int)($request->attributes->get('client_contour_access')['employee']['id'] ?? 0) ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ClientHistory::record($client, 'account_delegated', 'Временное замещение аккаунта',
                '#'.$row->account_employee_id.' → #'.$data['to_employee_id'].' ('.$data['starts_at'].' — '.$data['ends_at'].')', $this->username($request));
            return response()->json(['data' => DB::table('client_account_delegations')->find($id)], 201);
        });
    }

    public function revoke(Request $request, int $client, int $delegation)
    {
        return DB::transaction(function () use ($request, $client, $delegation) {
            abort_unless(DB::table('clients')->where('id', $client)->lockForUpdate()->first(), 404);
            $row = DB::table('client_account_delegations')->where('id', $delegation)->where('client_id', $client)->whereNull('revoked_at')->first();
            abort_unless($row, 404);
            DB::table('client_account_delegations')->where('id', $delegation)->update(['revoked_at' => now(), 'updated_at' => now()]);
            ClientHistory::record($client, 'account_delegation_revoked', 'Замещение прекращено', '#'.$row->to_employee_id, $this->username($request));
            return response()->json(['data' => DB::table('client_account_delegations')->find($delegation)]);
        });
    }

    private function assertAccount(array $access, int $id): void
    {
        abort_unless(($access['platform_admin'] ?? false) || in_array($id, array_map('intval', $access['account_employee_ids'] ?? []), true), 422, 'Выберите аккаунт-менеджера.');
    }

    private function username(Request $request): ?string
    {
        return $request->attributes->get('identity', [])['preferred_username'] ?? null;
    }
}
