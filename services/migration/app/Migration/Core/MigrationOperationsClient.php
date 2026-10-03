<?php

namespace App\Migration\Core;

final class MigrationOperationsClient
{
    public function request(string $method, string $path, array $body = []): array
    {
        $socket = @stream_socket_client('unix:///ops/migration-ops.sock', $errno, $error, 3);
        if (! is_resource($socket)) {
            throw new \RuntimeException('Служба снимков недоступна. Проверьте migration-ops.');
        }

        stream_set_timeout($socket, 5);
        $json = json_encode((object) $body, JSON_THROW_ON_ERROR);
        $request = "{$method} {$path} HTTP/1.0\r\nHost: migration-ops\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\nConnection: close\r\n\r\n".$json;
        try {
            $sent = 0;
            while ($sent < strlen($request)) {
                $written = fwrite($socket, substr($request, $sent));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Служба снимков не приняла запрос.');
                }
                $sent += $written;
            }
            $response = stream_get_contents($socket, 131072);
        } finally {
            fclose($socket);
        }
        if (! is_string($response) || ! str_contains($response, "\r\n\r\n")) {
            throw new \RuntimeException('Нет ответа от службы снимков.');
        }
        [$headers, $payload] = explode("\r\n\r\n", $response, 2);
        if (! preg_match('/^HTTP\/1\.[01] (\d{3})/', $headers, $match)) {
            throw new \RuntimeException('Некорректный ответ службы снимков.');
        }
        return [(int) $match[1], json_decode($payload, true, 512, JSON_THROW_ON_ERROR)];
    }
}
