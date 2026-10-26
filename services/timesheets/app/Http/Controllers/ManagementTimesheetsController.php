<?php

namespace App\Http\Controllers;

use App\Services\ManagementTimesheetsService;
use App\Support\CurrentEmployee;
use Illuminate\Http\Request;

final class ManagementTimesheetsController
{
    public function month(Request $request, CurrentEmployee $actor, ManagementTimesheetsService $service)
    {
        return response()->json(['data' => $service->month($request, $actor->resolve($request))]);
    }

    public function write(Request $request, CurrentEmployee $actor, ManagementTimesheetsService $service)
    {
        $current = $actor->resolve($request);
        $authorized = $service->authorized($request, $current, true);
        $baseRules = [
            'employee_id' => ['required', 'integer', 'min:1'],
            'work_date' => ['required', 'date_format:Y-m-d'],
        ];
        if ($request->has('entries')) {
            $data = $request->validate([...$baseRules,
                'entries' => ['required', 'array', 'min:1', 'max:100'],
                'entries.*.client_member_id' => ['required', 'integer', 'min:1', 'distinct'],
                'entries.*.hours' => ['required', 'numeric', 'min:0', 'max:24'],
                'entries.*.description' => ['nullable', 'string', 'max:4000'],
            ]);
            $rows = $data['entries'];
        } else {
            $data = $request->validate([...$baseRules,
                'client_member_id' => ['required', 'integer', 'min:1'],
                'hours' => ['required', 'numeric', 'min:0', 'max:24'],
                'description' => ['nullable', 'string', 'max:4000'],
            ]);
            $rows = [$data];
        }
        return response()->json(['data' => $service->write($request, $current, $authorized, $data, $rows)]);
    }

    public function bulkHours(Request $request, CurrentEmployee $actor, ManagementTimesheetsService $service)
    {
        $current = $actor->resolve($request);
        $authorized = $service->authorized($request, $current, true);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'client_member_id' => ['required', 'integer', 'min:1'],
            'dates' => ['required', 'array', 'min:1', 'max:31'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'hours' => ['required', 'numeric', 'min:0', 'max:24'],
        ]);
        return response()->json(['data' => $service->bulkHours($request, $current, $authorized, $data)]);
    }

    public function finalApproval(Request $request, CurrentEmployee $actor, ManagementTimesheetsService $service)
    {
        $current = $actor->resolve($request);
        $authorized = $service->authorized($request, $current, true);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'client_member_id' => ['required', 'integer', 'min:1'],
            'month' => ['required', 'date_format:Y-m'],
            'approved' => ['required', 'boolean'],
        ]);
        return response()->json(['data' => $service->finalApproval($request, $current, $authorized, $data)]);
    }
}
