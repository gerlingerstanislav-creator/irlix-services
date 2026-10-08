<?php

namespace App\Http\Controllers { abstract class Controller {} }

namespace {
    require getenv('CLIENTS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($path)) require $path;
        }
    });
    $app = new \Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-tester-smoke');
    $app->instance('request', \Illuminate\Http\Request::create('/'));
    $app->instance('config', new \Illuminate\Config\Repository([
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
        'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
        'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
    ]));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    foreach ([\Illuminate\Filesystem\FilesystemServiceProvider::class, \Illuminate\View\ViewServiceProvider::class,
        \Illuminate\Database\DatabaseServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class,
        \Illuminate\Validation\ValidationServiceProvider::class] as $provider) $app->register($provider);
    \Illuminate\Http\Request::macro('validate', function (array $rules): array {
        return app('validator')->make($this->all(), $rules)->validate();
    });
    $schema = \Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
    $schema->create('clients', function ($t) { $t->id(); $t->string('name'); });
    $schema->create('projects', function ($t) { $t->id(); $t->integer('client_id'); });
    $schema->create('partner_specialists', function ($t) { $t->id(); $t->string('full_name'); $t->timestamps(); });
    $schema->create('project_members', function ($t) { $t->id(); $t->integer('project_id'); $t->integer('specialist_id')->nullable(); $t->integer('partner_specialist_id')->nullable(); $t->string('specialist_name'); $t->integer('source_attempt_id')->nullable(); $t->timestamps(); $t->unique(['project_id','specialist_id']); $t->unique(['project_id','partner_specialist_id']); });
    $schema->create('member_terms', function ($t) { $t->id(); $t->integer('project_member_id'); $t->string('technology'); $t->string('level'); $t->decimal('hourly_rate'); $t->decimal('hours_per_day'); $t->date('valid_from'); $t->date('valid_to')->nullable(); $t->timestamps(); });
    $schema->create('connection_attempts', function ($t) { $t->id(); $t->integer('position_id'); $t->boolean('is_external'); $t->integer('specialist_id')->nullable(); $t->string('specialist_name'); $t->string('status'); $t->dateTime('closed_at')->nullable(); $t->timestamps(); });
    $schema->create('positions', function ($t) { $t->id(); $t->integer('client_request_id'); });
    $schema->create('client_requests', function ($t) { $t->id(); $t->integer('client_id'); });
    $db=\Illuminate\Support\Facades\DB::class;
    $db::table('clients')->insert(['id'=>1,'name'=>'Synthetic Client']);
    $db::table('projects')->insert([['id'=>1,'client_id'=>1],['id'=>2,'client_id'=>1]]);
    $controller=new \App\Http\Controllers\ClientsController();
    function partnerCheck(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
    $body=['is_external'=>true,'specialist_id'=>999,'specialist_name'=>'Synthetic Partner','technology'=>'Synthetic','level'=>'Senior','hourly_rate'=>100,'hours_per_day'=>8,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'];
    $create=fn ($data,$project=1)=>$controller->storeMember(\Illuminate\Http\Request::create('/','POST',$data),$project)->getData(true)['data']['member_id'];
    $first=$create($body); $second=$create($body);
    partnerCheck($first!==$second && $db::table('partner_specialists')->count()===2,'Matching partner names must stay distinct');
    $member=$db::table('project_members')->find($first);
    partnerCheck($member->specialist_id===null && $member->partner_specialist_id!==null,'Partner must never impersonate an employee');
    $employee=$create([...$body,'is_external'=>false,'specialist_id'=>$member->partner_specialist_id]);
    partnerCheck($db::table('project_members')->find($employee)->partner_specialist_id===null,'Employee numeric IDs have a separate namespace');
    try { $create([...$body,'specialist_name'=>'  ']); throw new RuntimeException('Blank name accepted'); } catch (\Illuminate\Validation\ValidationException $e) { partnerCheck(isset($e->errors()['specialist_name']),'Wrong blank-name rejection'); }
    $db::table('client_requests')->insert(['id'=>1,'client_id'=>1]); $db::table('positions')->insert(['id'=>1,'client_request_id'=>1]);
    $db::table('connection_attempts')->insert(['id'=>1,'position_id'=>1,'is_external'=>true,'specialist_id'=>null,'specialist_name'=>'Synthetic Attempt Partner','status'=>'Ожидает подключения']);
    $fromAttempt=$create([...$body,'source_attempt_id'=>1]);
    partnerCheck($db::table('connection_attempts')->find(1)->status==='Закрыт: успех','External attempt can create connection');
    partnerCheck($db::table('project_members')->find($fromAttempt)->specialist_name==='Synthetic Attempt Partner','Attempt identity is authoritative');
    $before=$db::table('partner_specialists')->count();
    try { $create([...$body,'source_attempt_id'=>1]); throw new RuntimeException('Overlapping conditions accepted'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { partnerCheck($e->getStatusCode()===422,'Wrong overlap rejection'); }
    partnerCheck($db::table('partner_specialists')->count()===$before,'Rejected connection leaves no orphan identity');
    partnerCheck($create([...$body,'source_attempt_id'=>1,'valid_from'=>'2026-11-01','valid_to'=>'2026-11-30'])===$fromAttempt,'Repeat attempt connection reuses partner');
    $terms=new \App\Http\Controllers\MemberTermsController();
    $terms->store(\Illuminate\Http\Request::create('/','POST',[...$body,'valid_from'=>'2026-10-15','valid_to'=>'2026-11-01']),$first);
    partnerCheck($db::table('member_terms')->where('project_member_id',$first)->orderBy('id')->value('valid_to')==='2026-10-14','Partner conditions use normal versioning');
    $controller->moveMember(\Illuminate\Http\Request::create('/','PATCH',['project_id'=>2]),$first);
    $controller->moveMember(\Illuminate\Http\Request::create('/','PATCH',['project_id'=>2]),$second);
    partnerCheck($db::table('project_members')->where('project_id',2)->count()===2,'Moving partners never conflates null employee IDs');
    $beforePartners=$db::table('partner_specialists')->count(); $beforeMembers=$db::table('project_members')->count();
    $db::statement("CREATE TRIGGER synthetic_reject_terms BEFORE INSERT ON member_terms BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
    try { $create($body); throw new RuntimeException('Synthetic database failure accepted'); } catch (\Illuminate\Database\QueryException $e) {}
    partnerCheck($db::table('partner_specialists')->count()===$beforePartners && $db::table('project_members')->count()===$beforeMembers,'Terms failure must roll back new partner and member');
    $db::statement('DROP TRIGGER synthetic_reject_terms');
    echo "Partner connections passed: identity namespaces, same names, attempts, overlap, rollback and conditions.\n";
}
