<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Auth-readiness gate; never prints token, credentials or response payloads.
try {
    $base = rtrim((string) env('KEYCLOAK_URL', 'http://keycloak:8080/keycloak/auth'), '/');
    $login = Http::asForm()->timeout(8)->post(
        $base.'/realms/master/protocol/openid-connect/token',
        [
            'client_id' => 'admin-cli',
            'username' => (string) env('KEYCLOAK_ADMIN_USERNAME'),
            'password' => (string) env('KEYCLOAK_ADMIN_PASSWORD'),
            'grant_type' => 'password',
        ]
    );
    $token = $login->json('access_token');
    if (!$login->successful() || !is_string($token) || $token === '') {
        fwrite(STDERR, "Keycloak authorization readiness: admin token unavailable\n");
        exit(2);
    }

    $realm = rawurlencode((string) env('KEYCLOAK_REALM', 'irlix'));
    $response = Http::withToken($token)->timeout(8)->get(
        $base.'/admin/realms/'.$realm
    );
    if (!$response->successful()) {
        fwrite(STDERR, "Keycloak authorization readiness: realm API unavailable\n");
        exit(2);
    }
    echo "Keycloak authorization readiness: verified\n";
} catch (Throwable $e) {
    // Never log exception text: network libraries may include private URLs.
    fwrite(STDERR, "Keycloak authorization readiness: connection unavailable\n");
    exit(2);
}
