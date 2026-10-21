<?php
declare(strict_types=1);
// Read-only production triage: never print credentials, identities, role names,
// SQL values, raw exception messages or Keycloak response bodies.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
function errorBucket(string $message): string {
    return match (true) {
        str_contains($message, 'administrator login unavailable') => 'admin_login_unavailable',
        str_contains($message, 'Unmapped bootstrap platform administrator') => 'bootstrap_admin_mismatch',
        str_contains($message, 'employee identity not found') => 'identity_not_found',
        str_contains($message, 'Keycloak could not resolve an IRLIX role') => 'realm_role_unresolved',
        str_contains($message, 'Keycloak role mapping is unavailable') => 'mapping_unavailable',
        preg_match('/SQLSTATE\\[42703\\]|Undefined column/i', $message) === 1 => 'database_column_missing',
        preg_match('/SQLSTATE\\[42P01\\]|relation .+ does not exist/i', $message) === 1 => 'database_relation_missing',
        str_contains($message, 'SQLSTATE[') => 'database_other',
        preg_match('/cURL error 28|timed out|Connection timed out/i', $message) === 1 => 'network_timeout',
        preg_match('/cURL error|Connection refused|Could not resolve host/i', $message) === 1 => 'network_connection',
        preg_match('/HTTP 401\\b/', $message) === 1 => 'keycloak_http_401',
        preg_match('/HTTP 403\\b/', $message) === 1 => 'keycloak_http_403',
        preg_match('/HTTP 404\\b/', $message) === 1 => 'keycloak_http_404',
        preg_match('/HTTP 409\\b/', $message) === 1 => 'keycloak_http_409',
        preg_match('/HTTP 5\\d\\d\\b/', $message) === 1 => 'keycloak_http_5xx',
        str_contains($message, 'Keycloak authorization sync failed with HTTP') => 'keycloak_http_other',
        preg_match('/Undefined array key|Undefined property|Trying to access array offset/i', $message) === 1 => 'php_undefined_access',
        preg_match('/Call to undefined method|Call to a member function|Class .* not found/i', $message) === 1 => 'php_missing_symbol',
        preg_match('/must be of type|TypeError|Return value must/i', $message) === 1 => 'php_type_mismatch',
        preg_match('/permission denied|not authorized|forbidden/i', $message) === 1 => 'permission_denied',
        preg_match('/parse error|syntax error/i', $message) === 1 => 'php_parse_error',
        default => 'unclassified',
    };
}
try {
    $rows = DB::table('keycloak_auth_sync')
        ->whereIn('status', ['retry','failed'])->get(['status','last_error','attempts']);
    $buckets=[]; $attemptGroups=[]; $hints=[];
    $safeWords=['column','table','department','employee','role','keycloak','access','query','exception','syntax',
                'database','undefined','missing','invalid','array','object','string','laravel','postgresql',
                'method','sqlstate','token','connection','response','unique','foreign','constraint'];
    foreach ($rows as $row) {
        $msg=(string)($row->last_error??'');
        $bucket=$msg===''?'empty_error':errorBucket($msg);
        $buckets[$bucket]=($buckets[$bucket]??0)+1;
        $attempt=(int)($row->attempts??0);
        $attemptBand=$attempt===0?'0':($attempt<=2?'1-2':($attempt<=5?'3-5':($attempt<=10?'6-10':'11+')));
        $attemptGroups[$attemptBand]=($attemptGroups[$attemptBand]??0)+1;
        if ($bucket==='unclassified') {
            foreach ($safeWords as $word) {
                if (stripos($msg,$word)!==false) $hints[$word]=($hints[$word]??0)+1;
            }
        }
    }
    ksort($buckets);ksort($attemptGroups);ksort($hints);
    $curlCodes=[]; $networkTypes=[];
    foreach ($rows as $entry) {
        $msg=(string)($entry->last_error??'');
        if (preg_match('/cURL error ([0-9]{1,3}):/', $msg, $match)===1) {
            $code=(int)$match[1];
            if ($code >= 1 && $code<=99) $curlCodes[(string)$code]=($curlCodes[(string)$code]??0)+1;
        }
        $type=match(true) {
            str_contains($msg,'Could not resolve host') => 'dns_resolution',
            str_contains($msg,'Connection refused') => 'connection_refused',
            str_contains($msg,'SSL certificate') => 'tls_validation',
            str_contains($msg,'URL using bad/illegal format') => 'bad_url_format',
            str_contains($msg,'Connection reset by peer') => 'connection_reset',
            str_contains($msg,'timed out') => 'timeout',
            default => 'other_or_not_network',
        };
        $networkTypes[$type]=($networkTypes[$type]??0)+1;
    }
    ksort($curlCodes);ksort($networkTypes);
    $results=['curl_error_codes'=>$curlCodes,'network_subtypes'=>$networkTypes,
        'container_service'=>(string)(getenv('AUTHZ_PROBE_SERVICE')?:'employees'),
        'operation'=>'read_only_authz_triage','retry_failed_count'=>count($rows),
        'categories'=>$buckets,'attempt_bands'=>$attemptGroups,'safe_keyword_hits'=>$hints];
    // An admin login is a read-only reachability/credentials test. Never log token,
    // response body, URL, realm metadata or exception message.
    try {
        $base=rtrim((string)env('KEYCLOAK_URL','http://keycloak:8080/keycloak/auth'),'/');
        $response=Http::asForm()->timeout(8)->post($base.'/realms/master/protocol/openid-connect/token',[
            'grant_type'=>'password','client_id'=>'admin-cli',
            'username'=>(string)env('KEYCLOAK_ADMIN_USERNAME'),
            'password'=>(string)env('KEYCLOAK_ADMIN_PASSWORD'),
        ]);
        $token=$response->json('access_token');
        $results['admin_token_http']=$response->status();
        $results['admin_token_received']=$response->successful()&&is_string($token)&&$token!=='';
        if($results['admin_token_received']) {
            $admin=Http::withToken($token)->timeout(8)->get(
                $base.'/admin/realms/'.rawurlencode((string)env('KEYCLOAK_REALM','irlix'))
            );
            $results['realm_admin_get_http']=$admin->status();
        }
    } catch (\Throwable $e) {
        $results['admin_probe_failure_class']=get_class($e);
    }
    echo json_encode($results,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
} catch (\Throwable $e) {
    echo json_encode(['operation'=>'read_only_authz_triage',
        'probe_failure_class'=>get_class($e)],JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
