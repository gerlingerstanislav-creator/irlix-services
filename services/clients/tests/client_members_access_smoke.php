<?php
require __DIR__.'/client_members_bootstrap.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
clientSchemaLegacy();clientExpand();
DB::connection()->getSchemaBuilder()->create('client_account_delegations',function($t){$t->id();$t->integer('client_id');$t->integer('to_employee_id');$t->date('starts_at');$t->date('ends_at');$t->timestamp('revoked_at')->nullable();});
DB::table('clients')->insert([['id'=>1,'name'=>'Own','account_employee_id'=>5],['id'=>2,'name'=>'Other','account_employee_id'=>6],['id'=>3,'name'=>'Team','account_employee_id'=>7]]);
foreach([1,2,3]as $id)DB::table('client_members')->insert(['id'=>$id,'client_id'=>$id,'employee_id'=>10,'specialist_name'=>'Synthetic']);
DB::table('client_account_delegations')->insert(['client_id'=>2,'to_employee_id'=>5,'starts_at'=>'2026-01-01','ends_at'=>'2026-12-31']);
Http::fake(['*/self'=>Http::response(['data'=>['id'=>5]]),'*/clients-directory'=>Http::response(['data'=>['employees'=>[['id'=>7,'department_id'=>11]]]])]);
$resolver=new class extends App\Support\ClientContourAccess {
    public string $scope='own';public bool $manage=true;
    public function resolve(Illuminate\Http\Request $request):array {
        $permissions=[];
        foreach(['clients.view','clients.manage','members.view','members.manage']as $p)$permissions[$p]=['allowed'=>!str_ends_with($p,'manage')||$this->manage,'scope'=>$this->scope];
        return ['platform_admin'=>false,'employee'=>['id'=>5],'permissions'=>$permissions,'client_service_permissions'=>$permissions,'department_ids'=>[11],'roles'=>['account-manager']];
    }
};
$guard=new App\Http\Middleware\ClientsAccountingAccess($resolver);
$invoke=function(string $path,string $method='GET')use($guard){
    return $guard->handle(Illuminate\Http\Request::create('/api/'.$path,$method),fn($r)=>response()->json(['data'=>['allowed'=>true]]))->getStatusCode();
};
clientCheck($invoke('clients/1/client-members')===200,'own membership route');
clientCheck($invoke('client-members/2')===200,'active delegate membership route');
clientCheck($invoke('client-members/3')===403,'other team denied under own');
$resolver->scope='team';clientCheck($invoke('client-members/3')===200,'team scoped route');
$resolver->manage=false;clientCheck($invoke('client-members/1/terms','POST')===403,'view cannot mutate canonical terms');
$resolver->scope='all';clientCheck($invoke('client-members/3')===200,'all scoped read');
// Controller remains responsible for registry all-scope after route guard.
$app->instance(App\Support\ClientContourAccess::class,$resolver);
$partners=new App\Http\Controllers\PartnersController();
$resolver->scope='own';clientReject(fn()=>$guard->handle(Illuminate\Http\Request::create('/api/partners'),fn($r)=>$partners->index($r)),403,'own scope registry');
$resolver->scope='all';clientCheck($guard->handle(Illuminate\Http\Request::create('/api/partners'),fn($r)=>$partners->index($r))->getStatusCode()===200,'all scope registry');
echo "Canonical membership AM/delegate/team/read-only and registry all-scope permissions PASS\n";
