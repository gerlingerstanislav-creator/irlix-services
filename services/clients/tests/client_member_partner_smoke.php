<?php

namespace App\Http\Controllers { abstract class Controller {} }

namespace {
    require getenv('CLIENTS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class,'App\\')) {
            $path=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php';
            if (is_file($path)) require $path;
        }
    });
    $app=new \Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-client-member-partner');
    $app->instance('request', \Illuminate\Http\Request::create('/'));
    $app->instance('config', new \Illuminate\Config\Repository([
        'database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
        'view'=>['paths'=>[],'compiled'=>sys_get_temp_dir()],
        'app'=>['locale'=>'en','fallback_locale'=>'en'],
    ]));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    foreach ([\Illuminate\Filesystem\FilesystemServiceProvider::class,\Illuminate\View\ViewServiceProvider::class,
        \Illuminate\Database\DatabaseServiceProvider::class,\Illuminate\Translation\TranslationServiceProvider::class,
        \Illuminate\Validation\ValidationServiceProvider::class] as $provider)$app->register($provider);
    \Illuminate\Http\Request::macro('validate',function(array $rules):array{
        return app('validator')->make($this->all(),$rules)->validate();
    });

    $db=\Illuminate\Support\Facades\DB::class;
    $schema=$db::connection()->getSchemaBuilder();
    $schema->create('clients',function($t){$t->id();$t->string('name');$t->unsignedBigInteger('account_employee_id')->nullable();});
    $schema->create('projects',function($t){$t->id();$t->unsignedBigInteger('client_id');$t->string('name');});
    $schema->create('partner_specialists',function($t){$t->id();$t->string('full_name');});
    $schema->create('client_members',function($t){$t->id();$t->unsignedBigInteger('client_id');$t->unsignedBigInteger('employee_id')->nullable();$t->unsignedBigInteger('partner_specialist_id')->nullable();$t->string('specialist_name');$t->timestamps();$t->unique(['client_id','partner_specialist_id']);});
    $schema->create('member_terms',function($t){$t->id();$t->unsignedBigInteger('client_member_id')->nullable();$t->unsignedBigInteger('project_member_id')->nullable();$t->string('technology');$t->string('level');$t->decimal('hourly_rate',12,2)->nullable();$t->decimal('hours_per_day',5,2);$t->date('valid_from');$t->date('valid_to')->nullable();$t->timestamps();});
    $schema->create('project_assignments',function($t){$t->id();$t->unsignedBigInteger('client_member_id');$t->unsignedBigInteger('project_id');$t->date('valid_from');$t->date('valid_to')->nullable();$t->timestamps();});
    $schema->create('client_member_feedbacks',function($t){$t->id();$t->unsignedBigInteger('client_member_id');$t->unsignedBigInteger('project_id')->nullable();$t->text('text');$t->string('created_by_username')->nullable();$t->timestamps();});
    $schema->create('reporting_periods',function($t){$t->id();$t->unsignedBigInteger('client_id');$t->string('status');$t->date('period_start');$t->date('period_end');});
    $db::table('clients')->insert([['id'=>1,'name'=>'Synthetic A'],['id'=>2,'name'=>'Synthetic B']]);
    $db::table('projects')->insert([['id'=>1,'client_id'=>1,'name'=>'A1'],['id'=>2,'client_id'=>2,'name'=>'B1']]);
    $db::table('partner_specialists')->insert(['id'=>5,'full_name'=>'Synthetic Partner Person']);

    class MemberPartnerAccess extends \App\Support\ClientContourAccess {
        public function resolve(\Illuminate\Http\Request $request):array {
            return ['platform_admin'=>true,'permissions'=>[]];
        }
    }
    $ctl=new \App\Http\Controllers\ClientMembersController();
    $permission=new MemberPartnerAccess();
    function req(array $data=[],string $method='POST'): \Illuminate\Http\Request {
        return \Illuminate\Http\Request::create('/',$method,$data);
    }
    function memberCheck(bool $assertion,string $message):void {
        if(!$assertion) throw new RuntimeException($message);
    }
    function denied(callable $fn,int $code):void {
        try{$fn();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            memberCheck($e->getStatusCode()===$code,'Wrong rejection HTTP code'); return;
        }
        throw new RuntimeException('Expected rejected operation');
    }
    $valid=['specialist_type'=>'partner','partner_specialist_id'=>5,'technology'=>'PHP','level'=>'Senior',
        'hourly_rate'=>1200,'hours_per_day'=>8,'valid_from'=>'2026-01-01','valid_to'=>'2026-01-31'];
    $first=$ctl->store(req($valid),$permission,1)->getData(true)['data']['client_member_id'];
    memberCheck($first>0,'Direct partner connection without project');
    memberCheck($db::table('projects')->count()===2,'Connecting partner did not create projects');
    $again=$ctl->store(req([...$valid,'valid_from'=>'2026-02-01','valid_to'=>null]),$permission,1)
        ->getData(true)['data']['client_member_id'];
    memberCheck($first===$again,'Return must reuse permanent ClientMember');
    memberCheck($db::table('member_terms')->where('client_member_id',$first)->count()===2,'Two compatible periods');
    denied(fn()=>$ctl->store(req([...$valid,'valid_from'=>'2026-01-10','valid_to'=>'2026-01-20']),$permission,1),422);
    denied(fn()=>$ctl->projectAssignment(req(['project_id'=>2,'valid_from'=>'2026-01-01']),$permission,$first),422);
    $assign=$ctl->projectAssignment(req(['project_id'=>1,'valid_from'=>'2026-01-01','valid_to'=>'2026-01-31']),$permission,$first)
        ->getData(true)['data']['id'];
    memberCheck($assign>0,'Partner project assignment');
    denied(fn()=>$ctl->projectAssignment(req(['project_id'=>1,'valid_from'=>'2026-01-15','valid_to'=>'2026-02-03']),$permission,$first),422);
    denied(fn()=>$ctl->addFeedback(req(['project_id'=>2,'text'=>'Wrong client']),$permission,$first),422);
    $feedback=$ctl->addFeedback(req(['project_id'=>1,'text'=>'Project feedback']),$permission,$first)->getData(true)['data'];
    memberCheck((int)$feedback['project_id']===1,'Feedback linked to historically assigned project');
    $ctl->addFeedback(req(['text'=>'Client-wide feedback']),$permission,$first);
    memberCheck($db::table('client_member_feedbacks')->whereNull('project_id')->count()===1,'Client feedback without project');
    $list=$ctl->index(req([],'GET'),$permission,1)->getData(true)['data'];
    memberCheck(count($list)===1&&count($list[0]['terms'])===2&&count($list[0]['projects'])===1,'ClientMember nested list');
    $db::table('reporting_periods')->insert(['client_id'=>1,'status'=>'ТШ на согласовании','period_start'=>'2026-03-01','period_end'=>'2026-03-31']);
    denied(fn()=>$ctl->storeTerms(req([...$valid,'valid_from'=>'2026-03-01','valid_to'=>'2026-03-15']),$permission,$first),423);
    echo "ClientMember partner connection: stable identity, client-level terms, project assignments, feedback and locks OK.\n";
}
