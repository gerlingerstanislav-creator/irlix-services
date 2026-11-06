<?php

namespace App\Http\Controllers;

use App\Services\SpecialistsProjectDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class SpecialistsProjectDirectoryController extends Controller
{
    public function __construct(private readonly SpecialistsProjectDirectory $projects) {}

    /**
     * A signed Specialists service role is admission only. Employees verifies
     * current production-manager scope for this concrete employee on every read.
     */
    public function show(Request $request, int $employee): JsonResponse
    {
        $this->assertScopedEmployee($request, $employee);
        return response()->json(['data' => $this->projects->forEmployee($employee)]);
    }

    public function history(Request $request, int $employee): JsonResponse
    {
        $this->assertScopedEmployee($request, $employee);
        return response()->json(['data' => $this->projects->historyForEmployee($employee)]);
    }

    private function assertScopedEmployee(Request $request, int $employee): void
    {
        abort_if($request->header('X-Irlix-Role-Preview') !== null, 403, 'Role preview cannot authorize this directory.');

        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        try {
            $reply = Http::withToken((string) $token)->acceptJson()->timeout(8)
                ->get($base . '/specialists-directory');
        } catch (\Throwable) {
            abort(503, 'Employees specialist scope unavailable.');
        }
        if ($reply->status() === 403 || $reply->status() === 404) {
            abort(403, 'Specialists scope denied.');
        }
        if (!$reply->successful() || !is_array($reply->json('data.employees'))) {
            abort(503, 'Employees specialist scope unavailable.');
        }
        $visible = collect($reply->json('data.employees'))
            ->contains(static fn ($row) => (int) ($row['id'] ?? 0) === $employee);
        abort_unless($visible, 404, 'Specialist not in authorized scope.');

    }
}
