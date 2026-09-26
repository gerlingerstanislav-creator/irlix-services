<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class EmployeesDirectory
{
    public function resolve(Request $request): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) throw new RuntimeException('Authentication token missing');
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        try { $response = Http::withToken($token)->acceptJson()->timeout(8)->get("{$base}/specialists-directory"); }
        catch (Throwable) { throw new RuntimeException('Employees service unavailable'); }
        if ($response->status() === 403) throw new DomainException('Specialists access denied');
        if ($response->status() === 404) throw new DomainException('Employee profile is not linked to this account');
        if (!$response->successful()) throw new RuntimeException('Employees directory lookup failed: '.$response->status());
        $data = $response->json('data');
        if (!is_array($data) || !is_array($data['employees'] ?? null)) throw new RuntimeException('Employees directory lookup returned invalid data');
        return $data;
    }
}
