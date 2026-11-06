<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAccountDelegation;
use App\Support\ProductionDirection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Clients workflow ownership is independent of the Client card permission.
 * A request's responsible employee retains access after a client is reassigned.
 * Each view/manage permission keeps its own scope; broader sibling grants
 * must never turn an own-scoped permission into all.
 */
final class ClientWorkflowVisibility
{
    public function scopeFor(array $access, string $permission): array
    {
        $grant = (array) ($access['permissions'][$permission] ?? []);
        if (!($access['platform_admin'] ?? false) && !($grant['allowed'] ?? false)) {
            return ['allowed' => false, 'employees' => [], 'clients' => []];
        }

        $scope = ($access['platform_admin'] ?? false) ? 'all' : (string) ($grant['scope'] ?? 'none');
        if ($scope === 'all') return ['allowed' => true, 'employees' => null, 'clients' => null];

        $actorId = (int) ($access['employee']['id'] ?? 0);
        $ids = match ($scope) {
            'own' => [$actorId],
            'team' => [...(array) ($access['team_employee_ids'] ?? []), $actorId],
            default => [],
        };
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));
        if ($ids === []) return ['allowed' => false, 'employees' => [], 'clients' => []];

        $owned = Client::query()
            ->where(fn (Builder $query) => $query->whereIn('sales_employee_id', $ids)
                ->orWhereIn('account_employee_id', $ids))
            ->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $delegated = ClientAccountDelegation::query()
            ->whereIn('to_employee_id', $ids)
            ->whereNull('revoked_at')
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today())
            ->pluck('client_id')->map(static fn ($id) => (int) $id)->all();

        return ['allowed' => true, 'employees' => $ids, 'clients' => array_values(array_unique([...$owned, ...$delegated]))];
    }

    public function viewScopes(array $access): array
    {
        return [
            'requests.view' => $this->scopeFor($access, 'requests.view'),
            'positions.view' => $this->scopeFor($access, 'positions.view'),
            'attempts.view' => $this->scopeFor($access, 'attempts.view'),
        ];
    }

    public static function requestAllowed(array|object $request, array $scope): bool
    {
        if (!($scope['allowed'] ?? false)) return false;
        if (($scope['employees'] ?? []) === null) return true;
        $row = (array) $request;
        return in_array((int) ($row['responsible_employee_id'] ?? 0), $scope['employees'], true)
            || ((int) ($row['client_id'] ?? 0) > 0
                && in_array((int) $row['client_id'], $scope['clients'] ?? [], true));
    }

    private static function productionOnly(array $access): bool
    {
        return in_array('department-manager', $access['roles'] ?? [], true)
            && !array_intersect(
                ['account-manager', 'sales-manager', 'accounting-head', 'sales-head', 'client-service-head'],
                $access['roles'] ?? []
            );
    }

    private static function productionPositionAllowed(array|object $position, array $access): bool
    {
        if (!in_array('department-manager', $access['roles'] ?? [], true)) return false;
        $departmentIds = array_map('intval', $access['production_department_ids'] ?? []);
        return $departmentIds !== []
            && in_array(ProductionDirection::id((array) $position, $access), $departmentIds, true);
    }

    public static function positionAllowed(array|object $position, array|object $request, array $scope, array $access): bool
    {
        if (!($scope['allowed'] ?? false)) return false;
        return (!self::productionOnly($access) && self::requestAllowed($request, $scope))
            || self::productionPositionAllowed($position, $access);
    }

    /**
     * Apply the SAME request/position/attempt visibility before LIMIT/OFFSET.
     * Otherwise an empty first page of inaccessible requests hides later
     * migrated requests even though they belong to the signed-in Sales user.
     */
    public function restrictRequestQuery(Builder $query, array $access, array $scopes): void
    {
        if ($access['platform_admin'] ?? false) return;

        $query->where(function (Builder $visible) use ($access, $scopes): void {
            foreach ($scopes as $permission => $scope) {
                if (!($scope['allowed'] ?? false)) continue;
                if ($permission !== 'requests.view' && self::productionOnly($access)) continue;
                if ($scope['employees'] === null) {
                    $visible->orWhereRaw('1 = 1');
                    continue;
                }
                if ($scope['employees']) $visible->orWhereIn('client_requests.responsible_employee_id', $scope['employees']);
                if ($scope['clients']) $visible->orWhereIn('client_requests.client_id', $scope['clients']);
            }

            if (self::productionOnly($access)
                && (($scopes['positions.view']['allowed'] ?? false) || ($scopes['attempts.view']['allowed'] ?? false))) {
                $departments = array_map('intval', $access['production_department_ids'] ?? []);
                $names = [];
                foreach ($access['legacy_direction_ids'] ?? [] as $name => $departmentId) {
                    if (in_array((int) $departmentId, $departments, true)) $names[] = $name;
                }
                if ($departments) {
                    $visible->orWhereExists(function ($positions) use ($departments, $names): void {
                        $positions->selectRaw('1')->from('positions as visible_position')
                            ->whereColumn('visible_position.client_request_id', 'client_requests.id')
                            ->where(function ($match) use ($departments, $names): void {
                                $match->whereIn('visible_position.direction_department_id', $departments);
                                if ($names) $match->orWhere(fn ($legacy) => $legacy
                                    ->whereNull('visible_position.direction_department_id')
                                    ->whereIn('visible_position.direction', $names));
                            });
                    });
                }
            }
            // No matching grant leaves this grouped predicate false.
            if (!array_filter($scopes, static fn ($scope) => (bool) ($scope['allowed'] ?? false))) {
                $visible->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Preserve only minimal parent context for position-only or attempt-only
     * users, and never embed attempts when attempts.view is denied.
     */
    public function projectRequests(array $requests, array $access, array $scopes): array
    {
        if ($access['platform_admin'] ?? false) return $requests;
        $visible = [];
        foreach ($requests as $request) {
            $canReadRequest = self::requestAllowed($request, $scopes['requests.view']);
            $positions = [];
            foreach ($request['positions'] ?? [] as $position) {
                $canReadPosition = self::positionAllowed($position, $request, $scopes['positions.view'], $access);
                $canReadAttempts = self::positionAllowed($position, $request, $scopes['attempts.view'], $access);
                if (!$canReadPosition && !$canReadAttempts) continue;
                if (!$canReadPosition) {
                    $position = array_intersect_key($position, array_flip([
                        'id', 'client_request_id', 'direction', 'direction_department_id',
                        'technology', 'level', 'status', 'display_status', 'quantity',
                        'created_at', 'responsible_rn_employee_id', 'attempts',
                    ]));
                }
                $position['attempts'] = $canReadAttempts ? ($position['attempts'] ?? []) : [];
                $positions[] = $position;
            }
            if (!$canReadRequest && !$positions) continue;
            if (!$canReadRequest) {
                $request = array_intersect_key($request, array_flip([
                    'id', 'client_id', 'lead_id', 'title', 'description',
                    'responsible_employee_id', 'deadline', 'lifetime_weeks',
                    'status', 'display_status', 'positions',
                ]));
            }
            $request['positions'] = $positions;
            $visible[] = $request;
        }
        return $visible;
    }
}
