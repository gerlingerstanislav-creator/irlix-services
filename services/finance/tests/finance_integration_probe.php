<?php
declare(strict_types=1);
require __DIR__.'/support/finance_integration_probe.php';

function check(bool $value,string $message): void {
    if(!$value)throw new RuntimeException($message);
}
function probeResponse(int $status,mixed $data=null): object {
    return new class($status,$data) {
        public function __construct(private int $code,private mixed $data) {}
        public function status(): int {return $this->code;}
        public function successful(): bool {return $this->code>=200 && $this->code<300;}
        public function json(string $key): mixed {return $key==='data' ? $this->data : null;}
    };
}
$contracts=[['name'=>'Synthetic source','token_env'=>'TEST_TOKEN','header'=>'X-Test',
    'url'=>'http://example.invalid/read-only','params'=>['month'=>'2026-10']]];
$tokenFor=static fn(string $key): string => str_repeat('synthetic-',4);
foreach([403] as $denied){
    $calls=[];
    $errors=financeIntegrationProbe($contracts,$tokenFor,
        static function(array $contract,array $headers)use(&$calls,$denied){
            $calls[]=$headers;
            return count($calls)===3 ? probeResponse(200,[]) : probeResponse($denied);
        });
    check($errors===[],'Valid authenticated response and rejection must pass');
    check(count($calls)===3 && $calls[0]===[],'Missing token must omit the header');
    check($calls[1]===['X-Test'=>'invalid-smoke-token'],'Invalid token must be synthetic');
    check($calls[2]===['X-Test'=>$tokenFor('TEST_TOKEN')],'Positive probe must use configured token');
}
foreach([200,302,401,404,422,500] as $unexpected){
    $errors=financeIntegrationProbe($contracts,$tokenFor,
        static fn(array $contract,array $headers)=>probeResponse($unexpected,[]));
    check(count($errors)>=2,'Unexpected negative status must fail');
}
$errors=financeIntegrationProbe($contracts,$tokenFor,
    static fn(array $contract,array $headers)=>probeResponse(403,['private'=>'synthetic-private-data']));
check(count($errors)===3,'Denied data payload and failed positive response must fail');
check(!str_contains(implode(' ',$errors),'synthetic-private-data'),'Response data must never enter errors');
$errors=financeIntegrationProbe($contracts,$tokenFor,
    static fn(array $contract,array $headers)=>throw new RuntimeException('synthetic-secret'));
check(count($errors)===3 && !str_contains(implode(' ',$errors),'synthetic-secret'),
    'Connection exceptions must fail without leaking details');
$calls=0;
$errors=financeIntegrationProbe($contracts,static fn(string $key): string => '',
    static function()use(&$calls){$calls++;});
check(count($errors)===1 && $calls===0,'Missing credentials must fail before requests');
echo "Finance integration probe contract tests PASS\n";
