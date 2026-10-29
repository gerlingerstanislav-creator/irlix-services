<?php
namespace App\Services;

use App\Models\ClientContourPermission;
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
                    // New administrator-edited V2 policy always wins. A
                    // seeded unassigned row is not an intentional deny.
                    if ($dest) {
                        $hasAudit = DB::table('client_contour_permission_audit')
                            ->where('role', $role)->where('permission', $legacy->permission)
                            ->exists();
                        if ($hasAudit || (bool) $dest->allowed || (bool) ($dest->denied ?? false)) continue;
                        $dest->allowed = (bool) $legacy->allowed;
                        $dest->denied = (bool) ($legacy->denied ?? false);
                        $dest->scope = $dest->denied ? 'none' : (string) $legacy->scope;
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

            // Fail closed on any active noncanonical business role not mapped
            // by the static conversion or resolved production-head transfer.
            foreach ($rows as $old) {
                $role = (string) $old->role;
                if (preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1
                    || str_starts_with((string)$old->permission,'timesheets.')
                    || $role === 'platform-admin'
                    || $role === 'department-manager') continue;
                if ((bool) $old->allowed || (bool) ($old->denied ?? false)) {
                    throw new RuntimeException('Active unknown legacy Clients role; cleanup aborted');
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
