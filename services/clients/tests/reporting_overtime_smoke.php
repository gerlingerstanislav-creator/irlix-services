<?php
// Isolated real Laravel controller + SQLite regression. This is not live
// Keycloak/Timesheets E2E; middleware scoping is checked separately.
namespace App\Http\Controllers { abstract class Controller {} }

namespace {
    require getenv('CLIENTS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $path=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php';
            if (is_file($path)) require $path;
        }
    });
    $app=new \Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-report-overtime-smoke');
    $app->instance('request', \Illuminate\Http\Request::create('/'));
    $app->instance('config',new \Illuminate\Config\Repository([
        'database'=>['default'=>'sqlite','connections'=>[
            'sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'',
                'foreign_key_constraints'=>true]]],
        'app'=>['locale'=>'en','fallback_locale'=>'en'],
        'view'=>['paths'=>[],'compiled'=>sys_get_temp_dir()],
    ]));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    foreach ([
        \Illuminate\Filesystem\FilesystemServiceProvider::class,
        \Illuminate\Database\DatabaseServiceProvider::class,
        \Illuminate\View\ViewServiceProvider::class,
        \Illuminate\Translation\TranslationServiceProvider::class,
        \Illuminate\Validation\ValidationServiceProvider::class,
    ] as $provider) $app->register($provider);
    \Illuminate\Http\Request::macro('validate',function(array $rules):array {
        return app('validator')->make($this->all(),$rules)->validate();
    });

    $db=\Illuminate\Support\Facades\DB::class;
    $schema=$db::connection()->getSchemaBuilder();
    $schema->create('clients',static function($t):void {
        $t->id();$t->string('name');$t->integer('account_employee_id')->nullable();
        $t->decimal('overtime_multiplier',8,3)->default(1);$t->timestamps();
    });
    $schema->create('projects',static function($t):void {
        $t->id();$t->integer('client_id');$t->string('name')->nullable();
    });
    $schema->create('project_members',static function($t):void {
        $t->id();$t->integer('project_id');$t->integer('specialist_id')->nullable();
        $t->string('specialist_name');$t->integer('partner_specialist_id')->nullable();
    });
    $schema->create('member_terms',static function($t):void {
        $t->id();$t->integer('project_member_id');$t->decimal('hourly_rate',12,2)->nullable();
        $t->date('valid_from');$t->date('valid_to')->nullable();
    });
    $schema->create('reporting_periods',static function($t):void {
        $t->id();$t->integer('client_id');$t->date('period_start');$t->date('period_end');
        $t->string('status');$t->decimal('overtime_multiplier',8,3)->default(1);
        $t->date('timesheets_sent_at')->nullable();$t->date('timesheets_approved_at')->nullable();
        $t->date('act_sent_at')->nullable();$t->date('act_approved_at')->nullable();
        $t->date('paid_at')->nullable();$t->timestamps();
    });
    $schema->create('reporting_period_approved_hours',static function($t):void {
        $t->id();$t->integer('reporting_period_id');$t->integer('member_term_id');
        $t->unique(['reporting_period_id','member_term_id']);
        $t->decimal('hours',12,2);$t->decimal('overtime_hours',12,2)->default(0);
        $t->string('updated_by')->nullable();$t->timestamps();
    });
    $schema->create('reporting_period_history',static function($t):void {
        $t->id();$t->integer('reporting_period_id');$t->string('type');
        $t->string('title');$t->text('text')->nullable();$t->string('author')->nullable();
        $t->timestamps();
    });
    $db::table('clients')->insert(['id'=>1,'name'=>'Synthetic client','overtime_multiplier'=>1.5]);
    $db::table('projects')->insert(['id'=>7,'client_id'=>1,'name'=>'Main']);
    $db::table('project_members')->insert([
        'id'=>9,'project_id'=>7,'specialist_id'=>5,'specialist_name'=>'Test Worker']);
    $db::table('member_terms')->insert([
        'id'=>11,'project_member_id'=>9,'hourly_rate'=>100,
        'valid_from'=>'2026-10-01','valid_to'=>null]);
    \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['data'=>[
        'entries'=>[['employee_id'=>5,'project_id'=>7,'client_id'=>1,
            'work_date'=>'2026-10-05','hours'=>160]],
        'final_approvals'=>[['employee_id'=>5,'project_id'=>7,'month'=>'2026-10-01']],
    ]])]);
    function assertPeriod(bool $condition,string $reason):void {
        if(!$condition) throw new \RuntimeException($reason);
    }
    function conflict(callable $run,int $status,string $reason):void {
        try{$run();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){
            assertPeriod($e->getStatusCode()===$status,$reason.' unexpected status');
            return;
        }
        throw new \RuntimeException($reason.' expected rejection');
    }
    function patch(array $input):\Illuminate\Http\Request {
        $r=\Illuminate\Http\Request::create('/','PATCH',$input);
        $r->attributes->set('identity',['preferred_username'=>'ci-am']);
        return $r;
    }
    $ctl=new \App\Http\Controllers\ReportingPeriodsController();
    $created=$ctl->store(\Illuminate\Http\Request::create('/','POST',[
        'client_id'=>1,'period_start'=>'2026-10-01','period_end'=>'2026-10-31',
    ]))->getData(true)['data'];
    $period=(int)$created['id'];
    assertPeriod((float)$created['overtime_multiplier']===1.5,'Initial multiplier copy from client');
    $db::table('clients')->where('id',1)->update(['overtime_multiplier'=>2]);
    assertPeriod((float)$db::table('reporting_periods')->find($period)->overtime_multiplier===1.5,
        'Client default change must not rewrite existing period');

    $ctl->updateOvertimeMultiplier(patch([
        'overtime_multiplier'=>1.75,'expected_multiplier'=>1.5]),$period);
    assertPeriod((float)$db::table('reporting_periods')->find($period)->overtime_multiplier===1.75,
        'AM can edit period multiplier snapshot');
    conflict(fn()=>$ctl->updateOvertimeMultiplier(patch([
        'overtime_multiplier'=>2,'expected_multiplier'=>1.5]),$period),409,
        'Stale concurrent coefficient edit');

    $ctl->updateApprovedHours(patch(['hours'=>150,'expected_hours'=>160]),$period,11);
    $ctl->updateApprovedHours(patch([
        'overtime_hours'=>10,'expected_overtime_hours'=>0]),$period,11);
    $saved=$db::table('reporting_period_approved_hours')->first();
    assertPeriod((float)$saved->hours===150.0&&(float)$saved->overtime_hours===10.0,
        'Partial updates preserve independent regular/overtime fields');

    $shown=$ctl->show(\Illuminate\Http\Request::create('/','GET'),$period)->getData(true)['data'];
    assertPeriod((float)$shown['summary']['worked_hours']===160.0
        && (float)$shown['summary']['confirmed_hours']===150.0
        && (float)$shown['summary']['approved_overtime_hours']===10.0
        && (float)$shown['summary']['confirmed_amount']===16750.0,
        '150 + 10 / 160 at x1.75 calculates approved revenue without changing worked hours');

    conflict(fn()=>$ctl->updateApprovedHours(patch([
        'hours'=>140,'expected_hours'=>160]),$period,11),409,
        'Stale regular hours overwritten by another AM');
    assertPeriod((float)$db::table('reporting_period_approved_hours')->first()->hours===150.0,
        'Stale write must be atomic and leave previous value unchanged');

    $ctl->updateApprovedHours(patch(['hours'=>170,'expected_hours'=>150]),$period,11);
    $shown=$ctl->show(\Illuminate\Http\Request::create('/','GET'),$period)->getData(true)['data'];
    assertPeriod((float)$shown['summary']['confirmed_hours']===170.0
        && (float)$shown['summary']['worked_hours']===160.0,
        'AM can approve 170 regular hours on 160 actually worked without validation block');

    $db::table('reporting_periods')->where('id',$period)->update(['status'=>'Счет оплачен']);
    conflict(fn()=>$ctl->updateApprovedHours(patch(['overtime_hours'=>20]),$period,11),422,
        'Paid period must lock hours');
    conflict(fn()=>$ctl->updateOvertimeMultiplier(patch(['overtime_multiplier'=>2]),$period),423,
        'Paid period must lock multiplier');
    assertPeriod($db::table('reporting_period_history')->where('reporting_period_id',$period)
        ->count()>=4,'Approved hours and factor must have audit events');
    echo "Clients reporting overtime: periods, overrides, concurrency, locks and amounts OK\n";
}
