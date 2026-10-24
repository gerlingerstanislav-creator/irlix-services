<?php
/**
 * Safe read-only experiment: legacy FK lineage and target Clients coverage.
 * Output only schema metadata and aggregated counts, no names or source rows.
 */
declare(strict_types=1);

use App\Migration\Core\LegacyReader;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function out(string $name, array $data): void {
    echo json_encode(['check'=>$name]+$data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
}

foreach (['timesheets','clients'] as $service) {
    try {
        $legacy = new LegacyReader($service);
        $legacy->assertSafe();
        $tables = ['members','rates','timesheets'];
        if ($service === 'clients') $tables = ['members','projects','clients','subcontracts'];
        foreach ($tables as $table) {
            $q = $legacy->select("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema='public' AND table_name=?", [$table], false);
            if (empty($q) || (int)$q[0]->n === 0) continue;
            $columns = $legacy->select("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=? ORDER BY ordinal_position", [$table], false);
            out('legacy_schema', ['service'=>$service,'table'=>$table,'columns'=>array_map(fn($v)=>(string)$v->column_name,$columns)]);
        }
        $fk = $legacy->select("SELECT tc.table_name,kcu.column_name,ccu.table_name AS target_table,ccu.column_name AS target_column FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name AND tc.table_schema=kcu.table_schema JOIN information_schema.constraint_column_usage ccu ON tc.constraint_name=ccu.constraint_name AND tc.table_schema=ccu.table_schema WHERE tc.table_schema='public' AND tc.constraint_type='FOREIGN KEY' ORDER BY tc.table_name,kcu.column_name", [], false);
        foreach ($fk as $row) out('legacy_fk',['service'=>$service,'table'=>(string)$row->table_name,'column'=>(string)$row->column_name,'target_table'=>(string)$row->target_table,'target_column'=>(string)$row->target_column]);
    } catch (Throwable $e) { out('legacy_unavailable',['service'=>$service,'error_type'=>get_class($e)]); }
}
foreach (['client_members','member_terms','partner_specialists','projects'] as $table) {
    try {
        $conn=DB::connection('target_clients');
        $count=$conn->table($table)->count();
        out('target_count',['table'=>$table,'rows'=>$count]);
    } catch (Throwable $e) { out('target_unavailable',['table'=>$table,'error_type'=>get_class($e)]); }
}

try {
    $ts = new LegacyReader('timesheets');
    $cl = new LegacyReader('clients');
    $ts->assertSafe();
    $cl->assertSafe();
    $tMembers = $ts->select("SELECT id, employee_id, name, project, memberable_type FROM public.members", [], false);
    $cSubcontracts = $cl->select("SELECT id,employee_id,surname,name,patronymic FROM public.subcontracts", [], false);
    $cMembers = $cl->select("SELECT id,memberable_id,memberable_type,client_id,project_id FROM public.members", [], false);
    $byUuid = [];
    foreach ($cSubcontracts as $sub) {
        $uuid = mb_strtolower(trim((string)($sub->employee_id ?? '')));
        if ($uuid !== '') $byUuid[$uuid][] = (string)$sub->id;
    }
    $attached = [];
    foreach ($cMembers as $cm) {
        $type=mb_strtolower((string)$cm->memberable_type);
        if (!str_contains($type,'subcontract') && !str_contains($type,'partner')) continue;
        $attached[(string)$cm->memberable_id][] = [(string)$cm->client_id,(string)$cm->project_id];
    }
    $stats = ['external_total'=>0,'with_uuid'=>0,'uuid_unique_subcontract'=>0,
        'uuid_ambiguous'=>0,'uuid_missing'=>0,'uuid_one_with_client_attachment'=>0,
        'uuid_one_without_client_attachment'=>0,'no_uuid'=>0];
    foreach ($tMembers as $m) {
        $type=mb_strtolower((string)$m->memberable_type);
        if (!in_array($type,['subcontract','contractor','external','partner'],true)) continue;
        $stats['external_total']++;
        $uuid=mb_strtolower(trim((string)($m->employee_id ?? '')));
        if ($uuid==='') { $stats['no_uuid']++; continue; }
        $stats['with_uuid']++;
        $matches=array_unique($byUuid[$uuid]??[]);
        if (count($matches)>1) { $stats['uuid_ambiguous']++; continue; }
        if (!$matches) { $stats['uuid_missing']++; continue; }
        $stats['uuid_unique_subcontract']++;
        $sid=reset($matches);
        if (!empty($attached[$sid])) $stats['uuid_one_with_client_attachment']++;
        else $stats['uuid_one_without_client_attachment']++;
    }
    out('cross_legacy_partner_identity', $stats);
} catch (Throwable $e) {
    out('cross_legacy_unavailable',['error_type'=>get_class($e)]);
}

try {
    $ts = new LegacyReader('timesheets');
    $cl = new LegacyReader('clients');
    $ts->assertSafe(); $cl->assertSafe();
    $members = $ts->select("SELECT id,name,project,memberable_type FROM public.members", [], false);
    $subs = $cl->select("SELECT id,surname,name,patronymic FROM public.subcontracts", [], false);
    $attachments = $cl->select("SELECT memberable_id,memberable_type,client_id,project_id FROM public.members", [], false);
    $normalize = static fn(string $v): string => preg_replace('/\s+/u',' ',mb_strtolower(trim($v)));
    $byName=[];
    foreach ($subs as $sub) {
        $nm=$normalize(implode(' ',array_filter([(string)$sub->surname,(string)$sub->name,(string)$sub->patronymic])));
        if ($nm!=='') $byName[$nm][]=(string)$sub->id;
    }
    $linked=[];
    foreach ($attachments as $a) {
        $type=mb_strtolower((string)$a->memberable_type);
        if (str_contains($type,'subcontract')||str_contains($type,'partner'))
            $linked[(string)$a->memberable_id][]=[(string)$a->client_id,(string)$a->project_id];
    }
    $stats=['external_total'=>0,'blank_name'=>0,'exact_full_name_unique'=>0,
        'exact_full_name_ambiguous'=>0,'exact_full_name_unmatched'=>0,
        'unique_name_has_clients_member'=>0,'unique_name_no_clients_member'=>0];
    foreach ($members as $m) {
        if (!in_array(mb_strtolower((string)$m->memberable_type),['subcontract','contractor','external','partner'],true)) continue;
        $stats['external_total']++;
        $name=$normalize((string)$m->name);
        if($name===''){ $stats['blank_name']++; continue; }
        $ids=array_unique($byName[$name]??[]);
        if(count($ids)>1){ $stats['exact_full_name_ambiguous']++; continue; }
        if(!$ids){ $stats['exact_full_name_unmatched']++; continue; }
        $stats['exact_full_name_unique']++;
        if(!empty($linked[reset($ids)])) $stats['unique_name_has_clients_member']++;
        else $stats['unique_name_no_clients_member']++;
    }
    out('cross_legacy_exact_name_coverage',$stats);
} catch(Throwable $e){ out('cross_legacy_name_unavailable',['error_type'=>get_class($e)]); }

try {
  $ts=new LegacyReader('timesheets'); $cl=new LegacyReader('clients');
  $ts->assertSafe(); $cl->assertSafe();
  $tm=$ts->select("SELECT id,name,project,memberable_type FROM public.members",[],false);
  $sc=$cl->select("SELECT id,surname,name,patronymic FROM public.subcontracts",[],false);
  $cm=$cl->select("SELECT memberable_id,memberable_type,client_id,project_id FROM public.members",[],false);
  $cp=$cl->select("SELECT id,client_id,title FROM public.projects",[],false);
  $cc=$cl->select("SELECT id,title FROM public.clients",[],false);
  $norm=static fn($s)=>trim(preg_replace('/\s+/u',' ',mb_strtolower((string)$s)));
  $token=static fn($s)=>array_values(array_filter(explode(' ',$norm($s))));
  $subNames=[];$subNameParts=[];
  foreach($sc as $s){$id=(string)$s->id;$name=$norm(implode(' ',array_filter([(string)$s->surname,(string)$s->name,(string)$s->patronymic])));$subNames[$id]=$name;$subNameParts[$id]=$token($name);}
  $cNames=[];foreach($cc as $c)$cNames[(string)$c->id]=$norm($c->title);
  $pNames=[];foreach($cp as $p)$pNames[(string)$p->id]=$norm($p->title);
  $edges=[];foreach($cm as $m){
    $kind=mb_strtolower((string)$m->memberable_type);
    if(!str_contains($kind,'subcontract')&&!str_contains($kind,'partner'))continue;
    $edges[(string)$m->memberable_id][]=[$cNames[(string)$m->client_id]??'', $pNames[(string)$m->project_id]??''];
  }
  $stats=['total'=>0,'exact_name_plus_project_unique'=>0,'exact_name_plus_project_ambiguous'=>0,
    'exact_name_plus_project_no_match'=>0,'surname_first_initial_unique'=>0,'surname_first_initial_ambiguous'=>0,
    'surname_first_initial_no_match'=>0,'project_only_unique'=>0,'project_only_ambiguous'=>0,
    'project_only_no_match'=>0,'name_token_similarity_unique'=>0,'name_token_similarity_ambiguous'=>0,
    'name_token_similarity_no_match'=>0];
  $patterns=[];
  foreach($tm as $t){
    if(!in_array(mb_strtolower((string)$t->memberable_type),['subcontract','contractor','external','partner'],true))continue;
    $stats['total']++;$name=$norm($t->name);$project=$norm($t->project);$parts=$token($name);
    $exact=[];$abbr=[];$similar=[];$projectOnly=[];
    foreach($subNames as $sid=>$targetName){
      $connections=$edges[$sid]??[];
      $projectHit=false;
      foreach($connections as [$cn,$pn])if($project!==''&&($project===$cn||$project===$pn))$projectHit=true;
      if($projectHit)$projectOnly[$sid]=true;
      if($targetName===$name&&$projectHit)$exact[$sid]=true;
      $st=$subNameParts[$sid];$initialHit=count($parts)>=2&&count($st)>=2
        &&$parts[0]===$st[0]&&mb_substr($parts[1],0,1)===mb_substr($st[1],0,1);
      if($initialHit&&$projectHit)$abbr[$sid]=true;
      $overlap=count(array_intersect($parts,$st));
      if($projectHit&&$overlap>=2)$similar[$sid]=true;
    }
    foreach(['exact_name_plus_project'=>$exact,'surname_first_initial'=>$abbr,'project_only'=>$projectOnly,'name_token_similarity'=>$similar] as $k=>$matches){
      $n=count($matches);$stats[$k.'_'.($n===1?'unique':($n>1?'ambiguous':'no_match'))]++;
    }
  }
  out('cross_legacy_multisignal_coverage',$stats);
}catch(Throwable $e){out('cross_legacy_multisignal_error',['type'=>get_class($e),'line'=>$e->getLine(),'file'=>basename($e->getFile())]);}

try {
  $ts=new LegacyReader('timesheets');$cl=new LegacyReader('clients');$ts->assertSafe();$cl->assertSafe();
  $tm=$ts->select("SELECT id,name,project,memberable_type FROM public.members",[],false);
  $subs=$cl->select("SELECT id,surname,name,patronymic FROM public.subcontracts",[],false);
  $attachments=$cl->select("SELECT id,memberable_id,memberable_type,client_id,project_id FROM public.members",[],false);
  $projects=$cl->select("SELECT id,title FROM public.projects",[],false);
  $clients=$cl->select("SELECT id,title FROM public.clients",[],false);
  $facts=$ts->select("SELECT DISTINCT r.member_id,t.date FROM public.rates r JOIN public.timesheets t ON t.rate_id=r.id",[],false);
  $norm=static fn($s)=>trim(preg_replace('/\s+/u',' ',mb_strtolower((string)$s)));
  $tokens=static fn($s)=>array_values(array_filter(explode(' ',$norm($s))));
  $subNames=[];foreach($subs as $s)$subNames[(string)$s->id]=$tokens(implode(' ',array_filter([(string)$s->surname,(string)$s->name,(string)$s->patronymic])));
  $projNames=[];foreach($projects as $p)$projNames[(string)$p->id]=$norm($p->title);
  $clientNames=[];foreach($clients as $c)$clientNames[(string)$c->id]=$norm($c->title);
  $attached=[];foreach($attachments as $a){
    if(!in_array(mb_strtolower((string)$a->memberable_type),['subcontract','partner'],true))continue;
    $attached[(string)$a->memberable_id][]= $a;
  }
  $factDates=[];foreach($facts as $v)$factDates[(string)$v->member_id][$norm($v->date)]=true;
  $allMaps=DB::table('migration_mappings')->where('service','clients')->whereIn('entity_type',['subcontracts','members'])->get(['entity_type','legacy_id','target_id']);
  $maps=[];foreach($allMaps as $map)$maps[(string)$map->entity_type][(string)$map->legacy_id]=(int)$map->target_id;
  $targetMembers=DB::connection('target_clients')->table('client_members')->get(['id','client_id','partner_specialist_id']);
  $targetMemberIds=[];foreach($targetMembers as $m)$targetMemberIds[(int)$m->id]=$m;
  $terms=DB::connection('target_clients')->table('member_terms')->get(['id','client_member_id','valid_from','valid_to']);
  $byTarget=[];foreach($terms as $t)$byTarget[(int)$t->client_member_id][]=$t;
  $stats=['external'=>0,'identity_unique_by_name_project'=>0,'no_unique_identity'=>0,
   'subcontract_mapping_present'=>0,'subcontract_mapping_absent'=>0,
   'member_target_unique'=>0,'member_target_missing'=>0,'member_target_ambiguous'=>0,
   'member_target_partner_mismatch'=>0,'no_facts'=>0,'fully_covered_dates'=>0,
   'dates_missing_terms'=>0,'dates_overlapping_terms'=>0,'fact_date_count'=>0];
  foreach($tm as $m){
    if(!in_array(mb_strtolower((string)$m->memberable_type),['subcontract','contractor','external','partner'],true))continue;
    $stats['external']++;$parts=$tokens($m->name);$project=$norm($m->project);$candidates=[];
    foreach($subNames as $sid=>$sp){
      if(count($parts)<2||count($sp)<2||$parts[0]!==$sp[0]||mb_substr($parts[1],0,1)!==mb_substr($sp[1],0,1))continue;
      foreach($attached[$sid]??[] as $a){
        if($project!==''&&($project===($projNames[(string)$a->project_id]??'')||$project===($clientNames[(string)$a->client_id]??'')))
          $candidates[$sid][]=$a;
      }
    }
    if(count($candidates)!==1){$stats['no_unique_identity']++;continue;}
    $stats['identity_unique_by_name_project']++;
    $sid=array_key_first($candidates);
    $partnerTarget=$maps['subcontracts'][$sid]??null;
    if(!$partnerTarget){$stats['subcontract_mapping_absent']++;continue;}
    $stats['subcontract_mapping_present']++;
    $targetIds=[];
    foreach($candidates[$sid] as $a){
      $id=$maps['members'][(string)$a->id]??null;
      if($id)$targetIds[(int)$id]=true;
    }
    if(!$targetIds){$stats['member_target_missing']++;continue;}
    if(count($targetIds)!==1){$stats['member_target_ambiguous']++;continue;}
    $targetId=array_key_first($targetIds);
    if(!isset($targetMemberIds[$targetId])||(int)$targetMemberIds[$targetId]->partner_specialist_id!==(int)$partnerTarget){
      $stats['member_target_partner_mismatch']++;continue;
    }
    $stats['member_target_unique']++;
    $dates=array_keys($factDates[(string)$m->id]??[]);
    if(!$dates){$stats['no_facts']++;continue;}
    $bad=false;
    foreach($dates as $date){
      $stats['fact_date_count']++;
      $match=0;
      foreach($byTarget[$targetId]??[] as $term)
        if($date>=substr((string)$term->valid_from,0,10)&&($term->valid_to===null||$date<=substr((string)$term->valid_to,0,10)))$match++;
      if($match===0){$stats['dates_missing_terms']++;$bad=true;}
      if($match>1){$stats['dates_overlapping_terms']++;$bad=true;}
    }
    if(!$bad)$stats['fully_covered_dates']++;
  }
  out('partner_lineage_target_and_terms',$stats);
}catch(Throwable $e){
  out('partner_lineage_error',['error_type'=>get_class($e),'line'=>$e->getLine()]);
}

try {
  $ids=DB::table('migration_conflicts')->where('migration_run_id',292)
    ->where('service','timesheets')->where('entity_type','members')
    ->where('code','MEMBER_IDENTITY_UNRESOLVED')->distinct()->pluck('legacy_id');
  $stats=['conflicted_staff_ids'=>count($ids),'inspect_success'=>0,'inspect_failed'=>0,
    'project_exact_candidate_unique'=>0,'project_exact_candidate_zero'=>0,'project_exact_candidate_ambiguous'=>0,
    'period_unique_candidate'=>0,'period_no_candidate'=>0,'period_ambiguous'=>0];
  foreach($ids as $id){
    try {
      $a=\App\Migration\Core\TimesheetsMemberMatching::inspect((string)$id);
      $stats['inspect_success']++;
      $p=mb_strtolower(trim((string)($a['source']['project']??'')));
      $byName=0;$byPeriod=0;
      foreach($a['candidates'] as $c){
        $names=array_map(static fn($s)=>mb_strtolower(trim((string)$s)),$c['project_names']??[]);
        if($p!==''&&($p===mb_strtolower(trim((string)($c['client_name']??'')))||in_array($p,$names,true)))$byName++;
        if($c['period_matches']??false)$byPeriod++;
      }
      $stats['project_exact_candidate_'.($byName===1?'unique':($byName===0?'zero':'ambiguous'))]++;
      $stats['period_'.($byPeriod===1?'unique_candidate':($byPeriod===0?'no_candidate':'ambiguous'))]++;
    } catch(Throwable $e){$stats['inspect_failed']++;}
  }
  out('staff_292_candidate_analysis',$stats);
  $rates=DB::table('migration_conflicts')->where('migration_run_id',292)
    ->where('service','timesheets')->where('entity_type','rates')
    ->where('code','TARGET_TERM_GAP')->distinct()->pluck('legacy_id');
  out('rate_gap_292_count',['distinct_rate_ids'=>count($rates)]);
}catch(Throwable $e){out('staff_292_error',['type'=>get_class($e),'line'=>$e->getLine()]);}

try {
 $cl=new LegacyReader('clients');$ts=new LegacyReader('timesheets');$cl->assertSafe();$ts->assertSafe();
 $cols=$cl->select("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='users' ORDER BY ordinal_position",[],false);
 out('legacy_clients_users_columns',['columns'=>array_map(static fn($x)=>(string)$x->column_name,$cols)]);
 $cUsers=$cl->select("SELECT id,external_key FROM public.users",[],false);
 $cMembers=$cl->select("SELECT id,memberable_id,memberable_type,client_id,project_id FROM public.members",[],false);
 $projs=$cl->select("SELECT id,title FROM public.projects",[],false);
 $clients=$cl->select("SELECT id,title FROM public.clients",[],false);
 $tsMembers=$ts->select("SELECT id,employee_id,project,memberable_type FROM public.members",[],false);
 $normalize=static fn($x)=>trim(mb_strtolower((string)$x));
 $u=[];foreach($cUsers as $cu){$key=$normalize($cu->external_key);if($key!=='')$u[$key][]=(string)$cu->id;}
 $attachments=[];foreach($cMembers as $cm)if(mb_strtolower((string)$cm->memberable_type)==='employee')$attachments[(string)$cm->memberable_id][]=$cm;
 $pnames=[];foreach($projs as $p)$pnames[(string)$p->id]=$normalize($p->title);
 $cnames=[];foreach($clients as $c)$cnames[(string)$c->id]=$normalize($c->title);
 $ids=DB::table('migration_conflicts')->where('migration_run_id',292)->where('service','timesheets')->where('entity_type','members')->where('code','MEMBER_IDENTITY_UNRESOLVED')->distinct()->pluck('legacy_id')->map(static fn($x)=>(string)$x)->all();
 $byId=[];foreach($tsMembers as $tm)$byId[(string)$tm->id]=$tm;
 $maps=DB::table('migration_mappings')->where('service','clients')->where('entity_type','members')->pluck('target_id','legacy_id')->all();
 $stats=['staff_errors'=>count($ids),'legacy_clients_uuid_unique'=>0,'legacy_clients_uuid_absent'=>0,'legacy_clients_uuid_ambiguous'=>0,
 'legacy_attached_all'=>0,'old_project_unique'=>0,'old_project_ambiguous'=>0,'old_project_absent'=>0,
 'target_member_unique_from_uuid'=>0,'target_member_multiple_from_uuid'=>0,'target_member_absent_from_uuid'=>0,
 'target_member_project_unique'=>0,'target_member_project_ambiguous'=>0,'target_member_project_absent'=>0];
 foreach($ids as $id){
  $tm=$byId[$id]??null;if(!$tm)continue;
  $uuid=$normalize($tm->employee_id);$users=array_unique($u[$uuid]??[]);
  if(count($users)!==1){$stats[count($users)>1?'legacy_clients_uuid_ambiguous':'legacy_clients_uuid_absent']++;continue;}
  $stats['legacy_clients_uuid_unique']++;$attached=$attachments[reset($users)]??[];
  if($attached)$stats['legacy_attached_all']++;
  $projects=[];$target=[];$ptarget=[];
  foreach($attached as $a){
   $tid=$maps[(string)$a->id]??null;if($tid)$target[(int)$tid]=true;
   if(($pr=$normalize($tm->project))!==''&&($pr===($pnames[(string)$a->project_id]??'')||$pr===($cnames[(string)$a->client_id]??''))){
     $projects[(string)$a->id]=true;if($tid)$ptarget[(int)$tid]=true;
   }
  }
  $stats['old_project_'.(count($projects)===1?'unique':(count($projects)>1?'ambiguous':'absent'))]++;
  $stats['target_member_'.(count($target)===1?'unique_from_uuid':(count($target)>1?'multiple_from_uuid':'absent_from_uuid'))]++;
  $stats['target_member_project_'.(count($ptarget)===1?'unique':(count($ptarget)>1?'ambiguous':'absent'))]++;
 }
 out('staff_292_cross_legacy_uuid',$stats);
}catch(Throwable $e){out('staff_292_cross_legacy_error',['type'=>get_class($e),'line'=>$e->getLine()]);}

try {
 $c=new LegacyReader('clients');$t=new LegacyReader('timesheets');$c->assertSafe();$t->assertSafe();
 $sourceMembers=$t->select("SELECT id,employee_id,project FROM public.members",[],false);
 $sourceUsers=$c->select("SELECT id,external_key FROM public.users",[],false);
 $sourceClientMembers=$c->select("SELECT id,memberable_id,memberable_type FROM public.members",[],false);
 $u=[];foreach($sourceUsers as $x)$u[mb_strtolower(trim((string)$x->external_key))][]=(string)$x->id;
 $clientMem=[];foreach($sourceClientMembers as $x)if(mb_strtolower((string)$x->memberable_type)==='employee')$clientMem[(string)$x->memberable_id][]=(string)$x->id;
 $map=DB::table('migration_mappings')->where('service','clients')->where('entity_type','members')->pluck('target_id','legacy_id')->all();
 $byId=[];foreach($sourceMembers as $m)$byId[(string)$m->id]=$m;
 $ids=DB::table('migration_conflicts')->where('migration_run_id',292)->where('service','timesheets')->where('entity_type','members')->where('code','MEMBER_IDENTITY_UNRESOLVED')->distinct()->pluck('legacy_id')->all();
 $stats=['staff'=>count($ids),'intersection_exact_one'=>0,'intersection_zero'=>0,'intersection_multiple'=>0,
  'intersection_unique_has_full_fact_coverage'=>0,'intersection_unique_lacks_fact_coverage'=>0,
  'selected_by_period_and_uuid'=>0,'selected_by_uuid_only'=>0,'selected_by_period_only'=>0,'still_ambiguous'=>0];
 foreach($ids as $id){
  $m=$byId[(string)$id]??null;if(!$m)continue;
  $uuid=mb_strtolower(trim((string)$m->employee_id));
  $target=[];foreach($u[$uuid]??[] as $uid)foreach($clientMem[$uid]??[] as $mid)if(isset($map[$mid]))$target[(int)$map[$mid]]=true;
  $inspect=\App\Migration\Core\TimesheetsMemberMatching::inspect((string)$id);
  $candidateIds=[];$periodIds=[];
  foreach($inspect['candidates'] as $cand){
   $tid=(int)$cand['target_id'];$candidateIds[$tid]=true;
   if($cand['period_matches']??false)$periodIds[$tid]=true;
  }
  $intersection=array_intersect_key($target,$candidateIds);
  $n=count($intersection);
  $stats[$n===1?'intersection_exact_one':($n===0?'intersection_zero':'intersection_multiple')]++;
  $both=array_intersect_key($intersection,$periodIds);
  if(count($both)===1)$stats['selected_by_period_and_uuid']++;
  elseif(count($intersection)===1)$stats['selected_by_uuid_only']++;
  elseif(count($periodIds)===1)$stats['selected_by_period_only']++;
  else $stats['still_ambiguous']++;
  if($n===1){
   $chosen=array_key_first($intersection);
   if(isset($periodIds[$chosen]))$stats['intersection_unique_has_full_fact_coverage']++;
   else $stats['intersection_unique_lacks_fact_coverage']++;
  }
 }
 out('staff_292_combined_evidence',$stats);
}catch(Throwable $e){out('staff_292_combined_error',['type'=>get_class($e),'line'=>$e->getLine()]);}

try {
 $c=new LegacyReader('clients');$t=new LegacyReader('timesheets');$c->assertSafe();$t->assertSafe();
 $tMembers=$t->select("SELECT id,employee_id,name,project,memberable_type FROM public.members",[],false);
 $tRates=$t->select('SELECT id,member_id,"from","to" FROM public.rates',[],false);
 $tDates=$t->select("SELECT DISTINCT rate_id,date FROM public.timesheets",[],false);
 $cMembers=$c->select("SELECT id,memberable_id,memberable_type,client_id,project_id FROM public.members",[],false);
 $cUsers=$c->select("SELECT id,external_key FROM public.users",[],false);
 $cSubs=$c->select("SELECT id,surname,name,patronymic FROM public.subcontracts",[],false);
 $cProjects=$c->select("SELECT id,title FROM public.projects",[],false);
 $cClients=$c->select("SELECT id,title FROM public.clients",[],false);
 $cRates=$c->select("SELECT member_id,start_date,end_date FROM public.rates",[],false);
 $norm=static fn($v)=>trim(preg_replace('/\s+/u',' ',mb_strtolower((string)$v)));
 $tok=static fn($v)=>array_values(array_filter(explode(' ',$norm($v))));
 $subs=[];foreach($cSubs as $s)$subs[(string)$s->id]=$tok(implode(' ',array_filter([(string)$s->surname,(string)$s->name,(string)$s->patronymic])));
 $users=[];foreach($cUsers as $u)$users[$norm($u->external_key)][]=(string)$u->id;
 $ps=[];foreach($cProjects as $p)$ps[(string)$p->id]=$norm($p->title);
 $cs=[];foreach($cClients as $cc)$cs[(string)$cc->id]=$norm($cc->title);
 $mById=[];$rById=[];$dates=[];
 foreach($tMembers as $m)$mById[(string)$m->id]=$m;
 foreach($tRates as $r)$rById[(string)$r->id]=$r;
 foreach($tDates as $d)$dates[(string)$d->rate_id][substr((string)$d->date,0,10)]=true;
 $cmByPerson=[];foreach($cMembers as $a)$cmByPerson[mb_strtolower((string)$a->memberable_type)][(string)$a->memberable_id][]=$a;
 $targetMap=DB::table('migration_mappings')->where('service','clients')->where('entity_type','members')->pluck('target_id','legacy_id')->all();
 $oldRates=[];foreach($cRates as $cr)$oldRates[(string)$cr->member_id][]=$cr;
 $targetTerms=DB::connection('target_clients')->table('member_terms')->get(['client_member_id','valid_from','valid_to']);
 $terms=[];foreach($targetTerms as $mt)$terms[(int)$mt->client_member_id][]=$mt;
 $gapIds=DB::table('migration_conflicts')->where('migration_run_id',292)->where('service','timesheets')->where('entity_type','rates')->where('code','TARGET_TERM_GAP')->distinct()->pluck('legacy_id');
 $stats=['gap_rate_ids'=>count($gapIds),'rate_missing_source'=>0,'rate_no_facts'=>0,'partner_rates'=>0,'employee_rates'=>0,
  'target_identity_unique'=>0,'target_identity_multiple'=>0,'target_identity_missing'=>0,
  'fact_date_rows_unique_identity'=>0,'target_date_uncovered'=>0,'target_date_multiple'=>0,
  'source_clients_covered_but_target_missing'=>0,'source_clients_and_target_both_missing'=>0,
  'no_source_clients_rate_for_target'=>0,'source_clients_overlap_dates'=>0];
 foreach($gapIds as $rid){
  $rate=$rById[(string)$rid]??null;if(!$rate){$stats['rate_missing_source']++;continue;}
  $member=$mById[(string)$rate->member_id]??null;if(!$member)continue;
  $dd=array_keys($dates[(string)$rid]??[]);if(!$dd){$stats['rate_no_facts']++;continue;}
  $type=mb_strtolower((string)$member->memberable_type);$memberCandidates=[];
  if(in_array($type,['subcontract','contractor','external','partner'],true)){
    $stats['partner_rates']++;$name=$tok($member->name);$project=$norm($member->project);
    foreach($subs as $sid=>$person){
      if(count($name)<2||count($person)<2||$name[0]!==$person[0]||mb_substr($name[1],0,1)!==mb_substr($person[1],0,1))continue;
      foreach($cmByPerson['subcontract'][$sid]??[] as $cm)
        if($project!==''&&($project===($ps[(string)$cm->project_id]??'')||$project===($cs[(string)$cm->client_id]??'')))$memberCandidates[(string)$cm->id]=$cm;
    }
  }elseif($type==='employee'){
    $stats['employee_rates']++;
    foreach($users[$norm($member->employee_id)]??[] as $uid)
      foreach($cmByPerson['employee'][$uid]??[] as $cm)$memberCandidates[(string)$cm->id]=$cm;
  }
  $targets=[];
  foreach($memberCandidates as $mid=>$cm)if(isset($targetMap[$mid]))$targets[(int)$targetMap[$mid]][]=$mid;
  if(!$targets){$stats['target_identity_missing']++;continue;}
  if(count($targets)>1){$stats['target_identity_multiple']++;continue;}
  $stats['target_identity_unique']++;$tid=array_key_first($targets);
  foreach($dd as $date){
   $stats['fact_date_rows_unique_identity']++;
   $current=0;foreach($terms[$tid]??[] as $term)
     if($date>=substr((string)$term->valid_from,0,10)&&($term->valid_to===null||$date<=substr((string)$term->valid_to,0,10)))$current++;
   $old=0;foreach($targets[$tid] as $oldMid)foreach($oldRates[$oldMid]??[] as $cr)
     if($date>=substr((string)$cr->start_date,0,10)&&($cr->end_date===null||$date<=substr((string)$cr->end_date,0,10)))$old++;
   if($current===0){
     $stats['target_date_uncovered']++;
     $stats[$old>0?'source_clients_covered_but_target_missing':'source_clients_and_target_both_missing']++;
   }elseif($current>1)$stats['target_date_multiple']++;
   if($old===0)$stats['no_source_clients_rate_for_target']++;
   elseif($old>1)$stats['source_clients_overlap_dates']++;
  }
 }
 out('rate_gaps_292_old_vs_new_terms',$stats);
}catch(Throwable $e){out('rate_gaps_292_probe_error',['type'=>get_class($e),'line'=>$e->getLine()]);}
