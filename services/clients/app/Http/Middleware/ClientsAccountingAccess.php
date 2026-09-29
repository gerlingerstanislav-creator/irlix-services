<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class ClientsAccountingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health')) return $next($request);
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        $employee = Http::withToken((string) $token)->acceptJson()->timeout(8)->get($base.'/self');
        $roles = Http::withToken((string) $token)->acceptJson()->timeout(8)->get($base.'/access/me');
        if (!$employee->successful() || !$roles->successful()) return response()->json(['message' => 'Employees access is unavailable'], 503);
        $actor = $employee->json('data');
        if (!is_array($actor)) return response()->json(['message' => 'Employee profile is unavailable'], 403);
        if (in_array('platform-admin', (array) $roles->json('data.roles', []), true)) return $next($request);

        $context = Http::withToken((string) $token)->acceptJson()->timeout(8)->get($base.'/self/absence-approval-context');
        if (!$context->successful()) return response()->json(['message' => 'Organization scope is unavailable'], 503);
        $accounting = collect($context->json('data.department_chain', []))
            ->contains(fn ($department) => mb_strtolower((string) ($department['name'] ?? '')) === 'accounting');
        if (!$accounting) return $next($request); // Existing permissions for other departments are unchanged.

        $id = (int) $actor['id'];
        $ownClients = DB::table('clients')->where('account_employee_id', $id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $owns = fn (?int $clientId): bool => $clientId !== null && in_array($clientId, $ownClients, true);
        $clientFor = function (string $table, int $entityId) use ($id): ?int {
            return match ($table) {
                'projects', 'client_requests', 'reporting_periods', 'client_legal_entities', 'client_notes' => DB::table($table)->where('id', $entityId)->value('client_id'),
                'project_members' => DB::table('project_members as pm')->join('projects as p', 'p.id', '=', 'pm.project_id')->where('pm.id', $entityId)->value('p.client_id'),
                'member_terms' => DB::table('member_terms as mt')->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')->join('projects as p', 'p.id', '=', 'pm.project_id')->where('mt.id', $entityId)->value('p.client_id'),
                'positions' => DB::table('positions as p')->join('client_requests as r', 'r.id', '=', 'p.client_request_id')->where('p.id', $entityId)->where('r.responsible_employee_id', $id)->value('r.client_id'),
                'connection_attempts' => DB::table('connection_attempts as a')->join('positions as p', 'p.id', '=', 'a.position_id')->join('client_requests as r', 'r.id', '=', 'p.client_request_id')->where('a.id', $entityId)->where('r.responsible_employee_id', $id)->value('r.client_id'),
                'contact_relations' => DB::table('contact_relations')->where('id', $entityId)->where('entity_type', 'client')->value('entity_id'),
                default => null,
            };
        };
        $ownsRequest = fn (int $requestId): bool => DB::table('client_requests')->where('id', $requestId)->where('responsible_employee_id', $id)->whereIn('client_id', $ownClients)->exists();
        $ownsContact = fn (int $contactId): bool => DB::table('contact_relations')->where('contact_person_id', $contactId)->where('entity_type', 'client')->whereIn('entity_id', $ownClients)->exists();
        $path = $request->path();
        $method = $request->method();
        $allowed = true;

        if ($path === 'api/clients' && $method === 'POST') $allowed = (int) $request->input('account_employee_id') === $id;
        elseif ($path === 'api/requests' && $method === 'POST') $allowed = (int) $request->input('responsible_employee_id') === $id && $owns((int) $request->input('client_id'));
        elseif ($path === 'api/reporting-periods' && $method === 'POST') $allowed = $owns((int) $request->input('client_id'));
        elseif ($path === 'api/contacts' && $method === 'POST') {
            $relations = $request->input('client_relations', []);
            $allowed = is_array($relations) && count($relations) > 0 && collect($relations)->every(fn ($r) => is_array($r) && $owns((int) ($r['client_id'] ?? 0)));
        }
        elseif (in_array($path, ['api/leads'], true) || preg_match('#^api/leads/\d+(?:/convert)?$#', $path)) $allowed = false;
        elseif (preg_match('#^api/clients/(\d+)(?:/(?:card|legal-entities|notes|projects))?$#', $path, $m)) {
            $allowed = $owns((int) $m[1]) && !($method !== 'GET' && $request->has('account_employee_id') && (int) $request->input('account_employee_id') !== $id);
        }
        elseif (preg_match('#^api/projects/(\d+)/members$#', $path, $m)) {
            $allowed = $owns((int) $clientFor('projects', (int) $m[1]));
            if ($request->filled('source_attempt_id')) {
                $allowed = $allowed && $owns((int) $clientFor('connection_attempts', (int) $request->input('source_attempt_id')))
                    && (int) $clientFor('connection_attempts', (int) $request->input('source_attempt_id')) === (int) $clientFor('projects', (int) $m[1]);
            }
        }
        elseif (preg_match('#^api/members/(\d+)(?:/(?:terms|feedbacks|project))?$#', $path, $m)) $allowed = $owns((int) $clientFor('project_members', (int) $m[1]));
        elseif (preg_match('#^api/terms/(\d+)$#', $path, $m)) $allowed = $owns((int) $clientFor('member_terms', (int) $m[1]));
        elseif (preg_match('#^api/requests/(\d+)/positions$#', $path, $m)) $allowed = $ownsRequest((int) $m[1]);
        elseif (preg_match('#^api/requests/(\d+)$#', $path, $m)) $allowed = $ownsRequest((int) $m[1]);
        elseif (preg_match('#^api/positions/(\d+)$#', $path, $m)) $allowed = $owns((int) $clientFor('positions', (int) $m[1]));
        elseif (preg_match('#^api/positions/(\d+)/attempts$#', $path, $m)) $allowed = $owns((int) $clientFor('positions', (int) $m[1]));
        elseif (preg_match('#^api/attempts/(\d+)$#', $path, $m)) $allowed = $owns((int) $clientFor('connection_attempts', (int) $m[1]));
        elseif (preg_match('#^api/reporting-periods/(\d+)$#', $path, $m)) $allowed = $owns((int) $clientFor('reporting_periods', (int) $m[1]));
        elseif (preg_match('#^api/legal-entities/(\d+)$#', $path, $m)) $allowed = $owns((int) $clientFor('client_legal_entities', (int) $m[1]));
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
            $payload['data']['clients'] = array_values(array_filter($payload['data']['clients'] ?? [], fn ($row) => $owns((int) $row['id'])));
            $payload['data']['requests'] = array_values(array_filter($payload['data']['requests'] ?? [], fn ($row) => (int) $row['responsible_employee_id'] === $id && $owns((int) $row['client_id'])));
            $payload['data']['reportingPeriods'] = array_values(array_filter($payload['data']['reportingPeriods'] ?? [], fn ($row) => $owns((int) $row['client_id'])));
            $payload['data']['contacts'] = array_values(array_filter(array_map(function ($row) use ($owns) {
                $row['relations'] = array_values(array_filter($row['relations'] ?? [], fn ($relation) => $relation['entity_type'] === 'client' && $owns((int) $relation['entity_id'])));
                return $row;
            }, $payload['data']['contacts'] ?? []), fn ($row) => count($row['relations']) > 0));
            $payload['data']['leads'] = [];
        } elseif ($path === 'api/cash-flow') {
            $payload['data']['rows'] = array_values(array_filter($payload['data']['rows'] ?? [], fn ($row) => $owns((int) DB::table('project_members as pm')->join('projects as p', 'p.id', '=', 'pm.project_id')->where('pm.id', $row['project_member_id'])->value('p.client_id'))));
        } elseif (preg_match('#^api/contacts/\d+$#', $path)) {
            $payload['data']['client_relations'] = array_values(array_filter($payload['data']['client_relations'] ?? [], fn ($relation) => $owns((int) ($relation['client_id'] ?? 0))));
        }
        $response->setData($payload);
        return $response;
    }
}
