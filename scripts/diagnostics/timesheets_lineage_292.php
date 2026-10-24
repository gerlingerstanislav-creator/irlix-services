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
