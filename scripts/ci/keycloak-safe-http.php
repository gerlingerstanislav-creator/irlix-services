<?php
declare(strict_types=1);
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
try {
    $failed = DB::table('keycloak_auth_sync')->whereIn('status',['retry','failed'])->get(['employee_id','last_error']);
    $codes=[]; $summary=['linked_failures'=>0,'user_get_success'=>0,'mappings_get_success'=>0,
      'marker_present'=>0,'marker_matches'=>0,'expected_roles_all_present'=>0,
      'missing_expected_roles'=>0,'no_expected_roles'=>0,'failed_identity_lookups'=>0,
      'failed_get_requests'=>0];
    foreach($failed as $entry){
      if(preg_match('/Keycloak authorization sync failed with HTTP ([0-9]{3})/',(string)$entry->last_error,$m)){
        $code=(int)$m[1];if($code>=100&&$code<=599)$codes[(string)$code]=($codes[(string)$code]??0)+1;
      }
    }
    $base=rtrim((string)env('KEYCLOAK_URL','http://keycloak:8080/keycloak/auth'),'/');
    $login=Http::asForm()->timeout(8)->post($base.'/realms/master/protocol/openid-connect/token',[
      'grant_type'=>'password','client_id'=>'admin-cli','username'=>(string)env('KEYCLOAK_ADMIN_USERNAME'),
      'password'=>(string)env('KEYCLOAK_ADMIN_PASSWORD')
    ]);
    $token=(string)($login->json('access_token')??'');
    if(!$login->successful()||$token===''){echo '{"probe":"admin_login_unavailable"}'. "\n";exit(3);}
    $realm=rawurlencode((string)env('KEYCLOAK_REALM','irlix'));
    $endpoint=$base.'/admin/realms/'.$realm;
    foreach($failed as $entry){
      $employee=DB::table('employees')->where('id',(int)$entry->employee_id)->first();
      if(!$employee||!$employee->keycloak_user_id){$summary['failed_identity_lookups']++;continue;}
      $summary['linked_failures']++;
      $uid=rawurlencode((string)$employee->keycloak_user_id);
      $user=Http::withToken($token)->timeout(8)->get($endpoint.'/users/'.$uid);
      $mapped=Http::withToken($token)->timeout(8)->get($endpoint.'/users/'.$uid.'/role-mappings/realm');
      if(!$user->successful()||!$mapped->successful()){$summary['failed_get_requests']++;continue;}
      $summary['user_get_success']++;$summary['mappings_get_success']++;
      $u=$user->json();$maps=$mapped->json();
      $attrs=is_array($u['attributes']??null)?$u['attributes']:[];
      $saved=$attrs['irlix_managed_roles']??null;
      if(is_array($saved))$summary['marker_present']++;
      $roles=[];
      if(($employee->employment_status??'')==='Трудоустроен'||$employee->login==='admin'){
        $identity=Request::create('/api/access/me','GET');
        $identity->attributes->set('identity',['sub'=>$employee->keycloak_user_id,'preferred_username'=>$employee->login]);
        $roles=\App\Support\KeycloakAuthorizationSync::grants((new \App\Support\EmployeesAccess)->resolve($identity));
      }
      if(!$roles)$summary['no_expected_roles']++;
      $actual=array_values(array_filter(array_map(
          static fn($record)=>is_array($record)?($record['name']??null):null,
          is_array($maps)?$maps:[]),
          'is_string'));
      if(count(array_diff($roles,$actual))===0)$summary['expected_roles_all_present']++;
      else $summary['missing_expected_roles']++;
      if(is_array($saved)&&count(array_diff($saved,$roles))===0&&count(array_diff($roles,$saved))===0)
        $summary['marker_matches']++;
    }
    ksort($codes);
    echo json_encode(['probe'=>'safe_keycloak_role_state','codes'=>$codes,'summary'=>$summary],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){
    echo json_encode(['probe'=>'safe_keycloak_role_state','failed_class'=>get_class($e)],JSON_THROW_ON_ERROR)."\n";
    exit(4);
}
