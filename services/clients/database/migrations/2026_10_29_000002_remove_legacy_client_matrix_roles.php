<?php

use App\Support\ClientContourAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finalize Clients' already-active AuthZ V2 matrix. Old role keys have been
 * imported by 000001, but remained visible/editable alongside the new keys.
 * New V2 rows always win: their administrator edits are the live policy.
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
                if (!isset($mapping[$oldRole])) {
                    // Never discard a nonstandard manually configured grant.
                    if (((bool) $row->allowed || (bool) ($row->denied ?? false))
                        && isset($clientPermissions[$permission])) {
                        throw new RuntimeException('Unmapped active Clients legacy role; V2 cleanup aborted');
                    }
                    continue;
                }
                if (!isset($clientPermissions[$permission])) continue;
                $dest = $mapping[$oldRole];
                $exists = DB::table('client_contour_permissions')
                    ->where('role', $dest)->where('permission', $permission)
                    ->exists();
                if (!$exists) {
                    DB::table('client_contour_permissions')->insert([
                        'role' => $dest, 'permission' => $permission,
                        'allowed' => (bool) $row->allowed,
                        'scope' => (string) $row->scope,
                        'denied' => (bool) ($row->denied ?? false),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
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

            // Timesheets has already imported its permissions into its own
            // DB. Remove only Clients-owned historical copies here.
            $rows = DB::table('client_contour_permissions')->get(['id','role','permission']);
            $remove = [];
            foreach ($rows as $row) {
                $canonical = preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', (string) $row->role) === 1;
                if (!$canonical || str_starts_with((string) $row->permission, 'timesheets.')) {
                    $remove[] = (int) $row->id;
                }
            }
            foreach (array_chunk($remove, 400) as $ids) {
                DB::table('client_contour_permissions')->whereIn('id', $ids)->delete();
            }
        });
    }

    public function down(): void
    {
        // No reverse: restored legacy rows would be detached from live
        // permissions and could override newer administrator decisions.
    }
};
