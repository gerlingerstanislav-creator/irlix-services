<?php

declare(strict_types=1);

use App\Support\KeycloakCorporateProfileSync;
use App\Models\KeycloakProfileSync;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$once = in_array('--once', $argv, true);
$enabled = filter_var(env('IRLIX_PROFILE_SYNC_WRITE_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
if (!$enabled) {
    fwrite(STDERR, "Keycloak corporate profile synchronization is disabled by operator\n");
    exit(0);
}
$sync = new KeycloakCorporateProfileSync();
do {
    try {
        // Recover a claim interrupted by a container restart.
        KeycloakProfileSync::query()->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'retry', 'available_at' => now()]);

        $batch = KeycloakProfileSync::query()
            ->whereIn('status', ['pending', 'retry'])
            ->where('available_at', '<=', now())
            ->orderBy('available_at')->limit(20)->get();

        foreach ($batch as $entry) {
            $where = static fn () => KeycloakProfileSync::query()
                ->where('employee_id', $entry->employee_id)
                ->where('version', $entry->version);
            $claimed = $where()->whereIn('status', ['pending', 'retry'])
                ->update(['status' => 'processing', 'updated_at' => now()]) === 1;
            if (!$claimed) continue;
            try {
                $sync->reconcile((int) $entry->employee_id);
                // A concurrent Employees update increments version and remains
                // pending. Never mask that newer change as already synced.
                $where()->where('status', 'processing')->update([
                    'status' => 'synced', 'attempts' => 0, 'last_error' => null,
                    'synced_at' => now(), 'updated_at' => now(),
                ]);
            } catch (Throwable $error) {
                $attempts = (int) $entry->attempts + 1;
                $where()->where('status', 'processing')->update([
                    'status' => $attempts >= 20 ? 'failed' : 'retry',
                    'attempts' => $attempts,
                    'available_at' => now()->addSeconds(min(3600, 5 * (2 ** min($attempts, 9)))),
                    // No identities, passwords or raw HTTP bodies in logs.
                    'last_error' => mb_substr(get_class($error).': '.preg_replace(
                        '/[A-Za-z0-9_.%+-]+@[A-Za-z0-9.-]+/', '[email]', $error->getMessage()
                    ), 0, 600),
                    'updated_at' => now(),
                ]);
                fwrite(STDERR, "Keycloak profile sync failed for queue item; see sanitized status\n");
            }
        }
    } catch (Throwable $error) {
        fwrite(STDERR, "Keycloak profile sync queue unavailable: ".get_class($error)."\n");
    }
    if (!$once) sleep(3);
} while (!$once);
