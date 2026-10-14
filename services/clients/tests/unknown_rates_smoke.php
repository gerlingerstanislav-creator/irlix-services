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
    $app = new \Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-unknown-rates-smoke');
    $app->instance('request', \Illuminate\Http\Request::create('/'));
    $app->instance('config', new \Illuminate\Config\Repository([
        'database' => ['default'=>'sqlite', 'connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
        'view'=>['paths'=>[], 'compiled'=>sys_get_temp_dir()],
        'app'=>['locale'=>'en','fallback_locale'=>'en'],
    ]));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    foreach ([\Illuminate\Filesystem\FilesystemServiceProvider::class, \Illuminate\View\ViewServiceProvider::class,
        \Illuminate\Database\DatabaseServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class,
        \Illuminate\Validation\ValidationServiceProvider::class] as $provider) $app->register($provider);
    \Illuminate\Http\Request::macro('validate', function (array $rules): array {
        return app('validator')->make($this->all(), $rules)->validate();
    });
    function checkUnknown(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    $db=\Illuminate\Support\Facades\DB::class;
    $schema=$db::connection()->getSchemaBuilder();
    $schema->create('clients', function ($t) { $t->id(); $t->string('name'); $t->integer('account_employee_id')->nullable(); });
    $schema->create('projects', function ($t) { $t->id(); $t->integer('client_id'); $t->string('name')->nullable(); });
    $schema->create('project_members', function ($t) { $t->id(); $t->integer('project_id'); $t->integer('specialist_id'); $t->string('specialist_name'); });
    $schema->create('member_terms', function ($t) { $t->id(); $t->integer('project_member_id'); $t->decimal('hourly_rate')->nullable(); $t->date('valid_from'); $t->date('valid_to')->nullable(); });
    $schema->create('reporting_periods', function ($t) { $t->id(); $t->integer('client_id'); $t->date('period_start'); $t->date('period_end'); });
    $schema->create('reporting_period_history', function ($t) { $t->id(); $t->integer('reporting_period_id'); $t->string('type'); $t->string('title'); $t->text('text')->nullable(); $t->string('author')->nullable(); $t->timestamps(); });
    $schema->create('reporting_period_approved_hours', function ($t) { $t->id(); $t->integer('reporting_period_id'); $t->integer('member_term_id'); $t->decimal('hours',10,2); $t->string('updated_by')->nullable(); $t->timestamps(); });
    $db::table('clients')->insert(['id'=>1,'name'=>'Synthetic Client']);
    $db::table('projects')->insert(['id'=>1,'client_id'=>1]);
    $db::table('project_members')->insert(['id'=>1,'project_id'=>1,'specialist_id'=>1,'specialist_name'=>'Synthetic Specialist']);
    $db::table('member_terms')->insert(['id'=>1,'project_member_id'=>1,'hourly_rate'=>null,'valid_from'=>'2026-01-01','valid_to'=>'2026-01-31']);
    $db::table('reporting_periods')->insert(['id'=>1,'client_id'=>1,'period_start'=>'2026-01-01','period_end'=>'2026-01-31']);
    \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['data'=>[
        'entries'=>[['employee_id'=>1,'project_id'=>1,'work_date'=>'2026-01-12','hours'=>7.5]],
        'final_approvals'=>[['employee_id'=>1,'project_id'=>1,'month'=>'2026-01-01']],
    ]])]);
    $cash=new \App\Http\Controllers\CashFlowController();
    $period=new \App\Http\Controllers\ReportingPeriodsController();
    foreach ([null, 0, 1500] as $rate) {
        $db::table('member_terms')->where('id',1)->update(['hourly_rate'=>$rate]);
        $row=$cash->index(\Illuminate\Http\Request::create('/','GET',['month'=>'2026-01']))->getData(true)['data']['rows'][0];
        checkUnknown($row['timesheet_hours']===7.5 && $row['confirmed_hours']===7.5, 'Unknown rate must not hide worked/confirmed hours');
        $expected=$rate===null ? null : $rate*7.5;
        checkUnknown($rate===null ? $row['timesheet_amount']===null && $row['confirmed_amount']===null
            : (float)$row['timesheet_amount']===$expected && (float)$row['confirmed_amount']===$expected, 'Cash flow distinguishes unknown, zero and numeric rates');
        $data=$period->show(\Illuminate\Http\Request::create('/'),1)->getData(true)['data'];
        checkUnknown($data['summary']['worked_hours']===7.5 && $data['summary']['confirmed_hours']===7.5, 'Reporting period hours remain available');
        checkUnknown($rate===null ? $data['timesheets'][0]['hourly_rate']===null && $data['summary']['worked_amount']===null && $data['summary']['confirmed_amount']===null
            : (float)$data['summary']['worked_amount']===$expected && (float)$data['summary']['confirmed_amount']===$expected, 'Reporting period never sums unknown amounts as zero');
    }
    $request=\Illuminate\Http\Request::create('/', 'PATCH', ['hours'=>5.25]);
    $period->updateApprovedHours($request, 1, 1);
    $updated=$period->show(\Illuminate\Http\Request::create('/'),1)->getData(true)['data'];
    checkUnknown($updated['timesheets'][0]['confirmed_hours']===5.25 && $updated['timesheets'][0]['worked_hours']===7.5,
        'Approved hours can differ without overwriting worked hours');
    checkUnknown((float)$updated['summary']['confirmed_amount']===7875.0 && (float)$updated['summary']['worked_amount']===11250.0,
        'Approval override uses the term rate without changing worked amount');
    echo "Unknown imported rates: hours remain available; unknown, zero and known monetary amounts are distinct.\n";
}
