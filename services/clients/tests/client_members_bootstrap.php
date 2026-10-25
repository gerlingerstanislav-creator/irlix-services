<?php
require getenv('CLIENTS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(static function(string $class):void {
    if(str_starts_with($class,'App\\')) {
        $path=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php';
        if(is_file($path)) require $path;
    }
});
$app=new Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-canonical-tests');
$app->instance('request',Illuminate\Http\Request::create('/'));
$app->instance('config',new Illuminate\Config\Repository([
    'database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]],
    'app'=>['locale'=>'en','fallback_locale'=>'en'],'view'=>['paths'=>[],'compiled'=>sys_get_temp_dir()],
]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
foreach([Illuminate\Filesystem\FilesystemServiceProvider::class,Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\View\ViewServiceProvider::class,Illuminate\Events\EventServiceProvider::class,
    Illuminate\Routing\RoutingServiceProvider::class,Illuminate\Translation\TranslationServiceProvider::class,
    Illuminate\Validation\ValidationServiceProvider::class] as $provider) $app->register($provider);
Illuminate\Database\Eloquent\Model::setConnectionResolver($app['db']);
Illuminate\Http\Request::macro('validate',function(array $rules):array{return app('validator')->make($this->all(),$rules)->validate();});
function clientCheck(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);}
function clientReject(callable $run,int $status,string $reason):void {
    try{$run();}catch(Illuminate\Validation\ValidationException $e){clientCheck($status===422,$reason);return;}
    catch(Symfony\Component\HttpKernel\Exception\HttpException $e){clientCheck($e->getStatusCode()===$status,$reason.' unexpected '.$e->getStatusCode());return;}
    throw new RuntimeException($reason.' accepted');
}
function clientRequest(array $data=[],string $method='POST'):Illuminate\Http\Request {
    $r=Illuminate\Http\Request::create('/',$method,$data);
    $r->attributes->set('client_contour_access',['platform_admin'=>true]);
    $r->attributes->set('identity',['roles'=>['platform-admin'],'preferred_username'=>'synthetic-admin']);
    return $r;
}
function clientSchemaLegacy():void {
    $s=Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
    $s->create('clients',function($t){$t->id();$t->string('name');$t->integer('account_employee_id')->nullable();$t->integer('sales_employee_id')->nullable();$t->decimal('overtime_multiplier',8,3)->default(1);$t->timestamps();});
    $s->create('projects',function($t){$t->id();$t->integer('client_id');$t->string('name')->nullable();$t->boolean('is_default')->default(false);$t->timestamps();});
    $s->create('partner_specialists',function($t){$t->id();$t->string('full_name');$t->timestamps();});
    $s->create('project_members',function($t){$t->id();$t->integer('project_id');$t->integer('specialist_id')->nullable();$t->integer('partner_specialist_id')->nullable();$t->string('specialist_name');$t->integer('source_attempt_id')->nullable();$t->timestamps();});
    $s->create('member_terms',function($t){$t->id();$t->integer('project_member_id');$t->string('technology');$t->string('level');$t->decimal('hourly_rate',12,2)->nullable();$t->decimal('hours_per_day',5,2);$t->date('valid_from');$t->date('valid_to')->nullable();$t->timestamps();});
    $s->create('connection_attempts',function($t){$t->id();$t->integer('position_id');$t->integer('specialist_id')->nullable();$t->string('specialist_name');$t->boolean('is_external')->default(false);$t->string('status');$t->timestamp('closed_at')->nullable();$t->timestamps();});
    $s->create('project_member_feedbacks',function($t){$t->id();$t->integer('project_member_id');$t->text('text');$t->string('created_by_username')->nullable();$t->timestamps();});
    $s->create('client_requests',function($t){$t->id();$t->integer('client_id');});
    $s->create('positions',function($t){$t->id();$t->integer('client_request_id');});
    $s->create('reporting_periods',function($t){$t->id();$t->integer('client_id');$t->date('period_start');$t->date('period_end');$t->string('status');$t->decimal('overtime_multiplier',8,3)->default(1);foreach(['timesheets_sent_at','timesheets_approved_at','act_sent_at','act_approved_at','paid_at']as $name)$t->date($name)->nullable();$t->timestamps();});
    $s->create('reporting_period_approved_hours',function($t){$t->id();$t->integer('reporting_period_id');$t->integer('member_term_id');$t->decimal('hours',12,2);$t->decimal('overtime_hours',12,2)->default(0);$t->string('updated_by')->nullable();$t->timestamps();});
    $s->create('reporting_period_history',function($t){$t->id();$t->integer('reporting_period_id');$t->string('type');$t->string('title');$t->text('text')->nullable();$t->string('author')->nullable();$t->timestamps();});
    $s->create('client_history_events',function($t){$t->id();$t->integer('client_id');$t->string('type');$t->string('title');$t->text('text')->nullable();$t->string('created_by_username')->nullable();$t->timestamps();});
}
function clientExpand():void {foreach(['2026_10_23_000001_partner_directory.php','2026_10_23_000002_client_members.php','2026_10_23_000003_reporting_timesheet_snapshots.php']as $f)(require dirname(__DIR__).'/database/migrations/'.$f)->up();}
