<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EmployeePositionCatalog
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!str_starts_with($request->path(), 'api/employees')) {
            return $next($request);
        }

        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'DELETE'], true)) {
            return $next($request);
        }

        $current = null;
        if ($method === 'PATCH' && preg_match('~^api/employees/(?:employees/)?([0-9]+)$~', $request->path(), $matches)) {
            $current = DB::table('employees')->where('id', (int) $matches[1])->first();
            if ($current && $request->has('department_id') && !$request->has('position_id')
                && (string) ($request->input('department_id') ?? '') !== (string) ($current->department_id ?? '')) {
                $request->merge(['position_id' => null, 'position' => null]);
            }
        }

        if ($request->has('position') && !$request->has('position_id')) {
            return response()->json([
                'errors' => ['position_id' => ['Должность должна быть выбрана из штатного расписания.']],
            ], 422);
        }

        if (!$request->has('position_id')) {
            return $next($request);
        }

        $positionId = $request->input('position_id');
        if ($positionId === null || $positionId === '') {
            $request->merge(['position' => null]);
            return $next($request);
        }

        if (!ctype_digit((string) $positionId) || (int) $positionId < 1) {
            return response()->json([
                'errors' => ['position_id' => ['Некорректный идентификатор должности.']],
            ], 422);
        }

        $position = DB::table('staff_positions')->where('id', (int) $positionId)->first();
        if (!$position) {
            return response()->json([
                'errors' => ['position_id' => ['Выбранная должность отсутствует в штатном расписании.']],
            ], 422);
        }

        if ($position->closed_at !== null) {
            return response()->json([
                'errors' => ['position_id' => ['Выбранная должность закрыта и больше недоступна для новых назначений.']],
            ], 422);
        }

        $departmentId = $request->has('department_id') ? $request->input('department_id') : $current?->department_id;
        if (!$departmentId || (string) $position->direction_id !== (string) $departmentId) {
            return response()->json([
                'errors' => ['position_id' => ['Выберите должность выбранного подразделения.']],
            ], 422);
        }

        $request->merge(['position' => $position->name]);

        return $next($request);
    }
}
