<?php

namespace App\Http\Middleware;

use App\Support\ClientContourAccess;
use App\Support\ProductionDirection;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class ClientsAccountingAccess
{
    public function __construct(private readonly ClientContourAccess $accessResolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health')) return $next($request);
        $access = $this->accessResolver->resolve($request);
        $request->attributes->set('client_contour_access', $access);
        if ($request->is('api/permissions*')) return $next($request);
        if ($access['platform_admin']) return $next($request);

        $path = $request->path();
        $method = $request->method();
        $logoRead = $method === 'GET' && preg_match('#^api/clients/\d+/logo$#', $path) === 1;
        if ($logoRead && !collect(['clients.view','requests.view','positions.view','attempts.view'])->contains(fn ($key) => $this->accessResolver->allows($access, $key))) return response()->json(['message' => 'Недостаточно прав.'], 403);
        $permission = match (true) {
            $logoRead => null,
            $path === 'api/cash-flow' => 'cashflow.view',
            str_starts_with($path, 'api/reporting-period') => $method === 'GET' ? 'reports.view' : 'reports.manage',
            str_starts_with($path, 'api/leads') => $method === 'GET' ? 'leads.view' : 'leads.manage',
            preg_match('#^api/requests/\d+/positions$#', $path) === 1 => 'positions.manage',
            str_starts_with($path, 'api/requests') => $method === 'GET' ? 'requests.view' : 'requests.manage',
            preg_match('#^api/positions/\d+/attempts$#', $path) === 1 => 'attempts.manage',
            str_starts_with($path, 'api/positions') => $method === 'GET' ? 'positions.view' : 'positions.manage',
            str_starts_with($path, 'api/attempts') => $method === 'GET' ? 'attempts.view' : 'attempts.manage',
            str_starts_with($path, 'api/contacts') => $method === 'GET' ? 'contacts.view' : 'contacts.manage',
            str_starts_with($path, 'api/members') || str_starts_with($path, 'api/terms') || str_starts_with($path, 'api/projects') => $method === 'GET' ? 'members.view' : 'members.manage',
            str_starts_with($path, 'api/clients') || str_starts_with($path, 'api/legal-entities') => $method === 'GET' ? 'clients.view' : 'clients.manage',
            default => null,
        };
        if ($permission && !$this->accessResolver->allows($access, $permission)) return response()->json(['message' => 'Недостаточно прав для этого действия.'], 403);
        $clientPagePermissions = ['clients.view', 'contacts.view', 'members.view', 'requests.view', 'positions.view', 'attempts.view', 'leads.view', 'reports.view', 'cashflow.view', 'permissions.view'];
        if ($path === 'api/overview' && !collect($clientPagePermissions)->contains(fn ($key) => $this->accessResolver->allows($access, $key))) {
            return response()->json(['message' => 'Нет доступа к страницам сервиса клиентов.'], 403);
        }

        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        $employee = Http::withToken((string) $token)->acceptJson()->timeout(8)->get($base.'/self');
        $roles = Http::withToken((string) $token)->acceptJson()->timeout(8)->get($base.'/access/me');
        if (!$employee->successful() || !$roles->successful()) return response()->json(['message' => 'Employees access is unavailable'], 503);
        $actor = $employee->json('data');
        if (!is_array($actor)) return response()->json(['message' => 'Employee profile is unavailable'], 403);
        $context = Http::withToken((string) $token)->acceptJson()->timeout(8)->get($base.'/self/absence-approval-context');
        if (!$context->successful()) return response()->json(['message' => 'Organization scope is unavailable'], 503);
        $accounting = collect($context->json('data.department_chain', []))
            ->contains(fn ($department) => mb_strtolower((string) ($department['name'] ?? '')) === 'accounting');
        $id = (int) $actor['id'];
        $directory = Http::withToken((string) $token)->acceptJson()->timeout(8)->get($base.'/clients-directory');
        if (!$directory->successful()) return response()->json(['message' => 'Employees directory is unavailable'], 503);
        $teamEmployeeIds = collect($directory->json('data.employees', []))
            ->filter(fn ($row) => in_array((int) ($row['department_id'] ?? 0), $access['department_ids'], true))
            ->pluck('id')->map(fn ($value) => (int) $value)->all();
        $employeeIdsForScope = function (string $scope) use ($id, $teamEmployeeIds): ?array {
            return match ($scope) { 'own' => [$id], 'team' => array_values(array_unique([...$teamEmployeeIds, $id])), 'all' => null, default => [] };
        };
        $accountScope = $this->accessResolver->scope($access, $this->accessResolver->allows($access, 'clients.manage') ? 'clients.manage' : 'clients.view');
        $funnelScope = 'none';
        foreach (['leads.view', 'requests.view', 'positions.view', 'attempts.view', 'leads.manage', 'requests.manage', 'positions.manage', 'attempts.manage'] as $key) {
            $candidate = $access['client_service_permissions'][$key]['scope'] ?? 'none';
            if (array_search($candidate, ['none', 'own', 'team', 'all'], true) > array_search($funnelScope, ['none', 'own', 'team', 'all'], true)) $funnelScope = $candidate;
        }
        $accountIds = $employeeIdsForScope($accountScope);
        $salesIds = $employeeIdsForScope($funnelScope);
        $accountClientsQuery = DB::table('clients');
        if ($accountIds !== null) $accountIds ? $accountClientsQuery->whereIn('account_employee_id', $accountIds) : $accountClientsQuery->whereRaw('1 = 0');
        $accountClients = $accountClientsQuery->pluck('id')->map(fn ($v) => (int) $v)->all();
        $funnelClientsQuery = DB::table('clients');
        if ($accountIds !== null || $salesIds !== null) {
            $funnelClientsQuery->where(function ($query) use ($accountIds, $salesIds): void {
                if ($accountIds) $query->orWhereIn('account_employee_id', $accountIds);
                if ($salesIds) $query->orWhereIn('sales_employee_id', $salesIds);
                if (!$accountIds && !$salesIds) $query->whereRaw('1 = 0');
            });
        }
        $funnelClients = $funnelClientsQuery->pluck('id')->map(fn ($v) => (int) $v)->all();
        $ownClients = array_values(array_unique([...$accountClients, ...$funnelClients]));
        $owns = fn (?int $clientId): bool => $clientId !== null && in_array($clientId, $ownClients, true);
        $ownsAccount = fn (?int $clientId): bool => $clientId !== null && in_array($clientId, $accountClients, true);
        $isProductionManager = in_array('department-manager', $access['roles'] ?? [], true);
        $productionDepartmentIds = array_map('intval', $access['production_department_ids'] ?? []);
        $clientFor = function (string $table, int $entityId) use ($id): ?int {
            return match ($table) {
                'projects', 'client_requests', 'reporting_periods', 'client_legal_entities', 'client_notes' => DB::table($table)->where('id', $entityId)->value('client_id'),
                'project_members' => DB::table('project_members as pm')->join('projects as p', 'p.id', '=', 'pm.project_id')->where('pm.id', $entityId)->value('p.client_id'),
                'member_terms' => DB::table('member_terms as mt')->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')->join('projects as p', 'p.id', '=', 'pm.project_id')->where('mt.id', $entityId)->value('p.client_id'),
                'positions' => DB::table('positions as p')->join('client_requests as r', 'r.id', '=', 'p.client_request_id')->where('p.id', $entityId)->value('r.client_id'),
                'connection_attempts' => DB::table('connection_attempts as a')->join('positions as p', 'p.id', '=', 'a.position_id')->join('client_requests as r', 'r.id', '=', 'p.client_request_id')->where('a.id', $entityId)->value('r.client_id'),
                'contact_relations' => DB::table('contact_relations')->where('id', $entityId)->where('entity_type', 'client')->value('entity_id'),
                default => null,
            };
        };
        $responsibleIds = $employeeIdsForScope($funnelScope);
        $ownsRequest = fn (int $requestId): bool => DB::table('client_requests')->where('id', $requestId)
            ->when($responsibleIds !== null, fn ($query) => $query->whereIn('responsible_employee_id', $responsibleIds ?: [0]))
            ->whereIn('client_id', $ownClients ?: [0])->exists();
        $ownsProductionPosition = function (int $positionId) use ($isProductionManager, $productionDepartmentIds, $access): bool {
            if (!$isProductionManager) return false;
            $position = DB::table('positions')->find($positionId);
            return $position && in_array(ProductionDirection::id((array) $position, $access), $productionDepartmentIds, true);
        };
        $positionForAttempt = fn (int $attemptId): ?int => DB::table('connection_attempts')->where('id', $attemptId)->value('position_id');
        $ownsPosition = fn (int $positionId): bool => $owns((int) $clientFor('positions', $positionId)) || $ownsProductionPosition($positionId);
        $ownsAttempt = fn (int $attemptId): bool => $owns((int) $clientFor('connection_attempts', $attemptId)) || $ownsProductionPosition((int) $positionForAttempt($attemptId));
        $ownsContact = fn (int $contactId): bool => DB::table('contact_relations')->where('contact_person_id', $contactId)->where('entity_type', 'client')->whereIn('entity_id', $ownClients)->exists();
        $allowed = true;

        if ($path === 'api/clients' && $method === 'POST') $allowed = $accountScope === 'all' || in_array((int) $request->input('account_employee_id'), $accountIds ?: [$id], true);
        elseif (preg_match('#^api/clients/(\d+)/logo$#', $path, $logoMatch)) {
            $logoClient = (int) $logoMatch[1];
            $allowed = $logoRead ? ($owns($logoClient) || ($isProductionManager && DB::table('positions as p')->join('client_requests as r', 'r.id', '=', 'p.client_request_id')->where('r.client_id', $logoClient)->pluck('p.id')->contains(fn ($positionId) => $ownsProductionPosition((int) $positionId)))) : $ownsAccount($logoClient);
        }
        elseif ($path === 'api/requests' && $method === 'POST') $allowed = $owns((int) $request->input('client_id'));
        elseif ($path === 'api/reporting-periods' && $method === 'POST') $allowed = $ownsAccount((int) $request->input('client_id'));
        elseif ($path === 'api/contacts' && $method === 'POST') {
            $relations = $request->input('client_relations', []);
            $allowed = is_array($relations) && count($relations) > 0 && collect($relations)->every(fn ($r) => is_array($r) && $ownsAccount((int) ($r['client_id'] ?? 0)));
        }
        elseif (in_array($path, ['api/leads'], true) || preg_match('#^api/leads/\d+(?:/convert)?$#', $path)) $allowed = false;
        elseif (preg_match('#^api/clients/(\d+)(?:/(?:card|legal-entities|notes|projects))?$#', $path, $m)) {
            $allowed = $ownsAccount((int) $m[1]) && !($method !== 'GET' && $request->has('account_employee_id') && $accountScope === 'own' && (int) $request->input('account_employee_id') !== $id);
        }
        elseif (preg_match('#^api/projects/(\d+)/members$#', $path, $m)) {
            $allowed = $ownsAccount((int) $clientFor('projects', (int) $m[1]));
            if ($request->filled('source_attempt_id')) {
                $allowed = $allowed && $owns((int) $clientFor('connection_attempts', (int) $request->input('source_attempt_id')))
                    && (int) $clientFor('connection_attempts', (int) $request->input('source_attempt_id')) === (int) $clientFor('projects', (int) $m[1]);
            }
        }
        elseif (preg_match('#^api/members/(\d+)(?:/(?:terms|feedbacks|project))?$#', $path, $m)) $allowed = $ownsAccount((int) $clientFor('project_members', (int) $m[1]));
        elseif (preg_match('#^api/terms/(\d+)$#', $path, $m)) $allowed = $ownsAccount((int) $clientFor('member_terms', (int) $m[1]));
        elseif (preg_match('#^api/requests/(\d+)(?:/positions)?$#', $path, $m)) $allowed = $ownsRequest((int) $m[1]);
        elseif (preg_match('#^api/positions/(\d+)(?:/attempts)?$#', $path, $m)) $allowed = $ownsPosition((int) $m[1]);
        elseif (preg_match('#^api/attempts/(\d+)(?:/(?:cv|send-cv|interviews(?:/\d+/complete)?|schedule-connection|close-success|close-failure))?$#', $path, $m)) $allowed = $ownsAttempt((int) $m[1]);
        elseif (preg_match('#^api/reporting-periods/(\d+)$#', $path, $m)) $allowed = $ownsAccount((int) $clientFor('reporting_periods', (int) $m[1]));
        elseif (preg_match('#^api/legal-entities/(\d+)$#', $path, $m)) $allowed = $ownsAccount((int) $clientFor('client_legal_entities', (int) $m[1]));
        elseif (preg_match('#^api/contacts/(\d+)(?:/(?:methods(?:/\d+)?|client-relations(?:/\d+)?|relations))?$#', $path, $m)) {
            $contactId = (int) $m[1];
            $allowed = $ownsContact($contactId);
            if ($method !== 'GET' && $allowed) {
                $allowed = !DB::table('contact_relations')->where('contact_person_id', $contactId)->where(fn ($q) => $q->where('entity_type', '!=', 'client')->orWhereNotIn('entity_id', $ownClients))->exists();
                if ($request->has('client_id')) $allowed = $allowed && $owns((int) $request->input('client_id'));
                if ($request->has('entity_type')) $allowed = $allowed && $request->input('entity_type') === 'client' && $owns((int) $request->input('entity_id'));
                if (preg_match('#/client-relations/(\d+)$#', $path, $relation)) $allowed = $allowed && $owns((int) $clientFor('contact_relations', (int) $relation[1]));
            }
        }
        elseif ($path === 'api/reporting-period-lock' || $path === 'api/absence-approvers') $allowed = !$request->has('client_id') || $owns((int) $request->query('client_id'));
        elseif (!in_array($path, ['api/overview', 'api/cash-flow'], true)) $allowed = false;
        if (!$allowed) return response()->json(['message' => 'Клиент или запрос вне вашей области доступа.'], 403);

        $response = $next($request);
        if (!$response instanceof JsonResponse || !$response->isSuccessful()) return $response;
        $payload = $response->getData(true);
        if ($path === 'api/overview') {
            $canViewRequests = $this->accessResolver->allows($access, 'requests.view');
            $canViewPositions = $this->accessResolver->allows($access, 'positions.view');
            $canViewAttempts = $this->accessResolver->allows($access, 'attempts.view');
            $visibleRequests = [];
            foreach ($payload['data']['requests'] ?? [] as $row) {
                $accountRequest = $canViewRequests
                    && ($responsibleIds === null || in_array((int) $row['responsible_employee_id'], $responsibleIds, true))
                    && $owns((int) $row['client_id']);
                if ($accountRequest) {
                    $visibleRequests[] = $row;
                    continue;
                }
                if (!$isProductionManager || (!$canViewPositions && !$canViewAttempts)) continue;
                $scopedPositions = array_values(array_filter(
                    $row['positions'] ?? [],
                    fn ($position) => in_array(ProductionDirection::id($position, $access), $productionDepartmentIds, true)
                ));
                if (!$canViewAttempts) {
                    $scopedPositions = array_map(fn ($position) => [...$position, 'attempts' => []], $scopedPositions);
                }
                if ($scopedPositions) {
                    $visibleRequests[] = [
                        'id' => $row['id'],
                        'client_id' => $row['client_id'],
                        'title' => $row['title'],
                        'description' => $row['description'] ?? null,
                        'responsible_employee_id' => $row['responsible_employee_id'] ?? null,
                        'deadline' => $row['deadline'] ?? null,
                        'lifetime_weeks' => $row['lifetime_weeks'] ?? null,
                        'status' => $row['status'],
                        'positions' => $scopedPositions,
                    ];
                }
            }
            $payload['data']['requests'] = $visibleRequests;
            $referencedClientIds = array_values(array_unique(array_map(fn ($row) => (int) $row['client_id'], $visibleRequests)));
            if ($this->accessResolver->allows($access, 'clients.view')) {
                $payload['data']['clients'] = array_values(array_filter($payload['data']['clients'] ?? [], fn ($row) => $ownsAccount((int) $row['id'])));
            } else {
                $contextClientIds = array_values(array_unique([...$ownClients, ...$referencedClientIds]));
                $payload['data']['clients'] = array_values(array_map(
                    fn ($row) => ['id' => $row['id'], 'name' => $row['name'], 'logo_url' => $row['logo_url'] ?? null, 'sales_employee_id' => $row['sales_employee_id'] ?? null, 'account_employee_id' => $row['account_employee_id'] ?? null],
                    array_filter($payload['data']['clients'] ?? [], fn ($row) => in_array((int) $row['id'], $contextClientIds, true))
                ));
            }
            $payload['data']['reportingPeriods'] = $this->accessResolver->allows($access, 'reports.view') ? array_values(array_filter($payload['data']['reportingPeriods'] ?? [], fn ($row) => $ownsAccount((int) $row['client_id']))) : [];
            if ($this->accessResolver->allows($access, 'contacts.view')) {
                $contacts = array_map(function ($row) use ($ownsAccount) {
                    $row['relations'] = array_values(array_filter($row['relations'] ?? [], fn ($relation) => $relation['entity_type'] === 'client' && $ownsAccount((int) $relation['entity_id'])));
                    return $row;
                }, $payload['data']['contacts'] ?? []);
                $payload['data']['contacts'] = array_values(array_filter($contacts, fn ($row) => count($row['relations']) > 0));
            } else {
                $payload['data']['contacts'] = [];
            }
            $payload['data']['leads'] = $this->accessResolver->allows($access, 'leads.view') ? array_values(array_filter($payload['data']['leads'] ?? [], fn ($row) => $responsibleIds === null || in_array((int) ($row['responsible_employee_id'] ?? 0), $responsibleIds, true))) : [];
        } elseif ($path === 'api/cash-flow') {
            $payload['data']['rows'] = array_values(array_filter($payload['data']['rows'] ?? [], fn ($row) => $ownsAccount((int) DB::table('project_members as pm')->join('projects as p', 'p.id', '=', 'pm.project_id')->where('pm.id', $row['project_member_id'])->value('p.client_id'))));
        } elseif (preg_match('#^api/contacts/\d+$#', $path)) {
            $payload['data']['client_relations'] = array_values(array_filter($payload['data']['client_relations'] ?? [], fn ($relation) => $ownsAccount((int) ($relation['client_id'] ?? 0))));
        }
        $response->setData($payload);
        return $response;
    }
}
