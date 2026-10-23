<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ClientMemberDirectory
{
    public function snapshot(): array
    {
        $secret = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
        if (strlen($secret) < 32) throw new RuntimeException('Clients membership catalog token not configured');
        $response = Http::withHeaders(['X-Irlix-Timesheets-Token' => $secret])->acceptJson()->timeout(8)
            ->get(rtrim((string) env('CLIENTS_INTERNAL_URL', 'http://clients:8000/internal'), '/').'/system/timesheet-memberships');
        if (!$response->successful()) throw new RuntimeException('Clients membership catalog unavailable');
        $json = $response->json();
        if (!is_array($json) || ($json['complete'] ?? false) !== true
            || !is_string($json['revision'] ?? null) || $json['revision'] === ''
            || !is_array($json['data'] ?? null) || !array_is_list($json['data'])
            || ($json['count'] ?? null) !== count($json['data'])
            || ($json['locked_reporting_periods_complete'] ?? false) !== true
            || !is_array($json['locked_reporting_periods'] ?? null) || !array_is_list($json['locked_reporting_periods'])
            || ($json['locked_reporting_periods_count'] ?? null) !== count($json['locked_reporting_periods'])) {
            throw new RuntimeException('Clients membership catalog is incomplete');
        }
        $members = []; $terms = []; $identities = [];
        foreach ($json['data'] as $row) {
            if (!is_array($row) || !$this->id($row['client_member_id'] ?? null) || !$this->id($row['client_id'] ?? null)
                || !array_key_exists('employee_id', $row) || !array_key_exists('partner_specialist_id', $row)
                || ($row['employee_id'] === null) === ($row['partner_specialist_id'] === null)
                || ($row['employee_id'] !== null && !$this->id($row['employee_id']))
                || ($row['partner_specialist_id'] !== null && !$this->id($row['partner_specialist_id']))
                || !is_string($row['specialist_name'] ?? null) || !is_string($row['client_name'] ?? null)
                || !array_key_exists('account_employee_id', $row)
                || ($row['account_employee_id'] !== null && !$this->id($row['account_employee_id']))
                || !is_array($row['delegated_account_employee_ids'] ?? null) || !array_is_list($row['delegated_account_employee_ids'])
                || count(array_filter($row['delegated_account_employee_ids'], fn ($id) => !$this->id($id)))
                || !array_key_exists('member_term_id', $row) || !array_key_exists('valid_from', $row) || !array_key_exists('valid_to', $row)
                || ($row['member_term_id'] === null
                    ? ($row['valid_from'] !== null || $row['valid_to'] !== null)
                    : (!$this->id($row['member_term_id']) || !$this->date($row['valid_from'])
                        || ($row['valid_to'] !== null && (!$this->date($row['valid_to']) || $row['valid_to'] < $row['valid_from']))))) {
                throw new RuntimeException('Invalid Clients membership identity or term');
            }
            $id = $row['client_member_id'];
            $identity = [$row['client_id'], $row['employee_id'], $row['partner_specialist_id']];
            if (isset($members[$id]) && $members[$id]['identity'] !== $identity) throw new RuntimeException('Inconsistent membership identity');
            $identityKey = $row['client_id'].':'.($row['employee_id'] !== null ? 'e:'.$row['employee_id'] : 'p:'.$row['partner_specialist_id']);
            if (isset($identities[$identityKey]) && $identities[$identityKey] !== $id) throw new RuntimeException('Duplicate membership identity');
            $identities[$identityKey] = $id;
            foreach ($members[$id]['rows'] ?? [] as $other) {
                if ($row['member_term_id'] === null || $other['member_term_id'] === null
                    || ($row['valid_from'] <= ($other['valid_to'] ?? '9999-12-31')
                        && $other['valid_from'] <= ($row['valid_to'] ?? '9999-12-31'))) {
                    throw new RuntimeException('Overlapping or duplicate membership terms');
                }
            }
            if ($row['member_term_id'] !== null) {
                if (isset($terms[$row['member_term_id']])) throw new RuntimeException('Duplicate member term');
                $terms[$row['member_term_id']] = true;
            }
            $members[$id]['identity'] = $identity;
            $members[$id]['rows'][] = $row;
        }
        $periodIds = [];
        foreach ($json['locked_reporting_periods'] as $period) {
            if (!is_array($period) || !$this->id($period['id'] ?? null) || isset($periodIds[$period['id']])
                || !$this->id($period['client_id'] ?? null) || !$this->date($period['period_start'] ?? null)
                || !$this->date($period['period_end'] ?? null) || $period['period_end'] < $period['period_start']
                || !in_array($period['status'] ?? null, ['ТШ на согласовании', 'ТШ согласованы', 'Акт на согласовании', 'Акт согласован', 'Счет оплачен'], true)) {
                throw new RuntimeException('Invalid Clients reporting period lock');
            }
            $periodIds[$period['id']] = true;
        }
        return ['members' => $json['data'], 'locked_periods' => $json['locked_reporting_periods'], 'revision' => $json['revision']];
    }

    private function id(mixed $id): bool { return is_int($id) && $id > 0; }
    private function date(mixed $date): bool
    {
        return is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
