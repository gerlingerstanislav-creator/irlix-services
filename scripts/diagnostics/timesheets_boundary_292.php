<?php
/** Stand-only read-only fact-date boundary and source-vs-target term comparison. */
declare(strict_types=1);
use App\Migration\Core\LegacyReader;
use Illuminate\Support\Facades\DB;
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function output(string $name,array $fields):void{echo json_encode(['check'=>$name]+$fields,JSON_THROW_ON_ERROR)."\n";}
function norm(?string $s):string{return trim(preg_replace('/\s+/u',' ',mb_strtolower((string)$s)));}
function coverage(string $date,array $terms,string $start,string $end):int{
  $n=0;foreach($terms as $t)if($date>=substr((string)$t->$start,0,10)&&($t->$end===null||$date<=substr((string)$t->$end,0,10)))$n++;
  return $n;
}
function bucket(string $date,array $terms,string $start,string $end):string{
 if(!$terms)return 'none';
 $s=[];$e=[];
 foreach($terms as $t){$s[]=substr((string)$t->$start,0,10);$e[]=$t->$end===null?'9999-12-31':substr((string)$t->$end,0,10);}
 sort($s);sort($e);
 if($date<$s[0])return 'before';
 if($date>$e[count($e)-1])return 'after';
 return 'inside_gap';
}
try{
 $ts=new LegacyReader('timesheets');$cl=new LegacyReader('clients');$ts->assertSafe();$cl->assertSafe();
 $tsMembers=$ts->select("SELECT id,name,project,employee_id,memberable_type FROM public.members",[],false);
 $tsRates=$ts->select('SELECT id,member_id,"from","to" FROM public.rates',[],false);
 $clSubs=$cl->select("SELECT id,surname,name,patronymic FROM public.subcontracts",[],false);
 $clUsers=$cl->select("SELECT id,external_key FROM public.users",[],false);
 $clMembers=$cl->select("SELECT id,memberable_type,memberable_id,client_id,project_id FROM public.members",[],false);
 $clProjects=$cl->select("SELECT id,title FROM public.projects",[],false);
 $clClients=$cl->select("SELECT id,title FROM public.clients",[],false);
 $clRates=$cl->select("SELECT member_id,start_date,end_date FROM public.rates",[],false);
 $map=DB::table('migration_mappings')->where('service','clients')->where('entity_type','members')->pluck('target_id','legacy_id')->all();
 $termRows=DB::connection('target_clients')->table('member_terms')->get(['client_member_id','valid_from','valid_to']);
 $terms=[];foreach($termRows as $r)$terms[(int)$r->client_member_id][]=$r;
 $oldRates=[];foreach($clRates as $r)$oldRates[(string)$r->member_id][]=$r;
 $aliases=[];foreach($map as $source=>$target)$aliases[(int)$target][]=(string)$source;
 $subparts=[];foreach($clSubs as $s)$subparts[(string)$s->id]=explode(' ',norm(implode(' ',array_filter([(string)$s->surname,(string)$s->name,(string)$s->patronymic]))));
 $pnames=[];foreach($clProjects as $p)$pnames[(string)$p->id]=norm($p->title);
 $cnames=[];foreach($clClients as $c)$cnames[(string)$c->id]=norm($c->title);
 $uuidUsers=[];foreach($clUsers as $u)$uuidUsers[norm($u->external_key)][]=(string)$u->id;
 $people=[];foreach($clMembers as $m)$people[mb_strtolower((string)$m->memberable_type)][(string)$m->memberable_id][]=$m;
 $targetForTs=[];$partnerIds=[];
 foreach($tsMembers as $m){
  $type=mb_strtolower((string)$m->memberable_type);$sourceName=explode(' ',norm($m->name));$project=norm($m->project);$choice=[];
  if(in_array($type,['subcontract','contractor','external','partner'],true)){
   $partnerIds[]=(int)$m->id;
   foreach($subparts as $sid=>$sp){
    if(count($sourceName)<2||count($sp)<2||$sourceName[0]!==$sp[0]||mb_substr($sourceName[1],0,1)!==mb_substr($sp[1],0,1))continue;
    foreach($people['subcontract'][$sid]??[] as $a){
     if($project!==''&&($project===($cnames[(string)$a->client_id]??'')||$project===($pnames[(string)$a->project_id]??''))){
      if(isset($map[(string)$a->id]))$choice[(int)$map[(string)$a->id]]=true;
     }
    }
   }
  }else{
   foreach($uuidUsers[norm($m->employee_id)]??[] as $uid)foreach($people['employee'][$uid]??[] as $a)
    if($project!==''&&($project===($cnames[(string)$a->client_id]??'')||$project===($pnames[(string)$a->project_id]??'')))
     if(isset($map[(string)$a->id]))$choice[(int)$map[(string)$a->id]]=true;
  }
  if(count($choice)===1)$targetForTs[(string)$m->id]=array_key_first($choice);
 }
 $rateById=[];foreach($tsRates as $r)$rateById[(string)$r->id]=$r;
 $gapIds=DB::table('migration_conflicts')->where('migration_run_id',292)->where('entity_type','rates')->where('service','timesheets')->where('code','TARGET_TERM_GAP')->distinct()->pluck('legacy_id')->map(static fn($s)=>(int)$s)->all();
 $wantedRates=[];foreach($tsRates as $r)if(in_array((int)$r->member_id,$partnerIds,true)||in_array((int)$r->id,$gapIds,true))$wantedRates[]=(int)$r->id;
 $params=implode(',',array_fill(0,count($wantedRates),'?'));
 $facts=$wantedRates?$ts->select("SELECT DISTINCT rate_id,date FROM public.timesheets WHERE rate_id IN (".$params.")",$wantedRates,false):[];
 $byRate=[];foreach($facts as $f)$byRate[(string)$f->rate_id][substr((string)$f->date,0,10)]=true;
 $stats=['partners'=>['source_member_count'=>count($partnerIds),'fact_dates'=>0,'gaps'=>0,'old_client_also_gap'=>0,'old_client_covered_target_missing'=>0,'gap_before'=>0,'gap_after'=>0,'gap_inside_gap'=>0,'gap_none'=>0,'source_rate_outside'=>0],
         'staff_gap_rates'=>['source_rate_count'=>count($gapIds),'fact_dates'=>0,'gaps'=>0,'old_client_also_gap'=>0,'old_client_covered_target_missing'=>0,'gap_before'=>0,'gap_after'=>0,'gap_inside_gap'=>0,'gap_none'=>0,'source_rate_outside'=>0]];
 $partnerRateIds=[];
 foreach($tsRates as $r)if(in_array((int)$r->member_id,$partnerIds,true))$partnerRateIds[(string)$r->id]=true;
 $partnerDates=[];
 foreach($byRate as $rid=>$dates){
  $r=$rateById[$rid]??null;if(!$r)continue;
  $isPartner=isset($partnerRateIds[$rid]);
  if(!$isPartner&&!in_array((int)$rid,$gapIds,true))continue;
  $key=$isPartner?'partners':'staff_gap_rates';$target=$targetForTs[(string)$r->member_id]??null;
  if(!$target)continue;
  foreach(array_keys($dates) as $date){
   if($isPartner){$uniq=(string)$r->member_id.':'.$date;if(isset($partnerDates[$uniq]))continue;$partnerDates[$uniq]=true;}
   $stats[$key]['fact_dates']++;
   $outside=$date<substr((string)$r->from,0,10)||($r->to!==null&&$date>substr((string)$r->to,0,10));
   if($outside)$stats[$key]['source_rate_outside']++;
   $new=coverage($date,$terms[$target]??[],'valid_from','valid_to');
   if($new===0){
    $stats[$key]['gaps']++;
    $b=bucket($date,$terms[$target]??[],'valid_from','valid_to');
    $stats[$key]['gap_'.$b]++;
    $oldAll=[];foreach($aliases[$target]??[] as $oldmid)foreach($oldRates[$oldmid]??[] as $term)$oldAll[]=$term;
    if(coverage($date,$oldAll,'start_date','end_date')===0)$stats[$key]['old_client_also_gap']++;
    else $stats[$key]['old_client_covered_target_missing']++;
   }
  }
 }
 output('boundary_gap_292',$stats);
}catch(Throwable $e){output('boundary_gap_292_error',['type'=>get_class($e),'line'=>$e->getLine()]);}
