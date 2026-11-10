<?php

declare(strict_types=1);

// No production credentials, Keycloak, SMTP, or shared business database.
require __DIR__.'/../vendor/autoload.php';

use App\Support\EmployeeOnboardingService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('date', new Illuminate\Support\DateFactory());
$capsule = new Manager($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
Model::setConnectionResolver($capsule->getDatabaseManager());
$db = $capsule->getConnection();
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, keycloak_user_id TEXT, work_email TEXT, personal_email TEXT, onboarding_email_status TEXT, onboarding_email_sent_at TEXT, onboarding_email_error TEXT, updated_at TEXT)');
$db->table('employees')->insert([
    ['id'=>7, 'keycloak_user_id'=>'synthetic-id', 'work_email'=>'synthetic.correct@irlix.ru', 'personal_email'=>'synthetic@example.invalid'],
    ['id'=>8, 'keycloak_user_id'=>'synthetic-id-2', 'work_email'=>'synthetic.second@irlix.ru', 'personal_email'=>'synthetic2@example.invalid'],
]);

$profile = [
    'synthetic-id' => ['id'=>'synthetic-id','username'=>'synthetic.correct','email'=>'synthetic.old@example.invalid','emailVerified'=>true,'requiredActions'=>[]],
    'synthetic-id-2' => ['id'=>'synthetic-id-2','username'=>'synthetic.second','email'=>'synthetic.second@irlix.ru','emailVerified'=>true,'requiredActions'=>[]],
];
$messages = []; $failedEmail = false; $restoreFailure = false; $updates = [];
$factory = new Factory();
Http::swap($factory);
$factory->preventStrayRequests();
$factory->fake(function ($request) use (&$profile,&$messages,&$failedEmail,&$restoreFailure,&$updates,$factory) {
    $url = $request->url();
    if (str_ends_with($url, '/realms/master/protocol/openid-connect/token')) {
        return $factory->response(['access_token'=>'synthetic-access'],200);
    }
    if (!preg_match('~/users/(synthetic-id(?:-2)?)(?:/execute-actions-email.*)?$~', $url, $m)) {
        throw new RuntimeException('Unexpected Keycloak request');
    }
    $uid = $m[1];
    if (str_contains($url, '/execute-actions-email')) {
        $messages[] = $profile[$uid]['email'];
        return $factory->response('', $failedEmail ? 503 : 204);
    }
    if ($request->method() === 'GET') return $factory->response($profile[$uid],200);
    if ($request->method() !== 'PUT') throw new RuntimeException('Unsupported Keycloak call');
    $incoming=$request->data();
    $updates[]=['id'=>$uid,'email'=>$incoming['email']];
    if ($restoreFailure && $incoming['email'] === 'synthetic.second@irlix.ru') {
        return $factory->response(['errorMessage'=>'Synthetic restore failure'],503);
    }
    $profile[$uid]=$incoming;
    return $factory->response('',204);
});
$check = static fn (bool $ok,string $reason) => $ok ?: throw new RuntimeException($reason);

$service = new EmployeeOnboardingService();
$result = $service->sendPasswordSetup(7);
$check($result['status']==='sent' && $result['recipient']==='synthetic@example.invalid', 'Unexpected recipient');
$check($messages===['synthetic@example.invalid'], 'Password mail was not addressed to personal email');
$check($profile['synthetic-id']['email']==='synthetic.correct@irlix.ru', 'Corporate Keycloak email was overwritten after delivery');
$check($profile['synthetic-id']['emailVerified']===false, 'Old email verification was carried to new email');
$check($db->table('employees')->where('id',7)->value('onboarding_email_status')==='sent', 'Delivery state not saved');

$failedEmail=true;
try { $service->sendPasswordSetup(8); throw new RuntimeException('SMTP failure unexpectedly accepted'); }
catch (RuntimeException $e) { $service->markFailure(8, $e); $check(str_contains($e->getMessage(),'onboarding email failed'), 'Wrong error on SMTP failure'); }
$check($messages[1]==='synthetic2@example.invalid', 'SMTP failure did not use personal email');
$check($profile['synthetic-id-2']['email']==='synthetic.second@irlix.ru','SMTP failure left personal email in Keycloak');
$check($db->table('employees')->where('id',8)->value('onboarding_email_status')!== 'sent', 'False successful delivery');

$failedEmail=false; $restoreFailure=true;
try { $service->sendPasswordSetup(8); throw new RuntimeException('Restore failure unexpectedly accepted'); }
catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'restoration failed'), 'Failed restoration not reported: '.$e->getMessage()); }
$check($db->table('employees')->where('id',8)->value('onboarding_email_status')!== 'sent','Failed restoration accepted as sent');

echo "Keycloak onboarding corporate email restoration: PASS\n";
