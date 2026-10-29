<?php

use App\Support\ClientContourAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finalize Clients' already-active AuthZ V2 matrix. Old role keys have been
 * imported by 000001, but remained visible/editable alongside the new keys.
 * New V2 rows always win: their administrator edits are the live policy.
 * This intermediate migration NEVER deletes legacy source rows.
 * Never create new Keycloak identity/role assignments in this migration.
 */
return new class extends Migration {
    public function up(): void
    {
        $mapping = [
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
        $clientPermissions = array_fill_keys(array_filter(
            array_keys(ClientContourAccess::PERMISSION_LABELS),
            static fn (string $key): bool => !str_starts_with($key, 'timesheets.')
        ), true);
        DB::transaction(static function () use ($mapping, $clientPermissions): void {
            $rows = DB::table('client_contour_permissions')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $oldRole = (string) $row->role;
                $permission = (string) $row->permission;
                $canonical = preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $oldRole) === 1;
                if ($canonical || str_starts_with($permission, 'timesheets.')) continue;
                if ($oldRole === 'platform-admin' || $oldRole === 'platform-tester') {
                    // Superuser access is virtual and locked; tester has no
                    // business rights. Neither may be reintroduced as a row.
                    continue;
                }
                // Unknown/production-manager role IDs are validated and
                // resolved AFTER the inter-service Timesheets transfer,
                // against Employees' actual production department aliases.
                if (!isset($mapping[$oldRole])) continue;
                if (!isset($clientPermissions[$permission])) continue;
                $dest = $mapping[$oldRole];
                $target = DB::table('client_contour_permissions')
                    ->where('role', $dest)->where('permission', $permission)
                    ->lockForUpdate()->first();
                if (!$target) {
                    DB::table('client_contour_permissions')->insert([
                        'role' => $dest, 'permission' => $permission,
                        'allowed' => (bool) $row->allowed,
                        'scope' => (string) $row->scope,
                        'denied' => (bool) ($row->denied ?? false),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    continue;
                }
                $same = (bool) $target->allowed === (bool) $row->allowed
                    && (string) $target->scope === (string) $row->scope
                    && (bool) ($target->denied ?? false) === (bool) ($row->denied ?? false);
                if ($same) continue;
                // The old UI and the new UI coexisted for a while. A newer
                // audited administrator edit wins even if it was made on the
                // historical column. Missing or equal audit timestamps are
                // ambiguous: never silently discard a different live rule.
                $oldEdit = DB::table('client_contour_permission_audit')
                    ->where('role', $oldRole)->where('permission', $permission)
                    ->max('created_at');
                $newEdit = DB::table('client_contour_permission_audit')
                    ->where('role', $dest)->where('permission', $permission)
                    ->max('created_at');
                if ($oldEdit !== null && ($newEdit === null
                    || strtotime((string)$oldEdit) > strtotime((string)$newEdit))) {
                    DB::table('client_contour_permissions')
                        ->where('id', $target->id)->update([
                            'allowed'=>(bool)$row->allowed,
                            'scope'=>(string)$row->scope,
                            'denied'=>(bool)($row->denied ?? false),
                            'updated_at'=>now(),
                        ]);
                } elseif ($newEdit === null || ($oldEdit !== null
                    && strtotime((string)$oldEdit) === strtotime((string)$newEdit))) {
                    throw new RuntimeException('Conflicting Clients role settings require review');
                }

            }

            // Head of Client Service gets the combined Clients business
            // capability of Account Management and Sales (including heads).
            // Existing V2 head edits, especially explicit denials, win.
            $sources = ['org:client-service','org:account-management','org:sales',
                        'head:account-management','head:sales'];
            $grants = DB::table('client_contour_permissions')
                ->whereIn('role', $sources)->where('allowed', true)
                ->where('denied', false)->get(['permission']);
            foreach ($grants as $source) {
                $permission = (string) $source->permission;
                if (!isset($clientPermissions[$permission])) continue;
                $head = DB::table('client_contour_permissions')
                    ->where('role', 'head:client-service')->where('permission', $permission)
                    ->first();
                if ($head) {
                    if ((bool) $head->allowed || (bool) ($head->denied ?? false)) continue;
                    // Never override a formerly edited head policy. Seeded
                    // default "none" is not the same as an explicit decision.
                    $edited = DB::table('client_contour_permission_audit')
                        ->whereIn('role', ['head:client-service','client-service-head'])
                        ->where('permission', $permission)->exists();
                    if ($edited) continue;
                    DB::table('client_contour_permissions')->where('id', $head->id)
                        ->update(['allowed'=>true,'scope'=>'all','denied'=>false,'updated_at'=>now()]);
                    continue;
                }
                DB::table('client_contour_permissions')->insert([
                    'role'=>'head:client-service', 'permission'=>$permission,
                    'allowed'=>true, 'scope'=>'all', 'denied'=>false,
                    'created_at'=>now(), 'updated_at'=>now(),
                ]);
            }

            // Do not delete source rows yet. The deployment must first
            // transfer Timesheets permissions to its owning service, then
            // resolve production head:* aliases from Employees. The finalizer
            // deletes ALL obsolete role/Timesheets rows atomically.

        });
    }

    public function down(): void
    {
        // No reverse: restored legacy rows would be detached from live
        // permissions and could override newer administrator decisions.
    }
};
