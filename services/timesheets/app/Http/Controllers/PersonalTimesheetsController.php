<?php

namespace App\Http\Controllers;

use App\Services\PersonalTimesheetsService;
use App\Support\CurrentEmployee;
use Illuminate\Http\Request;

final class PersonalTimesheetsController
{
    public function workspace(Request $request, CurrentEmployee $actor, PersonalTimesheetsService $service)
    {
        return response()->json(['data' => $service->workspace($request, $actor->resolve($request))]);
    }

    public function write(Request $request, CurrentEmployee $actor, PersonalTimesheetsService $service)
    {
        $employee = $actor->resolve($request);
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'client_member_id' => ['required', 'integer', 'min:1'],
            'hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);
        $service->write($request, $employee, $data);
        return response()->json(['data' => ['ok' => true]]);
    }

    public function confirm(Request $request, CurrentEmployee $actor, PersonalTimesheetsService $service)
    {
        $employee = $actor->resolve($request);
        $data = $request->validate([
            'dates' => ['required', 'array', 'min:1', 'max:62'],
            'dates.*' => ['date_format:Y-m-d'],
        ]);
        return response()->json(['data' => $service->confirm($request, $employee, $data)]);
    }

    public function unconfirm(Request $request, CurrentEmployee $actor, PersonalTimesheetsService $service)
    {
        $employee = $actor->resolve($request);
        $data = $request->validate([
            'dates' => ['required', 'array', 'min:1', 'max:62'],
            'dates.*' => ['date_format:Y-m-d'],
        ]);
        $service->unconfirm($request, $employee, $data);
        return response()->json(['data' => ['ok' => true]]);
    }
}
