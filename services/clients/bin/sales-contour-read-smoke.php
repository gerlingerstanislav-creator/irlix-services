<?php

/**
 * Read-only production diagnostic. Uses an in-process synthetic own-scoped
 * Sales projection (never a browser token), invokes the actual Clients guard
 * and overview use case, and prints only statuses/exception location.
 *
 * No customer, employee, request, or log payload is printed. The check does
 * not mutate the database or expose an HTTP impersonation endpoint.
 */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Http\Middleware\ClientsAccountingAccess;
use App\Services\ClientsOverviewPageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "Sales contour read smoke: JWT mode unavailable (NOT TESTED)\n");
    exit(1);
}

$assigned = DB::table('clients')->whereNotNull('sales_employee_id')
    ->orderBy('id')->limit(3)->pluck('sales_employee_id');
$responsible = DB::table('client_requests')->whereNotNull('responsible_employee_id')
    ->orderBy('id')->limit(3)->pluck('responsible_employee_id');
$actors = collect([...$assigned, ...$responsible])
    ->map(static fn ($id) => (int) $id)->filter(static fn ($id) => $id > 0)
    ->unique()->take(4)->values()->all();
if ($actors === []) {
    fwrite(STDERR, "Sales contour read smoke: no assigned owners or responsible requests (NOT TESTED)\n");
    exit(1);
}
$guard = app(ClientsAccountingAccess::class);
$service = app(ClientsOverviewPageService::class);

foreach ($actors as $actorId) {
    $permissions = [
        'clients.view' => ['allowed' => true, 'scope' => 'own'],
        'requests.view' => ['allowed' => true, 'scope' => 'own'],
        'positions.view' => ['allowed' => true, 'scope' => 'own'],
        'attempts.view' => ['allowed' => true, 'scope' => 'own'],
    ];
    $access = [
        'employee' => ['id' => $actorId],
        'roles' => ['sales-manager'],
        'platform_admin' => false,
        'permissions' => $permissions,
        'client_service_permissions' => $permissions,
        'department_ids' => [],
        'production_department_ids' => [],
        'production_employee_ids' => [],
        'production_directions' => [],
        'legacy_direction_ids' => [],
        'team_employee_ids' => [$actorId],
    ];

    foreach (['clients', 'catalog', 'requests'] as $section) {
        $request = Request::create('/api/overview?section='.$section.'&page=1&per_page=5', 'GET');
        $request->attributes->set('client_contour_verified_at_entitlement', true);
        $request->attributes->set('client_contour_access', $access);
        $request->attributes->set('clients_employees_directory', [
            'employees' => [['id' => $actorId, 'department_id' => null]],
        ]);
        try {
            $response = $guard->handle($request, static function () use ($service, $access, $section, $request) {
                return response()->json($service->page(
                    $access, $section, 1, 5, null, [],
                    $request->attributes->get('clients_overview_client_ids'),
                    $request->attributes->get('clients_overview_lead_employee_ids'),
                    $request->attributes->get('clients_overview_contact_ids'),
                    (array) $request->attributes->get('clients_workflow_view_scopes', []),
                ));
            });
            if ($response->getStatusCode() !== 200) {
                fwrite(STDERR, "Sales contour read smoke failed: section={$section} status={$response->getStatusCode()}\n");
                exit(1);
            }
        } catch (\Throwable $e) {
            // Never print SQL exception messages: bindings may contain PII.
            fwrite(STDERR, "Sales contour read smoke failed: section={$section} exception="
                .get_class($e).' at '.basename($e->getFile()).':'.$e->getLine()."\n");
            exit(1);
        }
    }
}
echo "Sales own-scope Clients catalog, registry and workflow read smoke: PASS\n";
