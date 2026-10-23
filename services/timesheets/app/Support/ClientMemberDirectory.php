<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ClientMemberDirectory
{
    public function snapshot(): array
    {
        $secret=(string)env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN','');
        if (strlen($secret)<32) throw new RuntimeException('Clients membership catalog token not configured');
        $response=Http::withHeaders(['X-Irlix-Timesheets-Token'=>$secret])
            ->acceptJson()->timeout(8)->get(rtrim((string)env('CLIENTS_INTERNAL_URL','http://clients:8000/internal'),'/').'/timesheet-memberships');
        if (!$response->successful()) throw new RuntimeException('Clients membership catalog unavailable');
        $json=$response->json();
        if (!is_array($json)||($json['complete']??false)!==true
            || !is_array($json['data']??null)||!array_is_list($json['data'])
            || ($json['count']??null)!==count($json['data'])
            || ($json['locked_reporting_periods_complete']??false)!==true
            || !is_array($json['locked_reporting_periods']??null)
            || !array_is_list($json['locked_reporting_periods'])
            || ($json['locked_reporting_periods_count']??null)!==count($json['locked_reporting_periods']))
            throw new RuntimeException('Clients membership catalog is incomplete');
        foreach($json['data'] as $row){
            if(!is_array($row) || !is_int($row['client_member_id']??null) || !is_int($row['client_id']??null)
                || ($row['client_member_id']??0)<1||($row['client_id']??0)<1
                || (($row['employee_id']??null)===null) === (($row['partner_specialist_id']??null)===null)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)($row['valid_from']??''))
                || (!is_null($row['valid_to']??null)&&($row['valid_to']<$row['valid_from']))) {
                throw new RuntimeException('Invalid Clients membership identity or term');
            }
        }
        return ['members'=>$json['data'],'locked_periods'=>$json['locked_reporting_periods']];
    }
}
