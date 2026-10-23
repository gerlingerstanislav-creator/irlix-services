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

    $app = new \Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-partner-directory-smoke');
    $app->instance('request', \Illuminate\Http\Request::create('/'));
    $app->instance('config', new \Illuminate\Config\Repository([
        'database' => ['default'=>'sqlite', 'connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
        'view' => ['paths'=>[],'compiled'=>sys_get_temp_dir()],
        'app' => ['locale'=>'ru','fallback_locale'=>'en'],
    ]));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    foreach ([\Illuminate\Filesystem\FilesystemServiceProvider::class, \Illuminate\View\ViewServiceProvider::class,
        \Illuminate\Database\DatabaseServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class,
        \Illuminate\Validation\ValidationServiceProvider::class] as $provider) $app->register($provider);
    \Illuminate\Http\Request::macro('validate', function (array $rules): array {
        return app('validator')->make($this->all(), $rules)->validate();
    });

    $schema = \Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
    $schema->create('partners', function($t) { $t->id(); $t->string('name'); $t->string('contact_name')->nullable(); $t->string('phone')->nullable(); $t->string('email')->nullable(); $t->text('comment')->nullable(); $t->timestamps(); });
    $schema->create('partner_specialists', function($t) { $t->id(); $t->unsignedBigInteger('partner_id')->nullable(); $t->string('full_name'); $t->string('specialization')->nullable(); $t->text('comment')->nullable(); $t->timestamps(); });
    $schema->create('project_members', function($t) { $t->id(); $t->unsignedBigInteger('partner_specialist_id')->nullable(); $t->string('specialist_name'); });

    class TestPartnerAccess extends \App\Support\ClientContourAccess {
        public bool $allowed = true;
        public function resolve(\Illuminate\Http\Request $request): array {
            return [
                'platform_admin'=>false,
                'permissions'=>[
                    'partners.view'=>['allowed'=>$this->allowed,'scope'=>'all'],
                    'partners.manage'=>['allowed'=>$this->allowed,'scope'=>'all'],
                ],
            ];
        }
    }
    $controller = new \App\Http\Controllers\PartnersController();
    $access = new TestPartnerAccess();
    $request = fn (array $data = [], string $method = 'POST') => \Illuminate\Http\Request::create('/', $method, $data);
    $expect = fn(bool $ok,string $message) => $ok ?: throw new \RuntimeException($message);
    $partnerId = $controller->store($request(['name'=>' Test Agency ','contact_name'=>'Test Contact']), $access)->getData(true)['data']['id'];
    $expect($partnerId > 0, 'Partner insert');
    $first = $controller->storeSpecialist($request(['full_name'=>'Name One','specialization'=>'PHP']), $access,$partnerId)->getData(true)['data']['id'];
    $second = $controller->storeSpecialist($request(['full_name'=>'Name One']), $access,$partnerId)->getData(true)['data']['id'];
    $expect($first !== $second,'Equal names must not coalesce identities');
    $list=$controller->index($request([],'GET'),$access)->getData(true)['data'];
    $expect(count($list['partners']) === 1 && count($list['partners'][0]['specialists']) === 2,'Nested partner specialists');
    $expect($list['partners'][0]['name'] === 'Test Agency','Whitespace normalized');
    // Historic external specialists without an organization must remain visible.
    \Illuminate\Support\Facades\DB::table('partner_specialists')->insert(['full_name'=>'Legacy Person']);
    $list=$controller->index($request([],'GET'),$access)->getData(true)['data'];
    $expect(count($list['unassigned']) === 1,'Legacy orphan visible');
    $other = $controller->store($request(['name'=>'Second Partner']),$access)->getData(true)['data']['id'];
    $controller->updateSpecialist($request(['partner_id'=>$other],'PATCH'),$access,$first);
    $expect((int) \Illuminate\Support\Facades\DB::table('partner_specialists')->find($first)->partner_id === $other, 'Move specialist');
    \Illuminate\Support\Facades\DB::table('project_members')->insert(['partner_specialist_id'=>$first,'specialist_name'=>'Historical Snapshot']);
    $controller->updateSpecialist($request(['full_name'=>'Name Changed'],'PATCH'),$access,$first);
    $expect(\Illuminate\Support\Facades\DB::table('project_members')->value('specialist_name') === 'Historical Snapshot','Historic connection snapshot intact');
    $access->allowed=false;
    try { $controller->index($request([],'GET'),$access); throw new \RuntimeException('Unauthorized read allowed'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $expect($e->getStatusCode()===403,'Read permission denied'); }
    try { $controller->update($request(['name'=>'Malicious'],'PATCH'),$access,$partnerId); throw new \RuntimeException('Unauthorized write allowed'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $expect($e->getStatusCode()===403,'Write permission denied'); }
    echo "Partner directory passed: CRUD, history, tree, auth and legacy identities.\n";
}
