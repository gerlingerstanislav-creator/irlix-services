<?php
declare(strict_types=1);
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, "JWT authorization test: ".$e->getMessage()."\n");
    exit(1);
});
use App\Resources\VerifiedKeycloakJwt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
$csr = openssl_csr_new(['commonName'=>'test-irlix-authorization'], $key);
$crt = openssl_csr_sign($csr, null, $key, 1);
openssl_x509_export($crt,$cert);
$x5c = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $cert);
$base = rtrim((string) env('KEYCLOAK_URL','http://keycloak:8080/keycloak/auth'),'/');
$realm = env('KEYCLOAK_REALM','irlix');
Http::preventStrayRequests();
Http::fake(["{$base}/realms/{$realm}/protocol/openid-connect/certs"=>
    Http::response(['keys'=>[['kid'=>'ci-auth','x5c'=>[$x5c]]]],200)]);
$enc=static fn ($x)=>rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');
$claims=['sub'=>'ci-admin','iss'=>env('KEYCLOAK_ISSUER',$base.'/realms/'.$realm),
    'azp'=>env('KEYCLOAK_CLIENT_ID','irlix-services-web'),'exp'=>time()+600,'nbf'=>time()-10,
    'realm_access'=>['roles'=>['platform-admin','service:system']]];
$sign=static function($payload)use($enc,$key){
    $v=$enc(['alg'=>'RS256','kid'=>'ci-auth']).'.'.$enc($payload);
    openssl_sign($v,$signature,$key,OPENSSL_ALGO_SHA256);
    return $v.'.'.rtrim(strtr(base64_encode($signature),'+/','-_'),'=');
};
$probe=static function($token){
    $req=Request::create('/api/protected','GET');
    $req->headers->set('Authorization','Bearer '.$token);
    $result=VerifiedKeycloakJwt::authorize($req,'system');
    return is_array($result) ? 200 : $result->getStatusCode();
};
$assert=static function($ok,$why){if(!$ok) throw new RuntimeException($why);};
$assert($probe($sign($claims))===200,'valid admin service grant denied');
$bad=$claims; $bad['realm_access']['roles']=['platform-admin'];
$assert($probe($sign($bad))===403,'service grant missing but accepted');
$bad=$claims; $bad['realm_access']['roles']=['service:system'];
$assert($probe($sign($bad))===403,'non-admin service grant accepted');
$bad=$claims; $bad['exp']=time()-5;
$assert($probe($sign($bad))===401,'expired JWT accepted');
$bad=$claims; $bad['iss']='https://evil.example.invalid/realms/irlix';
$assert($probe($sign($bad))===401,'incorrect issuer accepted');
$bad=$claims; $bad['azp']='other-app';
$assert($probe($sign($bad))===401,'incorrect client accepted');
$parts=explode('.', $sign($claims));
$parts[1]=$enc($bad);
$assert($probe(implode('.',$parts))===401,'modified JWT accepted without signature');
echo "Signed Keycloak admin-only JWT: PASS\n";
