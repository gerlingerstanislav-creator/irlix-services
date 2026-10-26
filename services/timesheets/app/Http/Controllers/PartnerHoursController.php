<?php
namespace App\Http\Controllers;

use App\Services\PartnerHoursService;
use Illuminate\Http\Request;

final class PartnerHoursController
{
    public function calendar(Request $request, PartnerHoursService $service)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        return response()->json(['data' => $service->calendar($request, $data['month'])]);
    }

    public function write(Request $request, PartnerHoursService $service)
    {
        $data = $request->validate([
            'client_member_id' => ['required', 'integer', 'min:1'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);
        $service->write($request, $data);
        return response()->json(['data' => ['ok' => true]]);
    }

    public function confirm(Request $request, PartnerHoursService $service)
    {
        $data = $request->validate([
            'client_member_id' => ['required', 'integer', 'min:1'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'confirmed' => ['required', 'boolean'],
        ]);
        $service->confirm($request, $data);
        return response()->json(['data' => ['ok' => true]]);
    }

    public function finalApproval(Request $request, PartnerHoursService $service)
    {
        $data = $request->validate([
            'client_member_id' => ['required', 'integer', 'min:1'],
            'month' => ['required', 'date_format:Y-m'],
            'approved' => ['required', 'boolean'],
        ]);
        $service->finalApproval($request, $data);
        return response()->json(['data' => ['ok' => true]]);
    }
}
