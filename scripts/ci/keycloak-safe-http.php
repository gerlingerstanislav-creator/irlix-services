<?php
declare(strict_types=1);
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{
    $codes=[];
    foreach (\Illuminate\Support\Facades\DB::table('keycloak_auth_sync')
            ->whereIn('status',['retry','failed'])->pluck('last_error') as $raw) {
        if (preg_match('/Keycloak authorization sync failed with HTTP ([0-9]{3})/',(string)$raw,$m)) {
            $code=(int)$m[1];if($code>=100&&$code<=599)$codes[(string)$code]=($codes[(string)$code]??0)+1;
        }
    }
    ksort($codes);
    echo json_encode(['operation'=>'http_status_group','codes'=>$codes],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo '{"operation":"http_status_group","error":"query_failed"}'. "\n";exit(1);}
