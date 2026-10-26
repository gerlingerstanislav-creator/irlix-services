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

        return response()->json(['data' => app(\App\Services\AttemptFunnelService::class)->report($data, $access)]);
    }

    private function access(Request $request): array
    {
        return (array) $request->attributes->get('client_contour_access', []);
    }
}
