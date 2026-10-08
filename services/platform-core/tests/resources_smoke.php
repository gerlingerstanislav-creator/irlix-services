<?php

use App\Resources\PlatformAdminAuthorizer;
use App\Resources\ResourceStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

try {
require __DIR__.'/../vendor/autoload.php';

$assert = static function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fake = static function ($payload, $status) {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response($payload, $status)]);
};
$authorizer = new PlatformAdminAuthorizer();
$request = Request::create('/api/resources', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer synthetic-token']);
foreach ([[], ['employee'], ['platform-tester'], ['platform-admin'], ['system-admin']] as $roles) {
    $fake(['data'=>['roles'=>$roles]],200);
    $response = $authorizer->authorize($request);
    $assert(in_array($roles, [['platform-admin'], ['system-admin']], true) ? $response === null : $response?->getStatusCode() === 403, 'Role gate failed');
}
$assert($authorizer->authorize(Request::create('/api/resources'))?->getStatusCode()===401,'Missing bearer accepted');
foreach ([401,403,500] as $code) {
    $fake([], $code);
    $assert($authorizer->authorize($request)?->getStatusCode()===($code===500 ? 503 : $code),'Upstream gate failed');
}
$fake(['data'=>['permissions'=>['resources.view'=>true]]],200);
$assert($authorizer->authorize($request)?->getStatusCode()===403,'Malformed role gate failed');

$directory = sys_get_temp_dir().'/resources-test-'.bin2hex(random_bytes(5));
mkdir($directory);
$store = new ResourceStore($directory);
$assert($store->snapshot()===null,'Empty store');
file_put_contents($directory.'/current.json', json_encode(['collected_at'=>time()-60,'host'=>[],'services'=>[]]));
$assert($store->snapshot()['stale']===true,'Staleness not detected');
$db = new PDO('sqlite:'.$directory.'/history.sqlite');
$db->exec('CREATE TABLE samples (ts INTEGER, service TEXT, memory REAL, working REAL, cpu REAL)');
$insert = $db->prepare('INSERT INTO samples VALUES (?,?,?,?,?)');
$ts = intdiv(time(),300)*300;
$insert->execute([$ts,'employees',100,80,1]);
$insert->execute([$ts+60,'employees',200,150,2]);
$insert->execute([$ts,'postgres',300,250,3]);
$history = $store->history('24h','employees');
$assert(count($history)===1 && (float)$history[0]['memory_bytes']===150.0 && (float)$history[0]['memory_peak']===200.0,'Bucket/service filtering');
foreach ([['all','employees'],['1h',"' OR 1=1 --"]] as [$period,$service]) {
    try {$store->history($period,$service); throw new RuntimeException('Invalid history selector accepted');}
    catch (InvalidArgumentException $expected) {}
}
$db = null;
unlink($directory.'/current.json'); unlink($directory.'/history.sqlite'); rmdir($directory);
echo "Resource authorization/history checks passed\n";
} catch (\Throwable $error) {
    fwrite(STDERR, 'Resource checks failed: '.$error->getMessage()."\n");
    exit(1);
}
