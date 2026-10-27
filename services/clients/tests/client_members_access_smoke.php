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
    public string $scope='own';public bool $manage=true;public bool $membersView=true;
    public function resolve(Illuminate\Http\Request $request):array {
        $permissions=[];
        foreach(['clients.view','clients.manage','members.view','members.manage',
                 'reports.view','contacts.view','requests.view','leads.view','positions.view','attempts.view']as $p)
            $permissions[$p]=['allowed'=>(!str_ends_with($p,'manage')||$this->manage)
                && ($p!=='members.view'||$this->membersView),'scope'=>$this->scope];
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
// Card permissions contract: an employee who can read a client and its
// memberships must be able to open canonical and legacy specialist cards;
// hidden cards and unrelated clients still reject 403. The same invariant
// applies to client, contact and reporting-period cards.
DB::table('projects')->insert(['id'=>19,'client_id'=>1,'name'=>'Synthetic Project']);
DB::table('project_members')->insert(['id'=>19,'project_id'=>19,'specialist_name'=>'Synthetic Specialist']);
DB::table('reporting_periods')->insert([
    'id'=>19,'client_id'=>1,'period_start'=>'2026-10-01',
    'period_end'=>'2026-10-31','status'=>'Новый',
]);
DB::connection()->getSchemaBuilder()->create('contact_relations',function($t){
    $t->id();$t->integer('contact_person_id');$t->string('entity_type');
    $t->integer('entity_id');$t->boolean('active')->default(true);
});
DB::connection()->getSchemaBuilder()->create('leads',function($t){
    $t->id();$t->integer('responsible_employee_id');
});
DB::table('contact_relations')->insert(['contact_person_id'=>19,'entity_type'=>'client','entity_id'=>1,'active'=>true]);

$resolver->scope='own';$resolver->manage=false;$resolver->membersView=true;
foreach(['clients/1/card','clients/1/client-members','client-members/1',
         'members/19','reporting-periods/19','contacts/19']as $card)
    clientCheck($invoke($card)===200,'Account Manager could not open permitted card '.$card);
clientCheck($invoke('clients/2/client-members')===200,'Delegated Account Manager card denied');
foreach(['clients/3/card','clients/3/client-members','client-members/3']as $card)
    clientCheck($invoke($card)===403,'Account Manager could access unrelated card '.$card);
$resolver->scope='team';
foreach(['clients/3/card','clients/3/client-members','client-members/3']as $card)
    clientCheck($invoke($card)===200,'Head of Accounting team card denied '.$card);
$resolver->scope='all';
foreach(['clients/3/card','clients/3/client-members','client-members/3']as $card)
    clientCheck($invoke($card)===200,'Client Service head global card denied '.$card);
$resolver->scope='own';$resolver->membersView=false;
clientCheck($invoke('clients/1/card')===200,'Client card requires unrelated members.view permission');
clientCheck($invoke('clients/1/client-members')===403,'Denied members.view opened canonical card');
clientCheck($invoke('members/19')===403,'Denied members.view opened legacy project card');
$resolver->membersView=true;
clientCheck($invoke('client-members/1/terms','POST')===403,'Read-only card granted editing permission');
echo "Canonical membership and Cards own/delegate/team/all, view-only and denied: PASS\n";
