<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Read-only rollback snapshot, captured before the authorization writer is
// started. Output contains protected identity IDs; never print in Actions logs.
try {
    $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
    $realm = rawurlencode((string) env('KEYCLOAK_REALM', 'irlix'));
    $auth = Http::asForm()->timeout(8)->post($base.'/realms/master/protocol/openid-connect/token', [
        'client_id' => 'admin-cli',
        'username' => (string) env('KEYCLOAK_ADMIN_USERNAME'),
        'password' => (string) env('KEYCLOAK_ADMIN_PASSWORD'),
        'grant_type' => 'password',
    ]);
    if (!$auth->successful() || !is_string($auth->json('access_token'))) {
        throw new RuntimeException('Keycloak backup authentication failed');
    }
    $linked = DB::table('employees')->whereNotNull('keycloak_user_id')
        ->orderBy('id')->get(['keycloak_user_id']);
    if ($linked->isEmpty()) throw new RuntimeException('No linked Keycloak identities to back up');

    $snapshot = [];
    foreach ($linked as $employee) {
        $keycloakId = (string) $employee->keycloak_user_id;
        $response = Http::withToken((string) $auth->json('access_token'))->timeout(12)
            ->acceptJson()->get($base.'/admin/realms/'.$realm.'/users/'.rawurlencode($keycloakId).'/role-mappings/realm');
        if (!$response->successful() || !is_array($response->json())) {
            throw new RuntimeException('Unable to snapshot every Keycloak role mapping');
        }
        $roles = [];
        foreach ($response->json() as $role) {
            if (!is_array($role) || !is_string($role['name'] ?? null)
                || !is_string($role['id'] ?? null)) {
                throw new RuntimeException('Invalid Keycloak role representation in backup');
            }
            $roles[] = ['id' => $role['id'], 'name' => $role['name']];
        }
        $snapshot[] = ['keycloak_user_id' => $keycloakId, 'roles' => $roles];
    }
    echo json_encode([
        'format' => 'irlix-keycloak-direct-role-backup-v1',
        'created_utc' => gmdate('c'),
        'linked_users' => count($snapshot),
        'entries' => $snapshot,
    ], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    // Never expose Keycloak response, credentials, identities, or URLs.
    fwrite(STDERR, "Authorization backup failed; rollout blocked\n");
    exit(3);
}
