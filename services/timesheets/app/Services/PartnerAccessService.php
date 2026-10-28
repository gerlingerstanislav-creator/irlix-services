<?php
namespace App\Services;

use App\Support\ClientMemberDirectory;
use App\Support\CurrentEmployee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** Client-owned grants and ClientMember ownership, never inferred from local facts. */
final class PartnerAccessService
{
    public function __construct(
        private readonly ClientMemberDirectory $directory,
        private readonly CurrentEmployee $actor,
    ) {}

    /**
     * Match exact partner endpoints and method, never use a broad default
     * grant that could turn a new sensitive operation into write access.
     */
    public static function permissionFor(string $method, string $path, bool $local): ?string
    {
        if (!$local) {
            return $method === 'GET' ? 'timesheets.management.view' : 'timesheets.management.manage';
        }
        return match (true) {
            $method === 'GET' && $path === 'api/partner-hours' => 'timesheets.partner-hours.view',
            $method === 'PUT' && $path === 'api/partner-hours' => 'timesheets.partner-hours.manage',
            $method === 'POST' && $path === 'api/partner-hours/confirm' => 'timesheets.partner-hours.manage',
            $method === 'POST' && $path === 'api/partner-hours/final-approval' => 'timesheets.partner-hours.approve',
            default => null,
        };
    }

    public function resolve(Request $request): array
    {
        $me = $this->actor->resolve($request);
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $local = filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if ($local) {
            // ServiceEntitlement checked the signed JWT and current
            // organizational assignments. Never consult Clients for a
            // business permission after Timesheets is switched to local V2.
            $localPermissions = $request->attributes->get('timesheets_local_permissions');
            abort_unless(is_array($localPermissions), 503, 'Timesheets permissions were not verified');
            $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
            $access = [
                'permissions' => $localPermissions,
                'platform_admin' => in_array('platform-admin', $roles, true)
                    || in_array('special:platform-admin', $roles, true),
            ];
            $permission = self::permissionFor($request->method(), $request->path(), true);
            abort_unless($permission !== null, 403, 'Partner-hours operation is not authorized');
        } else {
            $response = Http::withToken($token)
                ->withHeaders($request->header('X-Irlix-Role-Preview')
                    ? ['X-Irlix-Role-Preview' => $request->header('X-Irlix-Role-Preview')] : [])
                ->acceptJson()->timeout(5)
                ->get(rtrim((string) env('CLIENTS_URL', 'http://clients:8000/api'), '/').'/permissions/me');
            abort_unless($response->successful(), 503, 'Не удалось проверить доступ через Clients');
            $access = $response->json('data');
            abort_unless(is_array($access), 503, 'Некорректный ответ прав');
            $permission = self::permissionFor($request->method(), $request->path(), false);
        }
        $grant = $access['permissions'][$permission] ?? [];
        abort_unless(($access['platform_admin'] ?? false) || ($grant['allowed'] ?? false),
            403, 'Недостаточно прав на ТШ партнёров');
        try {
            $snapshot = $this->directory->snapshot();
        } catch (\Throwable) {
            abort(503, 'Каталог подключений Clients недоступен или неполон.');
        }
        $actorId = (int) $me['id'];
        $scope = (string) ($grant['scope'] ?? 'none');
        $rows = array_values(array_filter($snapshot['members'], static fn ($member) =>
            $member['partner_specialist_id'] !== null &&
            (($access['platform_admin'] ?? false) || $scope === 'all'
                || (((int) ($member['account_employee_id'] ?? 0) === $actorId
                    || in_array($actorId, $member['delegated_account_employee_ids'] ?? [], true))
                    && in_array($scope, ['own', 'team'], true)))
        ));
        return [$me, $rows, $snapshot['locked_periods'], $snapshot['members']];
    }
}
