<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AssignmentsDirectory
{
    public function all(): array
    {
        $token = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
        if ($token === '') throw new RuntimeException('Timesheets integration token is not configured');
        $response = Http::withHeaders(['X-Irlix-Timesheets-Token' => $token])->acceptJson()->timeout(8)
            ->get(rtrim((string) env('CLIENTS_INTERNAL_URL', 'http://clients:8000/internal'), '/').'/timesheet-assignments');
        $payload = $response->json();
        if (!$response->successful() || !is_array($payload) || ($payload['complete'] ?? null) !== true
            || !is_array($payload['data'] ?? null) || !array_is_list($payload['data'])
            || ($payload['count'] ?? null) !== count($payload['data'])) {
            throw new RuntimeException('Complete Clients assignment directory is unavailable');
        }
        foreach ($payload['data'] as $row) {
            if (!is_array($row) || !is_int($row['employee_id'] ?? null) || $row['employee_id'] < 1
                || !is_int($row['project_id'] ?? null) || $row['project_id'] < 1
                || !is_int($row['client_id'] ?? null) || $row['client_id'] < 1
                || !$this->validDate($row['valid_from'] ?? null)
                || !array_key_exists('valid_to', $row)
                || ($row['valid_to'] !== null && (!$this->validDate($row['valid_to']) || $row['valid_to'] < $row['valid_from']))) {
                throw new RuntimeException('Clients assignment directory contains invalid rows');
            }
        }
        return $payload['data'];
    }

    private function validDate(mixed $date): bool
    {
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) return false;
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
