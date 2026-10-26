<?php

/**
 * Isolated, real-network Clients ↔ Timesheets integration on migrated PostgreSQL.
 * Executed by the Clients PR rehearsal (disposable DB + service containers).
 */
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ClientMemberDirectory;
use App\Support\CommercialData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

function liveCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

liveCheck(DB::getDriverName() === 'pgsql', 'Real PostgreSQL required');
$base = rtrim((string)getenv('CLIENTS_INTERNAL_URL'), '/');
$token = (string)getenv('IRLIX_TIMESHEETS_INTEGRATION_TOKEN');
liveCheck(strlen($token) >= 32, 'Integration secret missing');

$unauthenticated = Http::timeout(8)->get($base.'/system/timesheet-memberships');
liveCheck($unauthenticated->status() === 403, 'Anonymous trusted directory leaked');
$wrongToken = Http::withHeaders(['X-Irlix-Timesheets-Token' => 'wrong'])
    ->timeout(8)->get($base.'/system/timesheet-memberships');
liveCheck($wrongToken->status() === 403, 'Wrong trusted token accepted');

$catalog = app(ClientMemberDirectory::class)->snapshot();
$employee = array_values(array_filter($catalog['members'], static fn($m) =>
    ($m['specialist_name'] ?? '') === 'Live PG Employee'));
$partner = array_values(array_filter($catalog['members'], static fn($m) =>
    ($m['specialist_name'] ?? '') === 'Live PG Partner'));
liveCheck(count($employee) === 1 && count($partner) === 1,
    'Timesheets did not consume both employee and partner without projects');
liveCheck($employee[0]['employee_id'] === 990011
    && $employee[0]['member_term_id'] !== null,
    'Employee membership or commercial term missing');
liveCheck($partner[0]['employee_id'] === null
    && $partner[0]['partner_specialist_id'] === 990022
    && $partner[0]['member_term_id'] === null,
    'Partner without project or term was dropped/falsely assigned an employee');
liveCheck(strlen($catalog['revision']) === 64, 'Catalog revision missing');

$entry = app(CommercialData::class)->read('2027-06-01', '2027-06-30', 990001);
liveCheck($entry['complete'] === true && $entry['schema_version'] === 2
    && $entry['entries'] === [] && strlen($entry['revision']) === 64,
    'Timesheets commercial data did not consume live Clients directory');

$original = getenv('CLIENTS_INTERNAL_URL');
putenv('CLIENTS_INTERNAL_URL=http://127.0.0.1:1/internal');
$failedClosed = false;
try {
    app(ClientMemberDirectory::class)->snapshot();
} catch (Throwable $error) {
    $failedClosed = true;
} finally {
    putenv('CLIENTS_INTERNAL_URL='.$original);
}
liveCheck($failedClosed, 'Missing Clients must fail closed, not synthesize stale scope');

echo "Live Clients-to-Timesheets PostgreSQL/HTTP catalog, partner, scope and outage: PASS\n";
