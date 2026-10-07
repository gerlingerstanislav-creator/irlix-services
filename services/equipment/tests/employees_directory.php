<?php
require __DIR__.'/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, (new ReflectionClass(Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
$router = new Router(new Dispatcher($app), $app);
$app->instance('router', $router);
require __DIR__.'/../routes/api.php';
$request = Request::create('/employees-directory', 'GET', [], [], [], ['HTTP_AUTHORIZATION'=>'Bearer synthetic-token']);
$request->attributes->set('identity', ['realm_roles'=>['system-admin']]);
$check = function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$fake = function ($status) {
    $factory = new Factory();
    Http::swap($factory);
    $factory->preventStrayRequests();
    $factory->fake([
        'http://employees:8000/api/equipment-directory' => $factory->response(['data'=>[['id'=>2,'full_name'=>'Synthetic Employee']]]),
        'http://employees:8000/api/equipment-directory/2' => $factory->response(['data'=>['employee'=>['id'=>2,'employment_status'=>$status]]]),
        'http://employees:8000/api/equipment-directory/999' => $factory->response(['message'=>'Employee not found'], 404),
    ]);
    return $factory;
};
$factory = $fake('Трудоустроен');
$response = ($router->getRoutes()->match($request)->getAction('uses'))($request);
$check($response->getStatusCode() === 200 && $response->getData(true)['data'][0]['id'] === 2, 'Equipment directory loading failed');
$check(equipmentEmployeeError($request, 2, true) === null, 'Valid issue rejected');
foreach ($factory->recorded() as [$sent, $response]) {
    $check($sent->hasHeader('Authorization', 'Bearer synthetic-token'), 'Caller token not forwarded');
    $check(str_contains($sent->url(), '/equipment-directory'), 'HR API still used');
}
$fake('Уволен');
$check(equipmentEmployeeError($request, 2, true)->getStatusCode() === 422, 'Issue to dismissed employee accepted');
$check(equipmentEmployeeError($request, 2) === null, 'Former employee rejected in historical record');
$check(equipmentEmployeeError($request, 999)->getStatusCode() === 422, 'Missing employee accepted');
echo "Equipment directory and assignment validation with forwarded identity: PASS\n";
