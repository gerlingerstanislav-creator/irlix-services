<?php
/**
 * Fixed read-only diagnostic, run in the CURRENT deployed Migration container.
 * No application boot, artisan, DDL, updates, metadata events or export of rows.
 * Secrets stay in container memory. Output is schema/counts only.
 */
require '/app/vendor/autoload.php';
use Illuminate\Encryption\Encrypter;

function invLine(array $item):void {echo json_encode($item,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";}
function invPassword(string $payload):string {
    if(str_starts_with($payload,'migration:v1:')) {
        // Existing cipher refuses key initialization with SKIP_BOOTSTRAP=true.
        return (new App\Migration\Core\MigrationCredentialCipher())->decryptString($payload);
    }
    $key=getenv('MIGRATION_APP_KEY')?:getenv('APP_KEY');
    if(str_starts_with($key,'base64:'))$key=base64_decode(substr($key,7),true);
    return (new Encrypter($key,'AES-256-CBC'))->decryptString($payload);
}
function invPg(array $c,string $schema,array $tables,string $label):void {
    foreach(['host','database','username'] as $field)if(empty($c[$field]))throw new RuntimeException('Connection unavailable');
    $pdo=new PDO('pgsql:host='.$c['host'].';port='.(int)($c['port']??5432).';dbname='.$c['database'].';sslmode='.($c['sslmode']??'disable'),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    try {
        $pdo->exec("SET LOCAL statement_timeout='20s'");$pdo->exec("SET LOCAL lock_timeout='2s'");
        if($pdo->query('SHOW transaction_read_only')->fetchColumn()!=='on')throw new RuntimeException('Read-only not enforced');
        foreach($tables as $table) {
            // Both identifiers are fixed whitelisted literals supplied below.
            if(!preg_match('/^[a-z_]+$/D',$schema)||!preg_match('/^[a-z_]+$/D',$table))throw new LogicException('Invalid identifier');
            $q=$pdo->prepare('SELECT column_name,data_type,is_nullable FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position');$q->execute([$schema,$table]);$columns=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$columns){invLine(['source'=>$label,'table'=>$table,'exists'=>false]);continue;}
            $quoted='"'.$schema.'"."'.$table.'"';
            invLine(['source'=>$label,'table'=>$table,'columns'=>$columns,'rows'=>(int)$pdo->query('SELECT count(*) FROM '.$quoted)->fetchColumn()]);
            $names=array_column($columns,'column_name');
            if($table==='timesheets' && in_array('hours',$names,true))invLine(['source'=>$label,'table'=>$table,'sum_hours'=>$pdo->query('SELECT SUM(hours)::text FROM '.$quoted)->fetchColumn()]);
            if($table==='members' && in_array('memberable_type',$names,true)) {
                // Never print arbitrary source strings: only known type labels.
                $types=$pdo->query("SELECT CASE WHEN lower(memberable_type) IN ('employee','subcontract','contractor','external','partner') THEN lower(memberable_type) ELSE 'other' END AS identity_type,count(*) AS rows FROM ".$quoted.' GROUP BY 1')->fetchAll(PDO::FETCH_ASSOC);
                invLine(['source'=>$label,'table'=>$table,'identity_type_counts'=>$types]);
            }
            $fk=$pdo->prepare("SELECT kcu.column_name,ccu.table_name AS referenced_table,ccu.column_name AS referenced_column FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name=tc.constraint_name AND kcu.constraint_schema=tc.constraint_schema JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name=tc.constraint_name AND ccu.constraint_schema=tc.constraint_schema WHERE tc.constraint_type='FOREIGN KEY' AND tc.table_schema=? AND tc.table_name=?");$fk->execute([$schema,$table]);
            invLine(['source'=>$label,'table'=>$table,'foreign_keys'=>$fk->fetchAll(PDO::FETCH_ASSOC)]);
        }
    } finally {$pdo->rollBack();}
}
$failed=false;
try {
    $metadataPath=getenv('MIGRATION_METADATA_DATABASE')?:'/data/migration.sqlite';
    if(!is_file($metadataPath))throw new RuntimeException('Metadata database unavailable');
    $metadata=new PDO('sqlite:'.$metadataPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
    $metadata->exec('PRAGMA query_only=ON');
    $metadata->beginTransaction();
    foreach(['clients','timesheets'] as $service) {
        try {
            $q=$metadata->prepare('SELECT * FROM migration_connections WHERE service=?');$q->execute([$service]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row || !(bool)$row['readonly_acknowledged'])throw new RuntimeException('No acknowledged source profile');
            $row['password']=invPassword($row['password_encrypted']);
            $tables=$service==='clients'?['clients','projects','members','rates','attempts','feedback','reviews','reporting_periods','reporting_period_rate','subcontracts']:['users','members','rates','timesheets','month_confirmations'];
            invPg($row,'public',$tables,'legacy_'.$service);
        } catch(Throwable $e) {$failed=true;invLine(['source'=>'legacy_'.$service,'status'=>'BLOCKED','error_class'=>get_class($e),'error_code'=>(string)$e->getCode()]);}
    }
    $q=$metadata->query("SELECT service,entity_type,count(*) AS rows FROM migration_mappings WHERE service IN ('employees','clients','timesheets') GROUP BY service,entity_type ORDER BY service,entity_type");
    invLine(['source'=>'saved_identity_mappings','counts'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    $metadata->rollBack();
} catch(Throwable $e) {$failed=true;invLine(['source'=>'metadata','status'=>'BLOCKED','error_class'=>get_class($e),'error_code'=>(string)$e->getCode()]);}
foreach(['clients'=>['project_members','member_terms','client_members','reporting_periods'],'timesheets'=>['timesheet_entries','employee_confirmations','final_approvals','final_approval_exceptions']] as $service=>$tables) {
    try {
        $prefix='TARGET_'.strtoupper($service).'_DB';
        invPg(['host'=>getenv($prefix.'_HOST')?:getenv('TARGET_SHARED_DB_HOST'),'port'=>getenv($prefix.'_PORT')?:5432,'database'=>getenv($prefix.'_DATABASE')?:getenv('TARGET_SHARED_DB_DATABASE'),'username'=>getenv($prefix.'_USERNAME'),'password'=>getenv($prefix.'_PASSWORD'),'sslmode'=>getenv($prefix.'_SSLMODE')?:'disable'],$service,$tables,'target_'.$service);
    } catch(Throwable $e) {$failed=true;invLine(['source'=>'target_'.$service,'status'=>'BLOCKED','error_class'=>get_class($e),'error_code'=>(string)$e->getCode()]);}
}
exit($failed?2:0);
