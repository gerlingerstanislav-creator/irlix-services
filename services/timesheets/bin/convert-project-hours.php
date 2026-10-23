<?php
/**
 * Project → ClientMember fact rekey.
 * Default: read-only dry run. Apply only during coordinated maintenance and
 * after Clients + Timesheets expand migrations. Never runs on boot/deploy.
 *
 * php bin/convert-project-hours.php
 * TIMESHEETS_MAINTENANCE_LOCK=1 php bin/convert-project-hours.php --apply
 */
use App\Support\ProjectHoursConverter;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$result=app(ProjectHoursConverter::class)->run(in_array('--apply',$argv,true));
$stream=$result['conflict_count']>0?STDERR:STDOUT;
fwrite($stream,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
exit($result['conflict_count']>0?2:0);
