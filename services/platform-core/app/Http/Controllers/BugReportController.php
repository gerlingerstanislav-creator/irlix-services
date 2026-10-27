<?php

namespace App\Http\Controllers;

use App\Reports\BugReportService;
use App\Reports\ReporterIdentity;
use App\Resources\PlatformAdminAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class BugReportController extends Controller
{
    public function store(Request $request, ReporterIdentity $identity, BugReportService $service)
    {
        $reporter = $identity->resolve($request);
        if ($reporter instanceof \Illuminate\Http\JsonResponse) return $reporter;
        $data = $request->validate([
            'service_key' => ['required', 'string', 'max:64'],
            'page_path' => ['required', 'string', 'max:2048', 'regex:/^\\//'],
            'description' => ['required', 'string', 'max:10000', function ($attribute, $value, $fail) { if (trim($value) === '') $fail('Введите описание'); }],
        ]);
        return response()->json(['data' => $service->create($data, $reporter)], 201)->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, PlatformAdminAuthorizer $auth, BugReportService $service)
    {
        if ($denied = $auth->authorize($request)) return $denied;
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,closed'],
            'service_key' => ['nullable','string','max:64'],
            'employee_id' => ['nullable','integer','min:1'],
            'from' => ['nullable','date_format:Y-m-d'],
            'to' => ['nullable','date_format:Y-m-d'],
        ]);
        return response()->json($service->paginate($filters))->header('Cache-Control','no-store');
    }

    public function updateStatus(Request $request, PlatformAdminAuthorizer $auth, ReporterIdentity $identity, BugReportService $service)
    {
        if ($denied = $auth->authorize($request)) return $denied;
        $reporter = $identity->resolve($request);
        if ($reporter instanceof \Illuminate\Http\JsonResponse) return $reporter;
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'status' => ['required', 'in:open,closed'],
        ]);
        return response()->json(['updated' => $service->changeStatus($data['ids'], $data['status'], $reporter['employee_id'])])->header('Cache-Control','no-store');
    }

    public function export(Request $request, PlatformAdminAuthorizer $auth, BugReportService $service)
    {
        if ($denied = $auth->authorize($request)) return $denied;
        $filters = $request->validate(['status' => ['nullable','in:open,closed']]);
        return response($service->export($filters), 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="irlix-bug-reports.md"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
