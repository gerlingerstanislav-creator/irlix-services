<?php

require __DIR__.'/../vendor/autoload.php';

use App\Services\RecruitmentCompensationPolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (\Throwable $error): void {
    fwrite(STDERR, 'Recruitment compensation policy FAIL: '.$error->getMessage()."\n");
    exit(1);
});

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$prevJwt = getenv('IRLIX_AUTHZ_JWT_ENABLED');
$prevLocal = getenv('IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED');
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');
putenv('IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED=true');

$policy = new RecruitmentCompensationPolicy();
$request = Request::create('/api/candidates', 'POST');
$request->attributes->set('recruitment_local_permissions', [
    'recruitment.workspace.view'=>true,
    'recruitment.candidates.manage'=>true,
    'recruitment.offers.manage'=>true,
    'recruitment.compensation.view'=>false,
    'recruitment.compensation.manage'=>false,
]);

$policy->assertWrite($request,['full_name'=>'Synthetic'],'salary_expectation');
foreach ([
    [['full_name'=>'Synthetic','salary_expectation'=>'private'],'salary_expectation'],
    [['salary_expectation'=>null],'salary_expectation'],
    [['compensation'=>'private'],'compensation'],
    [['compensation'=>null],'compensation'],
] as [$payload,$field]) {
    try {
        $policy->assertWrite($request,$payload,$field);
        throw new RuntimeException('Sensitive field was editable without a separate permission: '.$field);
    } catch (HttpException $expected) {
        $assert($expected->getStatusCode()===403,'Incorrect sensitive-field response');
    }
}
$record=(object)['role'=>'Synthetic','salary_expectation'=>'private','compensation'=>'private'];
$policy->redact($request,$record,'salary_expectation');
$policy->redact($request,$record,'compensation');
$assert($record->role==='Synthetic','Redaction removed public fields');
$assert($record->salary_expectation===null&&$record->compensation===null,'Sensitive response leaked');

$request->attributes->set('recruitment_local_permissions',[
    'recruitment.compensation.view'=>true,'recruitment.compensation.manage'=>true,
]);
$policy->assertWrite($request,['compensation'=>'private'],'compensation');
$visible=(object)['compensation'=>'visible'];
$policy->redact($request,$visible,'compensation');
$assert($visible->compensation==='visible','Permitted compensation was hidden');

if ($prevJwt === false) putenv('IRLIX_AUTHZ_JWT_ENABLED');
else putenv('IRLIX_AUTHZ_JWT_ENABLED='.$prevJwt);
if ($prevLocal === false) putenv('IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED');
else putenv('IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED='.$prevLocal);
echo "Recruitment compensation policy: PASS\n";
