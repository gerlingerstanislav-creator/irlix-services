<?php
declare(strict_types=1);

// Protected one-time import of explicit Finance role denials from Employees.
// Source is role/permission metadata only. Never access another DB schema.
// Finance's own persisted matrix is authoritative after this transfer.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $payload = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (($payload['version'] ?? null) !== 1 || !is_array($payload['denials'] ?? null)) {
        throw new RuntimeException('Invalid source');
    }
    $allowed = array_fill_keys(\App\Services\FinanceRoleAuthorization::PERMISSIONS, true);
    $denials = [];
    foreach ($payload['denials'] as $item) {
        if (!is_array($item) || !is_string($item['role'] ?? null)
            || !is_string($item['permission'] ?? null)
            || !isset($allowed[$item['permission']])) throw new RuntimeException('Invalid rule');
        $source = $item['role'];
        // Employees applies platform-admin independently of its rule table.
        // Never turn the global superuser into a local blanket deny.
        if ($source === 'platform-admin') continue;
        $special = ['personnel-officer', 'system-admin', 'top-management', 'platform-tester'];
        if ($source === 'manager') $role = 'head:*';
        elseif (in_array($source, $special, true)) $role = 'special:'.$source;
        elseif ($source === 'account-manager') $role = 'org:account-management';
        elseif ($source === 'sales-manager') $role = 'org:sales';
        elseif (str_ends_with($source, '-head')) $role = 'head:'.substr($source, 0, -5);
        elseif (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $source)) $role = 'org:'.$source;
        else throw new RuntimeException('Unknown role label');
        if ($role !== 'head:*'
            && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) !== 1) {
            throw new RuntimeException('Invalid translated role');
        }
        $denials[$role.'|'.$item['permission']] = [$role, $item['permission']];
    }

    \Illuminate\Support\Facades\DB::transaction(static function () use ($denials): void {
        foreach ($denials as [$role, $permission]) {
            $existing = \Illuminate\Support\Facades\DB::table('finance_role_permissions')
                ->where('role',$role)->where('permission',$permission)
                ->lockForUpdate()->first();
            if ($existing && !$existing->allowed && $existing->scope === 'none') continue;
            if ($existing) {
                // Explicit new-system edits always take precedence over
                // historical import. Do not silently overwrite administrator policy.
                $modified = \Illuminate\Support\Facades\DB::table('finance_permission_audit')
                    ->where('role',$role)->where('permission',$permission)->exists();
                if ($modified) throw new RuntimeException('Existing V2 rule was manually edited');
                \Illuminate\Support\Facades\DB::table('finance_role_permissions')
                    ->where('id',$existing->id)
                    ->update(['allowed'=>false,'scope'=>'none','updated_at'=>now()]);
            } else {
                \Illuminate\Support\Facades\DB::table('finance_role_permissions')->insert([
                    'role'=>$role,'permission'=>$permission,'allowed'=>false,
                    'scope'=>'none','created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            \Illuminate\Support\Facades\DB::table('finance_permission_audit')->insert([
                'actor'=>'authz-v2-initial-import','role'=>$role,
                'permission'=>$permission,
                'before'=>$existing ? json_encode(['allowed'=>(bool)$existing->allowed,
                    'scope'=>(string)$existing->scope], JSON_THROW_ON_ERROR) : null,
                'after'=>json_encode(['allowed'=>false,'scope'=>'none'], JSON_THROW_ON_ERROR),
                'created_at'=>now(),
            ]);
        }
    });
    fwrite(STDERR, "Finance role denials imported into Finance-owned V2 matrix.\n");
} catch (\Throwable) {
    fwrite(STDERR, "Finance V2 deny import failed; activation is not safe.\n");
    exit(1);
}
