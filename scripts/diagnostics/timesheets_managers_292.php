<?php
/** Read-only cross-system manager UUID evidence, no names or IDs in output. */
declare(strict_types=1);
use App\Migration\Core\LegacyReader;
use Illuminate\Support\Facades\DB;
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function emit(string $name,array $data):void{echo json_encode(['check'=>$name]+$data,JSON_THROW_ON_ERROR)."\n";}
try{
 $ts=new LegacyReader('timesheets');$cl=new LegacyReader('clients');$ts->assertSafe();$cl->assertSafe();
 $ids=DB::table('migration_conflicts')->where('migration_run_id',292)->where('service','timesheets')->where('entity_type','members')->where('code','MEMBER_IDENTITY_UNRESOLVED')->distinct()->pluck('legacy_id')->all();
 if(!$ids){emit('manager_292',['count'=>0]);exit(0);}
 $bindings=array_map('intval',$ids);$in=implode(',',array_fill(0,count($bindings),'?'));
 $members=$ts->select('SELECT id,employee_id,account_manager_id,sales_manager_id FROM public.members WHERE id IN ('.$in.')',$bindings,false);
 $tu=$ts->select('SELECT id,employee_id FROM public.users',[],false);
 $cu=$cl->select('SELECT id,external_key FROM public.users',[],false);
 $cm=$cl->select('SELECT id,memberable_id,memberable_type,client_id FROM public.members',[],false);
 $clients=$cl->select('SELECT id,account_manager_id,sales_manager_id FROM public.clients',[],false);
 $norm=static fn($v)=>mb_strtolower(trim((string)$v));
 $tUuid=[];foreach($tu as $u)$tUuid[(string)$u->id]=$norm($u->employee_id);
 $cUuid=[];$byUuid=[];foreach($cu as $u){$cUuid[(string)$u->id]=$norm($u->external_key);if($norm($u->external_key)!=='')$byUuid[$norm($u->external_key)][]=(string)$u->id;}
 $clientById=[];foreach($clients as $c)$clientById[(string)$c->id]=$c;
 $attached=[];foreach($cm as $m)if(mb_strtolower((string)$m->memberable_type)==='employee')$attached[(string)$m->memberable_id][]=$m;
 $mapping=DB::table('migration_mappings')->where('service','clients')->where('entity_type','members')->pluck('target_id','legacy_id')->all();
 $s=['members'=>count($members),'account_signal_available'=>0,'sales_signal_available'=>0,
 'account_match_unique'=>0,'account_match_none'=>0,'account_match_multiple'=>0,
 'sales_match_unique'=>0,'sales_match_none'=>0,'sales_match_multiple'=>0,
 'combined_match_unique'=>0,'combined_match_multiple'=>0,'combined_match_none'=>0,'no_manager_signals'=>0];
 foreach($members as $m){
  $macc=$tUuid[(string)$m->account_manager_id]??'';$msales=$tUuid[(string)$m->sales_manager_id]??'';
  $useAcc=$macc!=='';$useSales=$msales!=='';
  if($useAcc)$s['account_signal_available']++;
  if($useSales)$s['sales_signal_available']++;
  if(!$useAcc&&!$useSales){$s['no_manager_signals']++;continue;}
  $acc=[];$sales=[];$combined=[];
  foreach($byUuid[$norm($m->employee_id)]??[] as $uid)foreach($attached[$uid]??[] as $a){
   $target=$mapping[(string)$a->id]??null;if(!$target)continue;
   $clRow=$clientById[(string)$a->client_id]??null;if(!$clRow)continue;
   $am=$cUuid[(string)$clRow->account_manager_id]??'';$sm=$cUuid[(string)$clRow->sales_manager_id]??'';
   if($useAcc&&$macc===$am)$acc[(int)$target]=true;
   if($useSales&&$msales===$sm)$sales[(int)$target]=true;
   if((!$useAcc||$macc===$am)&&(!$useSales||$msales===$sm))$combined[(int)$target]=true;
  }
  foreach(['account'=>$acc,'sales'=>$sales,'combined'=>$combined] as $key=>$matches){
   if($key!=='combined' && !(($key==='account')?$useAcc:$useSales))continue;
   $n=count($matches);$s[$key.'_match_'.($n===1?'unique':($n>1?'multiple':'none'))]++;
  }
 }
 emit('manager_292_evidence',$s);
}catch(Throwable $e){emit('manager_292_error',['type'=>get_class($e),'line'=>$e->getLine()]);}
