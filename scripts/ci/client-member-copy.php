<?php
// Runner-only. No production addresses/credentials are present in these containers.
ini_set('display_errors','0');ini_set('log_errors','0');
require '/app/vendor/autoload.php';
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
function copyLines():Generator {
    $f=fopen('/private/source.jsonl','rb');
    try {while(($line=fgets($f))!==false)yield json_decode($line,true,512,JSON_THROW_ON_ERROR);}finally{fclose($f);}
}
function copyReport(array $data):void {echo json_encode($data,JSON_THROW_ON_ERROR)."\n";}
function copyNumbers(array $data):array {
    $result=[];
    foreach($data as $key=>$value) {
        if(is_int($value)||is_bool($value))$result[$key]=$value;
        elseif(is_array($value)){$child=copyNumbers($value);if($child)$result[$key]=$child;}
    }
    return $result;
}
function copyBoot():void {
    $app=require '/app/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}
function copyPg(string $database,string $user='postgres'):PDO {
    return new PDO('pgsql:host=copy-postgres;dbname='.$database,$user,'synthetic_copy_password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function copyInsert(PDO $db,string $table,array $row):void {
    foreach(array_keys($row) as $field)if(!preg_match('/^[a-z_][a-z0-9_]*$/D',$field))throw new LogicException('Invalid field');
    $columns=implode(',',array_map(fn($f)=>'"'.$f.'"',array_keys($row)));
    $q=$db->prepare('INSERT INTO '.$table.' ('.$columns.') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
    foreach(array_values($row) as $i=>$value)$q->bindValue($i+1,$value,is_bool($value)?PDO::PARAM_BOOL:($value===null?PDO::PARAM_NULL:PDO::PARAM_STR));
    $q->execute();
}
try {
    $mode=$argv[1]??'';
    copyBoot();
    if($mode==='old') {
        foreach(glob('/app/database/migrations/*.php') as $path)if(basename($path)<'2026_10_23_')Artisan::call('migrate',['--path'=>$path,'--realpath'=>true,'--force'=>true]);
        copyReport(['phase'=>'old-schema','status'=>'PASS']);exit;
    }
    if($mode==='upgrade') {
        Artisan::call('migrate',['--force'=>true]);
        copyReport(['phase'=>'populated-upgrade','service'=>getenv('DB_SEARCH_PATH'),'status'=>'PASS']);exit;
    }
    if($mode==='restore') {
        Artisan::call('migrate',['--force'=>true]);
        $pg=[];foreach(['target_clients','target_timesheets','legacy_clients','legacy_timesheets','target_employees'] as $name)$pg[$name]=copyPg($name);
        $metadata=DB::connection()->getPdo();
        $identity=new PDO('sqlite:/private/identities.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $identity->exec('CREATE TABLE employee_logins(source_key TEXT,old_login TEXT,new_login TEXT,actor TEXT,updated_at TEXT,PRIMARY KEY(source_key,old_login))');
        $scopes=[];$identities=[];$complete=false;$counts=[];$expected=[];$profiles=[];
        foreach(['clients','timesheets','vacations'] as $service) {
            $dbName='legacy_'.$service;
            $profiles[$service]=['service'=>$service,'host'=>'copy-postgres','port'=>5432,'database'=>$dbName,'username'=>'copy_reader','password_encrypted'=>app(App\Migration\Core\MigrationCredentialCipher::class)->encryptString('synthetic_copy_password'),'sslmode'=>'disable','readonly_acknowledged'=>true,'created_at'=>now(),'updated_at'=>now()];
        }
        foreach($pg as $label=>$db) {
            if(str_starts_with($label,'target_')&&$label!=='target_employees') {
                $schema=substr($label,7);$db->exec('SET search_path='.$schema);
                $tables=$db->query("SELECT tablename FROM pg_tables WHERE schemaname='".$schema."'")->fetchAll(PDO::FETCH_COLUMN);
                // Empty synthetic bootstrap rows before replacing with actual snapshot.
                $db->exec('TRUNCATE '.implode(',',array_map(fn($t)=>'"'.$schema.'"."'.$t.'"',$tables)).' RESTART IDENTITY CASCADE');
                $db->exec("SET session_replication_role='replica'");
            }
            $db->beginTransaction();
        }
        foreach(copyLines() as $item) {
            $kind=$item['kind'];
            if($kind==='complete'){$complete=true;continue;}
            if($kind==='identity_scope'){$scopes[$item['source_key']]=$item['service'];continue;}
            if($kind==='identity'){$identities[]=$item['row'];continue;}
            if($kind==='legacy_key'){$profiles['clients']['legacy_app_key_encrypted']=app(App\Migration\Core\MigrationCredentialCipher::class)->encryptString($item['key']);$profiles['clients']['legacy_cipher']=$item['cipher'];continue;}
            if($kind==='metadata'){copyInsert($metadata,'"'.$item['table'].'"',$item['row']);continue;}
            if($kind==='missing') {
                if(str_starts_with($item['database'],'legacy_'))throw new RuntimeException('Required source table absent');
                continue;
            }
            $label=$item['database'];$table=$item['table'];
            if(!isset($pg[$label])||!preg_match('/^[a-z_]+$/D',$table))throw new LogicException('Invalid clone table');
            $db=$pg[$label];$schema=str_starts_with($label,'target_')?substr($label,7):'public';$qualified='"'.$schema.'"."'.$table.'"';$key=$label.'.'.$table;
            if($kind==='table') {
                if(str_starts_with($label,'legacy_')||$label==='target_employees') {
                    $db->exec('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
                    $definitions=[];
                    foreach($item['columns'] as $column) {
                        if(!preg_match('/^[a-z_][a-z0-9_]*$/D',$column['name'])||!preg_match('/^[a-z0-9_ (),\[\]]+$/Di',$column['type']))throw new LogicException('Unsupported clone column');
                        $definitions[]='"'.$column['name'].'" '.$column['type'];
                    }
                    $db->exec('CREATE TABLE '.$qualified.' ('.implode(',',$definitions).')');
                } else {
                    $q=$db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position');$q->execute([$schema,$table]);
                    $actual=$q->fetchAll(PDO::FETCH_COLUMN);$exported=array_column($item['columns'],'name');
                    sort($actual);sort($exported);
                    if($actual!==$exported) {
                        copyReport(['phase'=>'target-schema-check','status'=>'BLOCKED','table'=>$key,'canonical_columns'=>$actual,'snapshot_columns'=>$exported]);
                        throw new RuntimeException('Target schema differs from canonical old migrations');
                    }
                }
                $counts[$key]=0;
            } elseif($kind==='row'){copyInsert($db,$qualified,$item['row']);$counts[$key]++;}
            elseif($kind==='count')$expected[$key]=$item['rows'];
        }
        if(!$complete||$counts!==$expected||!isset($counts['legacy_clients.clients'],$counts['legacy_timesheets.timesheets'],$counts['target_clients.migrations'],$counts['target_timesheets.migrations']))throw new RuntimeException('Incomplete private snapshot');
        foreach($pg as $label=>$db) {
            $db->commit();
            if(str_starts_with($label,'target_')&&$label!=='target_employees') {
                $db->exec("SET session_replication_role='origin'");
                $schema=substr($label,7);
                foreach($counts as $key=>$count)if(str_starts_with($key,$label.'.')) {
                    $table=substr($key,strlen($label)+1);
                    $q=$db->prepare('SELECT pg_get_serial_sequence(?,?)');$q->execute([$schema.'.'.$table,'id']);$sequence=$q->fetchColumn();
                    if($sequence){$maximum=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM "'.$schema.'"."'.$table.'"')->fetchColumn();$q=$db->prepare('SELECT setval(CAST(? AS regclass),?,?)');$q->execute([$sequence,max(1,$maximum),$maximum>0?'true':'false']);}
                }
            }
        }
        foreach($profiles as $row)DB::table('migration_connections')->insert($row);
        foreach($identities as $row) {
            if(!isset($scopes[$row['source_key']]))continue;
            $service=$scopes[$row['source_key']];
            $row['source_key']=hash('sha256',json_encode([$service,'copy-postgres',5432,'legacy_'.$service,'public']));
            copyInsert($identity,'employee_logins',$row);
        }
        foreach(['legacy_clients','legacy_timesheets'] as $name) {
            $db=$pg[$name];$db->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');$db->exec('GRANT USAGE ON SCHEMA public TO copy_reader');$db->exec('GRANT SELECT ON ALL TABLES IN SCHEMA public TO copy_reader');
        }
        // Ensure runtime readers pass the unmodified LegacyReader privilege checks.
        copyReport(['phase'=>'private-restore','status'=>'PASS','table_counts'=>$counts]);exit;
    }
    if($mode==='dry-run') {
        $store=app(App\Migration\Core\MigrationStore::class);$failed=false;
        foreach(['clients'=>App\Migration\Services\ClientsMigration::class,'timesheets'=>App\Migration\Services\TimesheetsMigration::class] as $service=>$class) {
            foreach(['dry-run','validate'] as $phase) {
                $run=$store->beginRun($service,$phase);$module=new $class($store);
                try {
                    $result=$phase==='validate'?$module->validate($run):$module->migrate($run,true);
                    $ok=$phase==='validate'?($result['ok']??false):($result['conflicts']??-1)===0;
                    $store->finishRun($run,$ok?'completed':'conflicts',$result);
                    $codes=DB::table('migration_conflicts')->where('migration_run_id',$run)->selectRaw('code,severity,count(*) AS rows')->groupBy('code','severity')->get()->map(fn($r)=>['code'=>$r->code,'severity'=>$r->severity,'rows'=>(int)$r->rows])->all();
                    copyReport(['phase'=>$phase,'service'=>$service,'status'=>$ok?'PASS':($phase==='validate'?'PRE_IMPORT_DIAGNOSTIC':'BLOCKED'),'conflict_codes'=>$codes,'counts'=>copyNumbers(array_intersect_key($result,array_flip(['ok','tables','provenance','conflicts','imported','skipped','warnings','processed','ready'])))]);
                    if($phase==='dry-run')$failed=$failed||!$ok;
                } catch(Throwable $e){if($phase==='dry-run')$failed=true;copyReport(['phase'=>$phase,'service'=>$service,'status'=>$phase==='validate'?'PRE_IMPORT_DIAGNOSTIC':'BLOCKED','error_class'=>get_class($e),'code'=>(string)$e->getCode(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);}
            }
        }
        exit($failed?2:0);
    }
    if($mode==='convert') {
        $result=(new App\Support\ProjectHoursConverter)->run(false);$codes=[];
        foreach($result['conflicts']??[] as $conflict){$code=$conflict['code'];$codes[$code]=($codes[$code]??0)+1;}
        copyReport(['phase'=>'project-hours-converter','status'=>$result['conflict_count']===0?'PASS':'BLOCKED','conflict_codes'=>$codes,'counts'=>array_filter($result,fn($v)=>is_int($v)||is_bool($v))]);
        exit($result['conflict_count']===0?0:2);
    }
    throw new LogicException('Unknown clone mode');
} catch(Throwable $e) {
    // Messages/SQL may include personal values. Only deterministic location escapes.
    $report=['phase'=>$mode??'bootstrap','status'=>'BLOCKED','error_class'=>get_class($e),'code'=>(string)$e->getCode(),'file'=>basename($e->getFile()),'line'=>$e->getLine()];
    // Whitelisted application-owned blocker texts contain no data or SQL.
    foreach(['Invalid legacy commercial interval; reconcile before deployment.','Incompatible overlapping ClientMember terms; explicit reconciliation required.','Protected reporting history overlaps coalesced terms; verified financial snapshot reconciliation required.','Multiple approved-hour origins map to one commercial interval; explicit reconciliation required.','Incomplete private snapshot'] as $safeMessage)
        if($e->getMessage()===$safeMessage)$report['reason']=$safeMessage;
    copyReport($report);exit(2);
}
