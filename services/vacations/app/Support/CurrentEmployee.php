<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class CurrentEmployee
{
    public function resolve(Request $request): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) throw new RuntimeException('Authentication token missing');

        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(5)->get("{$base}/self");
        } catch (Throwable) {
            throw new RuntimeException('Employees service unavailable');
        }

        if ($response->status() === 404) throw new DomainException('Employee profile is not linked to this account');
        if (!$response->successful()) throw new RuntimeException('Employees profile lookup failed: '.$response->status());

        $employee = $response->json('data');
        if (!is_array($employee) || empty($employee['id'])) throw new RuntimeException('Employees profile lookup returned invalid data');

        return $employee;
    }
}
