<?php
/** Synthetic 250k-row disk-sort contract: no business data, server or credentials. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$root = sys_get_temp_dir().'/timesheets-disk-'.bin2hex(random_bytes(8));
if (!mkdir($root,0700)) throw new RuntimeException('Unable to create private test directory');
$path = $root.'/rows.jsonl';
try {
    $out = fopen($path, 'wb');
    if ($out === false) throw new RuntimeException('Unable to create fixture');
    for ($i=249999; $i>=0; --$i) {
        $key = 'member:'.intdiv($i,5).':2026-01-05';
        $value = ['key'=>$key, 'id'=>$i+1, 'member'=>['target_id'=>intdiv($i,5)+1],
            'date'=>'2026-01-05','source'=>['id'=>$i+1, 'description'=>'synthetic'],
            'hours'=>1.25, 'term_id'=>null];
        fwrite($out,json_encode($value,JSON_THROW_ON_ERROR)."\n");
    }
    fclose($out);
    $class = new ReflectionClass(\App\Migration\Services\TimesheetsMigration::class);
    $worker = $class->newInstanceWithoutConstructor();
    $sort = $class->getMethod('sortedBucketGroups');
    $groups=0; $rows=0; $cents=0;
    foreach ($sort->invoke($worker,$path,$root) as $group) {
        ++$groups; $rows+=count($group['rows']);
        if (count($group['rows'])!==5) throw new RuntimeException('Split or duplicated group');
        foreach ($group['rows'] as $row) $cents+=(int)round($row['hours']*100);
    }
    if ($groups!==50000 || $rows!==250000 || $cents!==31250000)
        throw new RuntimeException('Disk group reconciliation mismatch');
    $peak=memory_get_peak_usage(true);
    if ($peak > 128*1048576) throw new RuntimeException('Unbounded memory usage');
    echo '250k disk groups: PASS; groups='.$groups.'; peak_mb='.round($peak/1048576)."\n";
} finally {
    if (is_file($path)) unlink($path);
    foreach (glob($root.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($root);
}
