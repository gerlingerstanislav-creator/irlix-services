<?php
/** Trusted CI-only authenticated Storage V1 probe.
 * Uses a real persisted platform-admin Employee identity and the production
 * session controller. Emits only the opaque short-lived cookie to a private
 * subprocess pipe; NEVER print it into CI logs or write it to the filesystem.
 */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Http\Controllers\StorageV1SessionController;
use App\Models\Employee;
use App\Models\EmployeeAccessRole;
use App\Support\BrandbookGate;
use App\Support\SpecialRoles;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

try {
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $identity = null;
    foreach (EmployeeAccessRole::query()->where('role', SpecialRoles::PlatformAdmin)
             ->limit(50)->get() as $assignment) {
        $candidate = Employee::query()->find((int) $assignment->employee_id);
        if ($candidate && BrandbookGate::active($candidate)) {
            $identity = $candidate;
            break;
        }
    }
    if (!$identity || !(string) $identity->keycloak_user_id) {
        throw new RuntimeException('no_active_admin');
    }

    $request = Request::create('/api/storage-v1/session', 'POST');
    $request->attributes->set('employees_access', [
        'employee_id' => (int) $identity->id,
        'roles' => [SpecialRoles::PlatformAdmin],
        'services' => ['storage' => true],
        'allowed' => true,
    ]);
    $request->attributes->set('identity', ['sub' => (string) $identity->keycloak_user_id]);
    $response = $app->make(StorageV1SessionController::class)->store($request);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('admin_v1_session_denied');
    }
    $cookies = $response->headers->getCookies();
    if (count($cookies) !== 1 || $cookies[0]->getName() !== 'irlix_storage_v1'
        || !$cookies[0]->isHttpOnly()) {
        throw new RuntimeException('v1_cookie_invalid');
    }
    fwrite(STDOUT, $cookies[0]->getValue());
} catch (Throwable $e) {
    fwrite(STDERR, "Storage V1 admin session runtime assertion failed.\n");
    exit(1);
}
