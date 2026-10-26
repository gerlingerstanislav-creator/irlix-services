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

    public function resolve(Request $request): array
    {
        $me = $this->actor->resolve($request);
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $response = Http::withToken($token)
            ->withHeaders($request->header('X-Irlix-Role-Preview')
                ? ['X-Irlix-Role-Preview' => $request->header('X-Irlix-Role-Preview')] : [])
            ->acceptJson()->timeout(5)
            ->get(rtrim((string) env('CLIENTS_URL', 'http://clients:8000/api'), '/').'/permissions/me');
        abort_unless($response->successful(), 503, 'Не удалось проверить доступ через Clients');
        $access = $response->json('data');
        abort_unless(is_array($access), 503, 'Некорректный ответ прав');
        $permission = $request->isMethod('GET')
            ? 'timesheets.management.view' : 'timesheets.management.manage';
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
