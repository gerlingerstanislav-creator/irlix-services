<?php
// Synthetic 63k paged source: three project rows per client/day.
require dirname(__DIR__).'/app/Migration/Core/TimesheetsFactStage.php';
$stage=new \App\Migration\Core\TimesheetsFactStage();
$epoch=new DateTimeImmutable('2000-01-01');
$rate=['client_member_id'=>501,'employee_id'=>10,'partner_specialist_id'=>null,'client_id'=>91];
for($page=0;$page<63;$page++)for($offset=0;$offset<1000;$offset++){
    $n=$page*1000+$offset;$date=$epoch->modify('+'.intdiv($n,3).' days')->format('Y-m-d');
    $cents=[233,200,300][$n%3];
    $stage->add((string)($n+1),['id'=>$n+1,'description'=>'Synthetic source '.($n+1),'hours'=>$cents/100],$rate,$date,601,$cents);
}
$groups=0;$sources=0;$sum=0;
foreach($stage->groups() as $group){
    if((int)$group['cents']!==733||count($group['source'])!==3)throw new RuntimeException('Cross-page group lost precision or sources');
    foreach($group['source'] as $source)if($source['row']['description']!=='Synthetic source '.$source['id'])throw new RuntimeException('Description lineage corrupted');
    ++$groups;$sources+=count($group['source']);$sum+=(int)$group['cents'];
}
if($groups!==21000||$sources!==63000||$sum!==15393000)throw new RuntimeException('Large aggregate reconciliation failed');
$days=0;foreach($stage->dailyTotals() as $cents){if($cents!==733)throw new RuntimeException('Daily total drift');++$days;}
if($days!==21000)throw new RuntimeException('Day inventory drift');
if(memory_get_peak_usage(true)>64*1024*1024)throw new RuntimeException('Stage retained unbounded source rows');
echo '63k source stage: 63 pages, 21000 groups, exact 153930 hours, all descriptions/IDs, peak '.round(memory_get_peak_usage(true)/1024/1024,1)." MiB PASS\n";
