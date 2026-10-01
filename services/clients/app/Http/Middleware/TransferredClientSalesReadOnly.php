<?php

namespace App\Http\Middleware;

use App\Support\ClientContourAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TransferredClientSalesReadOnly
{
    public function __construct(private readonly ClientContourAccess $accessResolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health') || $request->is('api/permissions*')) return $next($request);

        $access = $this->accessResolver->resolve($request);
        $request->attributes->set('client_contour_access', $access);
        $roles = $access['roles'] ?? [];
        $salesSide = count(array_intersect(['sales-manager', 'sales-head'], $roles)) > 0;
        $accountSide = count(array_intersect(['account-manager', 'accounting-head', 'client-service-head'], $roles)) > 0;
        $actorId = (int) ($access['employee']['id'] ?? 0);
        $clientId = $this->clientIdForRequest($request);
        $client = $clientId ? DB::table('clients')->find($clientId) : null;
        $transferredSalesReadOnly = $client
            && $client->account_employee_id
            && $salesSide
            && !$accountSide
            && (int) $client->account_employee_id !== $actorId;

        if ($transferredSalesReadOnly && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json(['message' => 'После передачи клиента Sales сохраняет только просмотр. Изменения выполняет Account Manager.'], 403);
        }

        $response = $next($request);
        if (!$transferredSalesReadOnly || !$response instanceof JsonResponse || !$response->isSuccessful()) return $response;

        $payload = $response->getData(true);
        if ($request->path() === 'api/overview') {
            $payload['data']['clients'] = array_map(function ($row) use ($clientId) {
                if ((int) ($row['id'] ?? 0) === $clientId) $row['can_manage'] = false;
                return $row;
            }, $payload['data']['clients'] ?? []);
        } elseif (preg_match('#^api/clients/\d+/card$#', $request->path()) === 1 && isset($payload['data']['client'])) {
            $payload['data']['client']['can_manage'] = false;
        }
        $response->setData($payload);
        return $response;
    }

    private function clientIdForRequest(Request $request): ?int
    {
        $path = $request->path();
        if ($path === 'api/overview') return null;
        if ($request->filled('client_id')) return (int) $request->input('client_id');
        if ($request->query('client_id')) return (int) $request->query('client_id');

        if (preg_match('#^api/clients/(\d+)#', $path, $m)) return (int) $m[1];
        if (preg_match('#^api/projects/(\d+)#', $path, $m)) return $this->clientFrom('projects', (int) $m[1]);
        if (preg_match('#^api/members/(\d+)#', $path, $m)) {
            return DB::table('project_members as pm')->join('projects as p', 'p.id', '=', 'pm.project_id')->where('pm.id', (int) $m[1])->value('p.client_id');
        }
        if (preg_match('#^api/terms/(\d+)#', $path, $m)) {
            return DB::table('member_terms as mt')->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')->join('projects as p', 'p.id', '=', 'pm.project_id')->where('mt.id', (int) $m[1])->value('p.client_id');
        }
        if (preg_match('#^api/requests/(\d+)#', $path, $m)) return $this->clientFrom('client_requests', (int) $m[1]);
        if (preg_match('#^api/positions/(\d+)#', $path, $m)) {
            return DB::table('positions as p')->join('client_requests as r', 'r.id', '=', 'p.client_request_id')->where('p.id', (int) $m[1])->value('r.client_id');
        }
        if (preg_match('#^api/attempts/(\d+)#', $path, $m)) {
            return DB::table('connection_attempts as a')->join('positions as p', 'p.id', '=', 'a.position_id')->join('client_requests as r', 'r.id', '=', 'p.client_request_id')->where('a.id', (int) $m[1])->value('r.client_id');
        }
        if (preg_match('#^api/reporting-periods/(\d+)#', $path, $m)) return $this->clientFrom('reporting_periods', (int) $m[1]);
        if (preg_match('#^api/legal-entities/(\d+)#', $path, $m)) return $this->clientFrom('client_legal_entities', (int) $m[1]);

        return null;
    }

    private function clientFrom(string $table, int $id): ?int
    {
        $value = DB::table($table)->where('id', $id)->value('client_id');
        return $value ? (int) $value : null;
    }
}
