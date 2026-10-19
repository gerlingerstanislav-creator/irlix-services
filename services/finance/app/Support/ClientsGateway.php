<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ClientsGateway
{
    private static function request(string $method, int $period, array $data=[]): array
    {
        $token = (string) env('IRLIX_FINANCE_CLIENTS_TOKEN', '');
        if (strlen($token) < 32) throw new HttpException(503, 'Finance/Clients integration secret is not configured');
        $url = rtrim((string) env('CLIENTS_INTERNAL_URL', 'http://clients:8000/internal/system'), '/')
            .'/finance/reporting-periods/'.$period.($method === 'POST' ? '/paid' : '');
        try {
            $client = Http::withHeaders(['X-Irlix-Finance-Token' => $token])->acceptJson()->timeout(8);
            $response = $method === 'POST' ? $client->post($url, $data) : $client->get($url);
        } catch (ConnectionException $e) {
            throw new HttpException(503, 'Clients is unavailable');
        }
        if (!$response->successful()) {
            throw new HttpException($response->status() === 404 ? 422 : 503, 'Finance/Clients contract rejected request');
        }
        return (array) $response->json('data', []);
    }

    public static function period(int $period, int $clientId, bool $requireAct=false): array
    {
        $source = self::request('GET', $period);
        abort_unless((int) ($source['client_id'] ?? 0) === $clientId, 422, 'Incorrect client reporting period');
        abort_if(($source['status'] ?? null) === 'Счет оплачен', 423, 'Paid client period is immutable');
        if ($requireAct) abort_unless(($source['status'] ?? null) === 'Акт согласован', 409, 'Act is not agreed');
        return $source;
    }

    public static function confirmPaid(int $period, array $claim): array
    {
        return self::request('POST', $period, $claim);
    }
}
