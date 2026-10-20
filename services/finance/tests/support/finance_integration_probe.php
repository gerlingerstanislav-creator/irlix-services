<?php
declare(strict_types=1);

// Shared with deterministic CLI tests. The caller issues GET requests only.
// Never include source bodies, headers, credentials or exception text in output.
function financeIntegrationProbe(array $contracts, callable $tokenFor, callable $get): array {
    $errors=[];
    foreach($contracts as $contract){
        $name=$contract['name'];
        $token=$tokenFor($contract['token_env']);
        if(strlen($token)<32){
            $errors[]=$name.': integration credentials missing';
            continue;
        }
        // Short synthetic token cannot equal a valid configured integration secret.
        foreach(['missing'=>[], 'invalid'=>[$contract['header']=>'invalid-smoke-token']] as $mode=>$headers){
            try {
                $response=$get($contract,$headers);
                if($response->status()!==403 || $response->json('data')!==null){
                    $errors[]=$name.': '.$mode.' token was not safely rejected (HTTP '.$response->status().')';
                }
            } catch(\Throwable) {
                $errors[]=$name.': '.$mode.' token check connection failed';
            }
        }
        try {
            $response=$get($contract,[$contract['header']=>$token]);
            if(!$response->successful() || !is_array($response->json('data'))){
                $errors[]=$name.': unavailable or unexpected response (HTTP '.$response->status().')';
            }
        } catch(\Throwable) {
            $errors[]=$name.': connection failed';
        }
    }
    return $errors;
}
