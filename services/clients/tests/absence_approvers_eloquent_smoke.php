<?php

// Synthetic SQLite regression for the Clients -> Vacations AM approval boundary.
require __DIR__.'/client_members_bootstrap.php';

use App\Http\Controllers\AbsenceApproversController;
use App\Services\AbsenceApproversService;
use Illuminate\Support\Facades\DB;

clientSchemaLegacy();
clientExpand();

DB::table('clients')->insert([
    ['id' => 101, 'name' => 'Client A', 'account_employee_id' => 51],
    ['id' => 102, 'name' => 'Client B', 'account_employee_id' => 52],
    ['id' => 103, 'name' => 'Client C', 'account_employee_id' => 51],
    ['id' => 104, 'name' => 'Client D', 'account_employee_id' => null],
]);
DB::table('client_members')->insert([
    ['id' => 201, 'client_id' => 101, 'employee_id' => 7, 'specialist_name' => 'Synthetic'],
    ['id' => 202, 'client_id' => 102, 'employee_id' => 7, 'specialist_name' => 'Synthetic'],
    ['id' => 203, 'client_id' => 103, 'employee_id' => 8, 'specialist_name' => 'Other'],
    ['id' => 204, 'client_id' => 104, 'employee_id' => 7, 'specialist_name' => 'Synthetic'],
]);
DB::table('member_terms')->insert([
    ['id' => 301, 'client_member_id' => 201, 'project_member_id' => null, 'technology' => 'PHP',
        'level' => 'Middle', 'hours_per_day' => 8, 'valid_from' => '2026-01-01', 'valid_to' => '2026-02-28'],
    ['id' => 302, 'client_member_id' => 201, 'project_member_id' => null, 'technology' => 'PHP',
        'level' => 'Middle', 'hours_per_day' => 8, 'valid_from' => '2026-03-01', 'valid_to' => null],
    ['id' => 303, 'client_member_id' => 202, 'project_member_id' => null, 'technology' => 'PHP',
        'level' => 'Middle', 'hours_per_day' => 8, 'valid_from' => '2026-02-01', 'valid_to' => '2026-08-01'],
    ['id' => 304, 'client_member_id' => 203, 'project_member_id' => null, 'technology' => 'PHP',
        'level' => 'Middle', 'hours_per_day' => 8, 'valid_from' => '2026-01-01', 'valid_to' => null],
    ['id' => 305, 'client_member_id' => 204, 'project_member_id' => null, 'technology' => 'PHP',
        'level' => 'Middle', 'hours_per_day' => 8, 'valid_from' => '2026-01-01', 'valid_to' => null],
]);

$service = new AbsenceApproversService();
$result = $service->forEmployee(7, '2026-02-20', '2026-03-10');
clientCheck($result['account_manager_ids'] === [51, 52], 'Unique account managers are returned');
clientCheck($result['connection_count'] === 3, 'Canonical memberships with no project remain valid');
$past = $service->forEmployee(7, '2025-01-01', '2025-01-31');
clientCheck($past === ['account_manager_ids' => [], 'connection_count' => 0], 'Expired/non-overlapping terms excluded');
$other = $service->forEmployee(8, '2026-03-01', '2026-03-10');
clientCheck($other === ['account_manager_ids' => [51], 'connection_count' => 1], 'Employee identity is isolated');

$controller = new AbsenceApproversController($service);
$response = $controller(clientRequest([
    'employee_id' => 7, 'from' => '2026-02-20', 'to' => '2026-03-10',
]));
clientCheck($response->getStatusCode() === 200, 'HTTP contract stays 200');
clientCheck(json_decode($response->getContent(), true)['data'] === $result, 'HTTP JSON contract stays stable');
clientReject(static fn () => $controller(clientRequest([
    'employee_id' => 7, 'from' => '2026-03-11', 'to' => '2026-03-10',
])), 422, 'Validation must reject inverted interval');

echo "Vacations AM approvers via canonical ClientMember without projects PASS\n";
