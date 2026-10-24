<?php
/** No writes, credentials, names, UUIDs, or per-person IDs in public logs. */
use Illuminate\Support\Facades\DB;
use App\Migration\Core\TimesheetsMemberMatching;
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
  $conn=DB::connection();
  $groups=$conn->table('migration_conflicts')->where('migration_run_id',306)
    ->where('service','timesheets')->select('entity_type','code',DB::raw('count(*) as n'))
    ->groupBy('entity_type','code')->get();
  foreach($groups as $g) echo json_encode(['category'=>$g->entity_type,'code'=>$g->code,'count'=>$g->n])."\n";
  $ids=$conn->table('migration_conflicts')->where('migration_run_id',306)
    ->where('service','timesheets')->where('entity_type','members')
    ->where('code','MEMBER_IDENTITY_UNRESOLVED')->distinct()->pluck('legacy_id');
  $counts=['members'=>count($ids),'inspect_ok'=>0,'inspect_fail'=>0,'name_unique'=>0,
    'name_none'=>0,'name_ambiguous'=>0,'period_unique'=>0,'period_none'=>0,'period_ambiguous'=>0,
    'legacy_clients_mapping_unique'=>0,'legacy_clients_mapping_none'=>0,'legacy_clients_mapping_ambiguous'=>0,
    'intersection_unique'=>0,'intersection_none'=>0,'intersection_ambiguous'=>0,
    'override_present'=>0,'prior_mapping_present'=>0,'diagnoses'=>[],'selection'=>[]];
  $legacyClients=new \App\Migration\Core\LegacyReader('clients');$legacyClients->assertSafe();
  $users=$legacyClients->select('SELECT id,external_key FROM public.users',[],false);
  $byUuid=[];foreach($users as $u)$byUuid[mb_strtolower(trim((string)$u->external_key))][]=(string)$u->id;
  foreach($ids as $id){
    try{
      $d=TimesheetsMemberMatching::inspect((string)$id);
      $counts['inspect_ok']++;
      $sel=$d['selection']??[];
      $diagnosis=(string)($d['diagnosis']['code']??'unknown');
      $counts['diagnoses'][$diagnosis]=($counts['diagnoses'][$diagnosis]??0)+1;
      $selector=(string)(($sel['selected_id']??null)===null?'null':'selected');
      $counts['selection'][$selector]=($counts['selection'][$selector]??0)+1;
      $cands=$d['candidates']??[];
      $project=mb_strtolower(trim((string)($d['source']['project']??'')));
      $byName=[];$byPeriod=[];
      foreach($cands as $c){
        $name=mb_strtolower(trim((string)($c['client_name']??'')));
        $names=array_map(static fn($n)=>mb_strtolower(trim((string)$n)),$c['project_names']??[]);
        if($project!==''&&($name===$project||in_array($project,$names,true)))$byName[(int)$c['target_id']]=true;
        if($c['period_matches']??false)$byPeriod[(int)$c['target_id']]=true;
      }
      foreach(['name'=>$byName,'period'=>$byPeriod] as $kind=>$set)$counts[$kind.'_'.(count($set)===1?'unique':(count($set)===0?'none':'ambiguous'))]++;
      $read=new \App\Migration\Core\LegacyReader('timesheets');$read->assertSafe();
      $src=$read->select('SELECT employee_id FROM public.members WHERE id=? LIMIT 1',[(int)$id],false);
      $uuid=mb_strtolower(trim((string)($src[0]->employee_id??'')));
      $matched=[];
      foreach($byUuid[$uuid]??[] as $userId){
        $rows=$legacyClients->select('SELECT id FROM public.members WHERE memberable_type=? AND memberable_id=?',['employee',(int)$userId],false);
        foreach($rows as $r){
          $target=$conn->table('migration_mappings')->where('service','clients')->where('entity_type','members')
            ->where('legacy_id',(string)$r->id)->value('target_id');
          if($target!==null)$matched[(int)$target]=true;
        }
      }
      $idsAll=[];foreach($cands as $c)$idsAll[(int)$c['target_id']]=true;
      if ((string)$id === '240') {
        $tReader=new \App\Migration\Core\LegacyReader('timesheets');$tReader->assertSafe();
        $sourceRates=$tReader->select('SELECT id FROM public.rates WHERE member_id=?',[(int)$id],false);
        $rateIds=array_map(static fn($r)=>(int)$r->id,$sourceRates);
        $factDates=[];
        if ($rateIds) {
          $in=implode(',',array_fill(0,count($rateIds),'?'));
          $factRows=$tReader->select('SELECT DISTINCT date FROM public.timesheets WHERE rate_id IN ('.$in.')',$rateIds,false);
          foreach($factRows as $fr)$factDates[substr((string)$fr->date,0,10)]=true;
        }
        $targetMap=$conn->table('migration_mappings')->where('service','clients')->where('entity_type','members')->get(['legacy_id','target_id']);
        $legacyByTarget=[];foreach($targetMap as $map)$legacyByTarget[(int)$map->target_id][]=(string)$map->legacy_id;
        $score=[];
        foreach([44,125,293,351] as $target){
          $oldIds=$legacyByTarget[$target]??[];
          $windows=[];
          if($oldIds){
            $in=implode(',',array_fill(0,count($oldIds),'?'));
            $windows=$legacyClients->select('SELECT member_id,start_date,end_date FROM public.rates WHERE member_id IN ('.$in.')',$oldIds,false);
          }
          $covered=0;$outside=0;
          foreach(array_keys($factDates) as $date){
            $matches=0;
            foreach($windows as $w)if($date>=substr((string)$w->start_date,0,10)&&($w->end_date===null||$date<=substr((string)$w->end_date,0,10)))$matches++;
            if($matches>0)$covered++;else $outside++;
          }
          $score[]=['target_client_member_id'=>$target,'legacy_client_connections'=>count($oldIds),'legacy_rate_windows'=>count($windows),'fact_days'=>count($factDates),'covered_by_old_clients'=>$covered,'outside_old_clients'=>$outside,'exact_project_name'=>isset($byName[$target])];
        }
        echo json_encode(['case'=>'member_240_legacy_periods','scores'=>$score],JSON_THROW_ON_ERROR)."\\n";
      }
      $periodIds=array_keys($byPeriod);sort($periodIds);
      $nameIds=array_keys($byName);sort($nameIds);
      $mappedIds=array_keys($matched);sort($mappedIds);
      $fullIds=array_keys($idsAll);sort($fullIds);
      $bothIds=array_keys(array_intersect_key($matched,$byPeriod));sort($bothIds);
      echo json_encode(['case'=>'member_306','legacy_member_id'=>(string)$id,
        'candidate_client_member_ids'=>$fullIds,'period_client_member_ids'=>$periodIds,
        'project_client_member_ids'=>$nameIds,'mapped_client_member_ids'=>$mappedIds,
        'period_and_mapping_ids'=>$bothIds], JSON_THROW_ON_ERROR)."\n";
      $n=count($matched);
      $counts['legacy_clients_mapping_'.($n===1?'unique':($n===0?'none':'ambiguous'))]++;
      $intersection=array_intersect_key($matched,$idsAll);
      $counts['intersection_'.(count($intersection)===1?'unique':(count($intersection)===0?'none':'ambiguous'))]++;
      if($conn->table('migration_overrides')->where('service','timesheets')->where('entity_type','members')->where('legacy_id',(string)$id)->exists())$counts['override_present']++;
      if($conn->table('migration_mappings')->where('service','timesheets')->where('entity_type','members')->where('legacy_id',(string)$id)->exists())$counts['prior_mapping_present']++;
    }catch(Throwable $e){$counts['inspect_fail']++;echo json_encode(['probe_error'=>get_class($e)])."\n";}
  }
  echo json_encode(['check'=>'timesheets306','result'=>$counts])."\n";
}catch(Throwable $e){echo json_encode(['fatal_type'=>get_class($e),'line'=>$e->getLine()])."\n";exit(2);}
