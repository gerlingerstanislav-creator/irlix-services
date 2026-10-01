<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AttemptFunnelController extends Controller
{
    private const STAGES = [
        ['key' => 'created', 'label' => 'Создано', 'rank' => 0],
        ['key' => 'cv_sent', 'label' => 'CV отправлено', 'rank' => 1],
        ['key' => 'interview_scheduled', 'label' => 'Интервью назначено', 'rank' => 2],
        ['key' => 'interview_completed', 'label' => 'Интервью пройдено', 'rank' => 3],
        ['key' => 'awaiting_connection', 'label' => 'Ожидает подключения', 'rank' => 4],
        ['key' => 'success', 'label' => 'Итог: успех', 'rank' => 5],
    ];

    private const GROUPS = ['direction', 'technology', 'client', 'responsible', 'level'];

    public function __invoke(Request $request)
    {
        $access = $this->access($request);
        abort_unless(
            ($access['platform_admin'] ?? false) || ($access['permissions']['attempts.analytics.view']['allowed'] ?? false),
            403,
            'Недостаточно прав для просмотра воронки попыток.'
        );

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'date_mode' => ['nullable', Rule::in(['created', 'closed'])],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'direction_department_id' => ['nullable', 'integer', 'min:1'],
            'responsible_employee_id' => ['nullable', 'integer', 'min:1'],
            'technology' => ['nullable', 'string', 'max:100'],
            'level' => ['nullable', 'string', 'max:100'],
            'specialist_type' => ['nullable', Rule::in(['all', 'internal', 'external'])],
            'group_by' => ['nullable', Rule::in(self::GROUPS)],
        ]);

        $today = CarbonImmutable::today();
        $from = CarbonImmutable::parse($data['date_from'] ?? $today->startOfMonth()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($data['date_to'] ?? $today->toDateString())->endOfDay();
        abort_if($to->lessThan($from), 422, 'Дата окончания не может быть раньше даты начала.');
        $dateMode = $data['date_mode'] ?? 'created';
        $groupBy = $data['group_by'] ?? 'direction';

        $query = DB::table('connection_attempts as a')
            ->join('positions as p', 'p.id', '=', 'a.position_id')
            ->join('client_requests as r', 'r.id', '=', 'p.client_request_id')
            ->leftJoin('clients as c', 'c.id', '=', 'r.client_id')
            ->leftJoin('leads as l', 'l.id', '=', 'r.lead_id')
            ->select([
                'a.id', 'a.status', 'a.is_external', 'a.specialist_id', 'a.responsible_employee_id as attempt_responsible_employee_id',
                'a.created_at', 'a.closed_at', 'a.cv_sent_at', 'a.connection_date', 'a.closed_from_status', 'a.failure_reasons',
                'p.direction_department_id', 'p.direction', 'p.technology', 'p.level', 'p.responsible_rn_employee_id',
                'r.id as request_id', 'r.responsible_employee_id', 'r.client_id', 'r.lead_id',
                'c.name as client_name', 'c.sales_employee_id', 'c.account_employee_id', 'l.name as lead_name',
            ])
            ->selectRaw('(select count(*) from attempt_interviews i where i.connection_attempt_id = a.id) as interview_count')
            ->selectRaw('(select count(*) from attempt_interviews i where i.connection_attempt_id = a.id and i.completed_at is not null) as completed_interview_count');

        if ($dateMode === 'closed') {
            $query->whereNotNull('a.closed_at')->whereBetween('a.closed_at', [$from, $to]);
        } else {
            $query->whereBetween('a.created_at', [$from, $to]);
        }

        $this->applyScope($query, $access);
        $baseRows = $query->get();
        $filterOptions = $this->filterOptions($baseRows);

        $rows = $baseRows->filter(function ($row) use ($data): bool {
            if (!empty($data['client_id']) && (int) $row->client_id !== (int) $data['client_id']) return false;
            if (!empty($data['direction_department_id']) && (int) $row->direction_department_id !== (int) $data['direction_department_id']) return false;
            if (!empty($data['responsible_employee_id']) && (int) $row->responsible_employee_id !== (int) $data['responsible_employee_id']) return false;
            if (!empty($data['technology']) && (string) $row->technology !== (string) $data['technology']) return false;
            if (!empty($data['level']) && (string) $row->level !== (string) $data['level']) return false;
            $type = $data['specialist_type'] ?? 'all';
            if ($type === 'internal' && (bool) $row->is_external) return false;
            if ($type === 'external' && !(bool) $row->is_external) return false;
            return true;
        })->values();

        $stats = $this->stats($rows);
        $scope = (string) ($access['permissions']['attempts.analytics.view']['scope'] ?? (($access['platform_admin'] ?? false) ? 'all' : 'none'));
        $roles = array_values($access['roles'] ?? []);
        $isHead = count(array_intersect(['sales-head', 'accounting-head', 'client-service-head'], $roles)) > 0;
        $isRegularClientManager = !$isHead && count(array_intersect(['sales-manager', 'account-manager'], $roles)) > 0;
        $isProductionManager = in_array('department-manager', $roles, true)
            && count(array_intersect(['sales-manager', 'account-manager', 'sales-head', 'accounting-head', 'client-service-head'], $roles)) === 0;
        $locks = [
            'direction' => $scope !== 'all' && ($isRegularClientManager || $isProductionManager),
            'responsible' => $scope !== 'all' && ($isRegularClientManager || $isProductionManager),
        ];

        return response()->json(['data' => [
            'filters' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'date_mode' => $dateMode,
                'group_by' => $groupBy,
                'locks' => $locks,
                'scope' => $scope,
            ],
            'summary' => $stats['summary'],
            'stages' => $stats['stages'],
            'failure_reasons_by_stage' => $stats['failure_reasons_by_stage'],
            'breakdown' => $this->breakdown($rows, $groupBy),
            'filter_options' => $filterOptions,
        ]]);
    }

    private function applyScope($query, array $access): void
    {
        if ($access['platform_admin'] ?? false) return;
        $scope = (string) ($access['permissions']['attempts.analytics.view']['scope'] ?? 'none');
        if ($scope === 'all') return;
        if ($scope === 'none') {
            $query->whereRaw('1 = 0');
            return;
        }

        $roles = array_values($access['roles'] ?? []);
        $actorId = (int) ($access['employee']['id'] ?? 0);
        $ids = $scope === 'team'
            ? array_values(array_unique(array_map('intval', [...($access['team_employee_ids'] ?? []), $actorId])))
            : [$actorId];
        $ids = array_values(array_filter($ids));
        if (!$ids) {
            $query->whereRaw('1 = 0');
            return;
        }

        $hasClientServiceRole = count(array_intersect(
            ['sales-manager', 'account-manager', 'sales-head', 'accounting-head', 'client-service-head'],
            $roles
        )) > 0;
        $isProductionManager = in_array('department-manager', $roles, true);

        if ($isProductionManager && !$hasClientServiceRole) {
            $specialistIds = $scope === 'team'
                ? array_values(array_unique(array_map('intval', $access['production_employee_ids'] ?? [])))
                : [$actorId];
            $query->whereIn('a.specialist_id', $specialistIds ?: [0]);
            return;
        }

        if ($hasClientServiceRole) {
            $canSeeSales = count(array_intersect(['sales-manager', 'sales-head', 'client-service-head'], $roles)) > 0;
            $canSeeAccount = count(array_intersect(['account-manager', 'accounting-head', 'client-service-head'], $roles)) > 0;
            $query->where(function ($nested) use ($ids, $canSeeSales, $canSeeAccount): void {
                if ($canSeeSales) $nested->orWhereIn('c.sales_employee_id', $ids);
                if ($canSeeAccount) $nested->orWhereIn('c.account_employee_id', $ids);
                $nested->orWhereIn('r.responsible_employee_id', $ids);
            });
            return;
        }

        $query->where(function ($nested) use ($ids): void {
            $nested->whereIn('a.specialist_id', $ids)
                ->orWhereIn('a.responsible_employee_id', $ids)
                ->orWhereIn('r.responsible_employee_id', $ids);
        });
    }

    private function filterOptions(Collection $rows): array
    {
        return [
            'clients' => $rows->filter(fn ($row) => $row->client_id)->map(fn ($row) => [
                'value' => (string) $row->client_id,
                'label' => (string) ($row->client_name ?: '#'.$row->client_id),
            ])->unique('value')->sortBy('label')->values()->all(),
            'directions' => $rows->filter(fn ($row) => $row->direction_department_id)->map(fn ($row) => [
                'value' => (string) $row->direction_department_id,
                'label' => (string) ($row->direction ?: '#'.$row->direction_department_id),
            ])->unique('value')->sortBy('label')->values()->all(),
            'responsibles' => $rows->pluck('responsible_employee_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all(),
            'technologies' => $rows->pluck('technology')->filter()->unique()->sort()->values()->map(fn ($value) => ['value' => $value, 'label' => $value])->all(),
            'levels' => $rows->pluck('level')->filter()->unique()->sort()->values()->map(fn ($value) => ['value' => $value, 'label' => $value])->all(),
        ];
    }

    private function stats(Collection $rows): array
    {
        $total = $rows->count();
        $success = $rows->where('status', 'Закрыт: успех')->count();
        $failed = $rows->where('status', 'Закрыт: неудача')->count();
        $inProgress = $total - $success - $failed;
        $failureStageCounts = [];
        $failureReasonsByStage = [];

        foreach ($rows->where('status', 'Закрыт: неудача') as $row) {
            $stage = $this->failureStage($row);
            $failureStageCounts[$stage] = ($failureStageCounts[$stage] ?? 0) + 1;
            $failureReasonsByStage[$stage] ??= [];
            foreach ($this->failureReasons($row) as $reason) {
                $failureReasonsByStage[$stage][$reason] = ($failureReasonsByStage[$stage][$reason] ?? 0) + 1;
            }
        }

        $stageRows = [];
        $previousCount = null;
        foreach (self::STAGES as $stage) {
            $count = $stage['key'] === 'success'
                ? $success
                : $rows->filter(fn ($row) => $this->rank($row) >= $stage['rank'])->count();
            $stageRows[] = [
                'key' => $stage['key'],
                'label' => $stage['label'],
                'count' => $count,
                'conversion_total' => $total ? round($count * 100 / $total, 1) : 0,
                'conversion_previous' => $previousCount === null ? 100 : ($previousCount ? round($count * 100 / $previousCount, 1) : 0),
                'failed_here' => $failureStageCounts[$stage['key']] ?? 0,
            ];
            $previousCount = $count;
        }

        $successDurations = $rows->where('status', 'Закрыт: успех')->filter(fn ($row) => $row->closed_at && $row->created_at)
            ->map(fn ($row) => CarbonImmutable::parse($row->created_at)->diffInSeconds(CarbonImmutable::parse($row->closed_at)) / 86400)
            ->sort()->values();

        $reasons = [];
        foreach ($failureReasonsByStage as $stage => $items) {
            arsort($items);
            $stageTotal = array_sum($items);
            $reasons[$stage] = collect($items)->map(fn ($count, $reason) => [
                'reason' => $reason,
                'count' => $count,
                'share' => $stageTotal ? round($count * 100 / $stageTotal, 1) : 0,
            ])->values()->all();
        }

        return [
            'summary' => [
                'total' => $total,
                'success' => $success,
                'failed' => $failed,
                'in_progress' => $inProgress,
                'success_rate' => $total ? round($success * 100 / $total, 1) : 0,
                'median_success_days' => $this->median($successDurations),
            ],
            'stages' => $stageRows,
            'failure_reasons_by_stage' => $reasons,
        ];
    }

    private function breakdown(Collection $rows, string $groupBy): array
    {
        return $rows->groupBy(fn ($row) => $this->groupKey($row, $groupBy))
            ->map(function (Collection $group, string $key) use ($groupBy): array {
                $stats = $this->stats($group);
                return [
                    'key' => $key,
                    'label' => $this->groupLabel($group->first(), $groupBy, $key),
                    'total' => $stats['summary']['total'],
                    'cv_sent' => $stats['stages'][1]['count'],
                    'interview_scheduled' => $stats['stages'][2]['count'],
                    'interview_completed' => $stats['stages'][3]['count'],
                    'awaiting_connection' => $stats['stages'][4]['count'],
                    'success' => $stats['summary']['success'],
                    'failed' => $stats['summary']['failed'],
                    'success_rate' => $stats['summary']['success_rate'],
                ];
            })
            ->sortByDesc('total')->values()->all();
    }

    private function groupKey(object $row, string $groupBy): string
    {
        return match ($groupBy) {
            'technology' => (string) ($row->technology ?: 'Без технологии'),
            'client' => $row->client_id ? 'client:'.$row->client_id : 'lead:'.($row->lead_id ?: 0),
            'responsible' => (string) ($row->responsible_employee_id ?: 0),
            'level' => (string) ($row->level ?: 'Без уровня'),
            default => (string) ($row->direction_department_id ?: 0),
        };
    }

    private function groupLabel(object $row, string $groupBy, string $key): string
    {
        return match ($groupBy) {
            'technology' => (string) ($row->technology ?: 'Без технологии'),
            'client' => (string) ($row->client_name ?: $row->lead_name ?: 'Без клиента / лида'),
            'responsible' => $key === '0' ? 'Без ответственного' : '#'.$key,
            'level' => (string) ($row->level ?: 'Без уровня'),
            default => (string) ($row->direction ?: 'Без направления'),
        };
    }

    private function rank(object $row): int
    {
        if ($row->status === 'Закрыт: успех') return 5;
        $status = $row->status === 'Закрыт: неудача' ? $row->closed_from_status : $row->status;
        $rank = match ($status) {
            'CV отправлено' => 1,
            'Интервью назначено' => 2,
            'Интервью пройдено' => 3,
            'Ожидает подключения' => 4,
            default => 0,
        };
        if ($row->cv_sent_at) $rank = max($rank, 1);
        if ((int) $row->interview_count > 0) $rank = max($rank, 2);
        if ((int) $row->completed_interview_count > 0) $rank = max($rank, 3);
        if ($row->connection_date) $rank = max($rank, 4);
        return $rank;
    }

    private function failureStage(object $row): string
    {
        return match ($this->rank($row)) {
            4 => 'awaiting_connection',
            3 => 'interview_completed',
            2 => 'interview_scheduled',
            1 => 'cv_sent',
            default => 'created',
        };
    }

    private function failureReasons(object $row): array
    {
        $decoded = json_decode((string) ($row->failure_reasons ?? '[]'), true);
        return is_array($decoded) && $decoded ? array_values(array_filter(array_map('strval', $decoded))) : ['Причина не указана'];
    }

    private function median(Collection $values): ?float
    {
        $count = $values->count();
        if (!$count) return null;
        $middle = intdiv($count, 2);
        $value = $count % 2
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
        return round($value, 1);
    }

    private function access(Request $request): array
    {
        return (array) $request->attributes->get('client_contour_access', []);
    }
}
