<?php

namespace App\Integrations;

use App\Support\CommercialSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class TimesheetsCommercialClient
{
    public function range(Request $request, string $from, string $to): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $pending = Http::acceptJson()
            ->withHeaders(['X-Irlix-Timesheets-Token' => (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '')])
            ->connectTimeout(3)->timeout(10);
        if ($token) $pending = $pending->withToken((string) $token);
        $response = $pending->get(
            rtrim((string) env('TIMESHEETS_INTERNAL_URL', 'http://timesheets:8000/internal'), '/').'/commercial-data',
            ['from' => $from, 'to' => $to]
        );
        abort_unless($response->successful(), 503, 'Timesheets service is unavailable');
        return CommercialSnapshot::validate($response->json('data'), $from, $to, null);
    }
}
