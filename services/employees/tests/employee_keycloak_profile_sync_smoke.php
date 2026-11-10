<?php

declare(strict_types=1);

// Synthetic database and Keycloak facade; exercises the real retry worker's
// profile reconciliation contract without touching any external service.
require __DIR__.'/../vendor/autoload.php';

use App\Support\KeycloakCorporateProfileSync;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

$app=new Container(); Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('date',new Illuminate\Support\DateFactory());
$capsule=new Manager($app); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
Model::setConnectionResolver($capsule->getDatabaseManager());
$db=$capsule->getConnection();
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, account_type TEXT, login TEXT, work_email TEXT, first_name TEXT, last_name TEXT, employment_status TEXT, onboarding_email_status TEXT, keycloak_user_id TEXT)');
$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, alias TEXT)');
$db->statement('CREATE TABLE keycloak_auth_sync (employee_id INTEGER PRIMARY KEY, managed_roles TEXT, updated_at TEXT)');
$db->table('employees')->insert([
 ['id'=>17,'account_type'=>'employee','login'=>'synthetic.current','work_email'=>'synthetic.current@irlix.ru','first_name'=>'Synthetic','last_name'=>'Employee','employment_status'=>'Уволен','onboarding_email_status'=>'sent','keycloak_user_id'=>'synthetic-sub'],
 ['id'=>18,'account_type'=>'employee','login'=>'synthetic.collision','work_email'=>'synthetic.collision@irlix.ru','first_name'=>'Other','last_name'=>'Employee','employment_status'=>'Уволен','onboarding_email_status'=>'sent','keycloak_user_id'=>'synthetic-conflict'],
]);
$db->table('keycloak_auth_sync')->insert([['employee_id'=>17],['employee_id'=>18]]);
$user=[
 'synthetic-sub'=>['id'=>'synthetic-sub','username'=>'synthetic.old','email'=>'synthetic.personal@example.invalid','firstName'=>'Old','lastName'=>'Employee','emailVerified'=>true],
 'synthetic-conflict'=>['id'=>'synthetic-conflict','username'=>'synthetic.older','email'=>'synthetic.other@example.invalid','emailVerified'=>true],
];
$operations=[];$factory=new Factory();Http::swap($factory);$factory->preventStrayRequests();
$factory->fake(function($request)use(&$user,&$operations,$factory){
 $url=$request->url();$method=$request->method();
 if(str_ends_with($url,'/realms/master/protocol/openid-connect/token'))return $factory->response(['access_token'=>'synthetic-token'],200);
 if(preg_match('~/users/(synthetic-sub|synthetic-conflict)(/role-mappings/realm(?:/composite)?)?$~',$url,$m)){
   $uid=$m[1];$suffix=$m[2]??'';
   if($suffix!=='')return $factory->response([],200);
   if($method==='GET')return $factory->response($user[$uid],200);
   if($method==='PUT'){
     $operations[]=['id'=>$uid,'payload'=>$request->data()];
     if($uid==='synthetic-conflict')return $factory->response(['error'=>'conflict'],409);
     $user[$uid]=array_replace($user[$uid],$request->data());
     return $factory->response('',204);
   }
 }
 throw new RuntimeException('Unexpected Keycloak test request: '.$method);
});
$check=static fn(bool $valid,string $message)=>$valid ?: throw new RuntimeException($message);
$sync=new KeycloakCorporateProfileSync();
$sync->reconcile(17);
$check($user['synthetic-sub']['username']==='synthetic.current','Keycloak login did not follow Employees');
$check($user['synthetic-sub']['email']==='synthetic.current@irlix.ru','Keycloak email did not follow Employees');
$check(($user['synthetic-sub']['emailVerified']??true)===false,'Changed corporate email kept prior verification');
$check($user['synthetic-sub']['firstName']==='Synthetic','Keycloak firstName did not follow Employees');
$check(count($operations)===1,'Expected one profile reconciliation');
$sync->reconcile(17);
$check(count($operations)===1,'Idempotent reconciliation still wrote identity');
$failed=false;
try{$sync->reconcile(18);}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'reconciliation required');}
$check($failed,'Keycloak collision must fail closed, not link wrong account');
$check($user['synthetic-conflict']['email']==='synthetic.other@example.invalid','Conflict unexpectedly rewrote Keycloak identity');
$check($db->table('employees')->count()===2,'Sync must not create or delete Employees rows');
echo "Keycloak corporate profile import/update sync: PASS\n";
