<?php
namespace App\Services;

use App\Models\ClientContourPermission;
use App\Models\ClientContourPermissionAudit;
use App\Support\ClientContourAccess;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Final part of the Clients V2 conversion, after Timesheets has received its
 * historic rows. Source production departments come from Employees' own
 * database through the protected local service-to-service CLI, not guessed.
 */
final class FinalizeLegacyClientPermissions
{
    public function run(array $productionAliases): void
    {
        $productionHeads = [];
        foreach ($productionAliases as $alias) {
            if (!is_string($alias) ||
                preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias) !== 1) {
                throw new RuntimeException('Invalid production organization alias');
            }
            $productionHeads['head:'.$alias] = true;
        }
        DB::transaction(static function () use ($productionHeads): void {
            $rows = ClientContourPermission::query()->lockForUpdate()->get();
            $managerRows = $rows->filter(static fn ($row) =>
                (string) $row->role === 'department-manager'
                && !str_starts_with((string) $row->permission, 'timesheets.')
                && ((bool) $row->allowed || (bool) ($row->denied ?? false)));

            if ($managerRows->isNotEmpty() && !$productionHeads) {
                throw new RuntimeException('No production head roles for existing manager grants');
            }

            foreach ($managerRows as $legacy) {
                foreach (array_keys($productionHeads) as $role) {
                    $dest = ClientContourPermission::query()
                        ->where('role', $role)
                        ->where('permission', $legacy->permission)
                        ->lockForUpdate()->first();
                    // Previously the generic manager and the concrete head
                    // were two simultaneous roles. Preserve their effective
                    // widest scope and explicit deny, unless a newer audited
                    // edit on the canonical head intentionally replaced it.
                    if ($dest) {
                        $headAudit = ClientContourPermissionAudit::query()
                            ->where('role', $role)
                            ->where('permission', $legacy->permission)
                            ->max('created_at');
                        $oldAudit = ClientContourPermissionAudit::query()
                            ->where('role', 'department-manager')
                            ->where('permission', $legacy->permission)
                            ->max('created_at');
                        if ($headAudit !== null && ($oldAudit === null
                            || strtotime((string)$headAudit) >= strtotime((string)$oldAudit))) {
                            continue;
                        }
                        $oldDeny = (bool) ($legacy->denied ?? false);
                        if ($oldDeny) {
                            $dest->denied = true;
                            $dest->allowed = false;
                            $dest->scope = 'none';
                        } elseif (!(bool) ($dest->denied ?? false)) {
                            $rank=['none'=>0,'own'=>1,'team'=>2,'all'=>3];
                            if (!$dest->allowed ||
                                ($rank[(string)$legacy->scope] ?? 0) >
                                ($rank[(string)$dest->scope] ?? 0)) {
                                $dest->allowed = true;
                                $dest->scope = (string)$legacy->scope;
                            }
                        }
                        $dest->save();
                        continue;
                    }
                    ClientContourPermission::query()->create([
                        'role'=>$role, 'permission'=>(string)$legacy->permission,
                        'allowed'=>(bool)$legacy->allowed,
                        'scope'=>(bool)($legacy->denied ?? false) ? 'none' : (string)$legacy->scope,
                        'denied'=>(bool)($legacy->denied ?? false),
                    ]);
                }
            }

            // Known old columns WERE migrated to the existing V2 roles
            // before this finalizer. They must not be classified as UNKNOWN.
            // Verify each effective old rule has a corresponding V2 row.
            // A manually edited V2 decision may intentionally differ, but
            // without an audit trace any difference aborts deletion.
            $known = [
                'employee' => 'org:irlix',
                'client-service' => 'org:client-service',
                'account-manager' => 'org:account-management',
                'accounting-head' => 'head:account-management',
                'sales-manager' => 'org:sales',
                'sales-head' => 'head:sales',
                'client-service-head' => 'head:client-service',
                'personnel-officer' => 'special:personnel-officer',
                'system-admin' => 'special:system-admin',
            ];
            $recognized = array_fill_keys(
                array_keys(ClientContourAccess::PERMISSION_LABELS), true
            );
            foreach ($rows as $old) {
                $role = (string) $old->role;
                $permission = (string) $old->permission;
                if (preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1
                    || str_starts_with($permission, 'timesheets.')) continue;
                if ($role === 'platform-admin' || $role === 'platform-tester') {
                    // Both were explicitly excluded from regular Clients
                    // grants; the only superuser is special:platform-admin.
                    continue;
                }
                if ($role === 'department-manager') continue;
                $active = (bool) $old->allowed || (bool) ($old->denied ?? false);
                if (!isset($known[$role])) {
                    if ($active) throw new RuntimeException('Unrecognized active legacy Clients role');
                    continue;
                }
                if (!isset($recognized[$permission])) {
                    if ($active) throw new RuntimeException('Unrecognized active Clients permission');
                    continue;
                }
                $v2 = ClientContourPermission::query()
                    ->where('role', $known[$role])
                    ->where('permission', $permission)
                    ->first();
                if (!$v2) {
                    if ($active) throw new RuntimeException('Missing V2 role permission after import');
                    continue;
                }
                $same = (bool) $v2->allowed === (bool) $old->allowed
                    && (string) $v2->scope === (string) $old->scope
                    && (bool) ($v2->denied ?? false) === (bool) ($old->denied ?? false);
                if ($same) continue;
                $oldAudit = ClientContourPermissionAudit::query()
                    ->where('role', $role)->where('permission', $permission)
                    ->max('created_at');
                $v2Audit = ClientContourPermissionAudit::query()
                    ->where('role', $known[$role])->where('permission', $permission)
                    ->max('created_at');
                if ($v2Audit === null || ($oldAudit !== null
                    && strtotime((string) $v2Audit) <= strtotime((string) $oldAudit))) {
                    throw new RuntimeException('Unreconciled Clients policy difference');
                }
            }
            // No business right stored under a retired role survives.
            // Timesheets historical rows are deleted only after a completed
            // transfer into Timesheets, before this executable is called.
            ClientContourPermission::query()->where('permission', 'like', 'timesheets.%')->delete();
            ClientContourPermission::query()
                ->where('role', 'not like', 'org:%')
                ->where('role', 'not like', 'head:%')
                ->where('role', 'not like', 'special:%')
                ->delete();
        });
    }
}
