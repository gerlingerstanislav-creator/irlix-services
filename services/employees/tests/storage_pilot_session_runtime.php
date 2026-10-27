<?php
/**
 * Read-only runtime assertion of the Storage pilot's admin cookie workflow.
 * Only used by server-side CI; the short-lived cookie is emitted to a private
 * subprocess pipe and MUST NOT be printed to CI logs or persisted.
 */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Http\Controllers\StoragePilotSessionController;
use App\Models\Employee;
use App\Models\EmployeeAccessRole;
use App\Services\StoragePilotAccessService;
use App\Support\SpecialRoles;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

try {
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $assignment = EmployeeAccessRole::query()->where('role', SpecialRoles::PlatformAdmin)->first();
    if (!$assignment) throw new RuntimeException('no_platform_admin_assignment');
    $employee = Employee::query()->find((int)$assignment->employee_id);
    if (!$employee || !(string)$employee->keycloak_user_id)
        throw new RuntimeException('no_active_platform_admin_identity');

    $request = Request::create('/api/storage-pilot/session', 'POST');
    $request->attributes->set('employees_access', [
        'employee_id' => (int)$employee->id,
        'roles' => [SpecialRoles::PlatformAdmin],
        'services' => ['storage' => true],
        'allowed' => true,
    ]);
    $request->attributes->set('identity', ['sub' => (string)$employee->keycloak_user_id]);

    $controller = $app->make(StoragePilotSessionController::class);
    $response = $controller->store($request);
    if ($response->getStatusCode() !== 200)
        throw new RuntimeException('storage_session_controller_denied');
    $cookies = $response->headers->getCookies();
    if (count($cookies) !== 1 || $cookies[0]->getName() !== 'irlix_storage_pilot')
        throw new RuntimeException('storage_cookie_missing');
    if (!$cookies[0]->isHttpOnly())
        throw new RuntimeException('storage_cookie_not_http_only');
    $token = $cookies[0]->getValue();
    if ($app->make(StoragePilotAccessService::class)->statusForCookie(
        'irlix_storage_pilot='.rawurlencode($token)
    ) !== 204) throw new RuntimeException('storage_cookie_did_not_authorize');

    // Only the trusted invoking CI process captures this token in memory.
    fwrite(STDOUT, $token);
} catch (Throwable $e) {
    // Never log identity, employee data, encrypted cookie, or credentials.
    fwrite(STDERR, 'Storage session runtime verification failed: '.get_class($e)."\n");
    exit(1);
}
