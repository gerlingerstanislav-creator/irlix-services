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
function invPg(array $c,string $schema,array $tables,string $label):PDO {
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
    return $pdo;
}
function invUnmapped(PDO $source,PDO $metadata,string $service,string $table,string $entity):array {
    $q=$metadata->prepare('SELECT legacy_id FROM migration_mappings WHERE service=? AND entity_type=?');
    $q->execute([$service,$entity]);
    $mapped=array_values(array_unique(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN))));
    $sourceCount=(int)$source->query('SELECT count(*) FROM "'.$table.'"')->fetchColumn();
    if(!$mapped) return ['source_rows'=>$sourceCount,'mapped_rows'=>0,'unmapped_rows'=>$sourceCount];
    $marks=implode(',',array_fill(0,count($mapped),'?'));
    $q=$source->prepare('SELECT count(*) FROM "'.$table.'" WHERE CAST(id AS text) IN ('.$marks.')');
    $q->execute($mapped);$mappedCount=(int)$q->fetchColumn();
    return ['source_rows'=>$sourceCount,'mapped_rows'=>$mappedCount,'unmapped_rows'=>$sourceCount-$mappedCount];
}
$failed=false;$sourcePdos=[];
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
            $sourcePdos[$service]=invPg($row,'public',$tables,'legacy_'.$service);
        } catch(Throwable $e) {$failed=true;invLine(['source'=>'legacy_'.$service,'status'=>'BLOCKED','error_class'=>get_class($e),'error_code'=>(string)$e->getCode()]);}
    }
    $q=$metadata->query("SELECT service,entity_type,count(*) AS rows FROM migration_mappings WHERE service IN ('employees','clients','timesheets') GROUP BY service,entity_type ORDER BY service,entity_type");
    invLine(['source'=>'saved_identity_mappings','counts'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    foreach([['clients','members','members'],['clients','rates','rates'],['timesheets','members','members'],['timesheets','rates','rates']] as [$service,$table,$entity]) {
        if(isset($sourcePdos[$service])) invLine(['source'=>'mapping_coverage','service'=>$service,'entity_type'=>$entity,...invUnmapped($sourcePdos[$service],$metadata,$service,$table,$entity)]);
    }
    if(isset($sourcePdos['clients'],$sourcePdos['timesheets'])) {
        $subcontracts=$sourcePdos['clients']->query('SELECT id,employee_id FROM subcontracts')->fetchAll(PDO::FETCH_ASSOC);
        $partners=$sourcePdos['timesheets']->query("SELECT id,employee_id FROM members WHERE lower(memberable_type) IN ('subcontract','contractor','external','partner')")->fetchAll(PDO::FETCH_ASSOC);
        $byUuid=[];
        foreach($subcontracts as $row) {
            $uuid=strtolower(trim((string)($row['employee_id']??'')));
            if($uuid!=='')$byUuid[$uuid][]=(string)$row['id'];
        }
        $q=$metadata->prepare("SELECT legacy_id,target_id FROM migration_mappings WHERE service='clients' AND entity_type='subcontracts'");
        $q->execute();$mappedSubcontracts=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$mappedSubcontracts[(string)$row['legacy_id']]=(string)$row['target_id'];
        $matched=0;$unique=0;$uniqueMapped=0;$missingUuid=0;$ambiguous=0;
        foreach($partners as $row) {
            $uuid=strtolower(trim((string)($row['employee_id']??'')));
            if($uuid===''){$missingUuid++;continue;}
            $ids=$byUuid[$uuid]??[];
            if(count($ids)===0)continue;
            $matched++;
            if(count($ids)!==1){$ambiguous++;continue;}
            $unique++;
            if(isset($mappedSubcontracts[$ids[0]]))$uniqueMapped++;
        }
        invLine(['source'=>'partner_identity_evidence','partner_members'=>count($partners),
            'missing_uuid'=>$missingUuid,'unique_uuid_matches'=>$unique,
            'unique_matches_with_saved_clients_mapping'=>$uniqueMapped,'ambiguous_uuid_matches'=>$ambiguous]);
    }
    if(isset($sourcePdos['timesheets'])) {
        $src=$sourcePdos['timesheets'];
        $status=$src->query("SELECT is_submitted,count(*) AS facts,COALESCE(SUM(hours),0)::text AS hours FROM timesheets GROUP BY is_submitted ORDER BY is_submitted")->fetchAll(PDO::FETCH_ASSOC);
        $nullRate=$src->query("SELECT count(*) AS facts,COALESCE(SUM(hours),0)::text AS hours FROM timesheets WHERE rate_id IS NULL")->fetch(PDO::FETCH_ASSOC);
        invLine(['source'=>'legacy_timesheet_fact_state','submission_flags'=>$status,'missing_rate_reference'=>['facts'=>(int)$nullRate['facts'],'hours'=>(string)$nullRate['hours']]]);
        $q=$metadata->prepare("SELECT legacy_id FROM migration_mappings WHERE service='timesheets' AND entity_type='rates'");
        $q->execute();$mappedRates=array_values(array_unique(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN))));
        if($mappedRates) {
            $marks=implode(',',array_fill(0,count($mappedRates),'?'));
            $q=$src->prepare('SELECT count(*) AS facts, COALESCE(SUM(hours),0)::text AS hours FROM timesheets WHERE rate_id IS NOT NULL AND CAST(rate_id AS text) NOT IN ('.$marks.')');
            $q->execute($mappedRates);$gap=$q->fetch(PDO::FETCH_ASSOC);
            invLine(['source'=>'mapping_coverage','service'=>'timesheets','entity_type'=>'facts_with_unmapped_rate','facts'=>(int)$gap['facts'],'hours'=>(string)$gap['hours']]);
        }
    }
    $metadata->rollBack();
} catch(Throwable $e) {$failed=true;invLine(['source'=>'metadata','status'=>'BLOCKED','error_class'=>get_class($e),'error_code'=>(string)$e->getCode()]);}
foreach(['clients'=>['project_members','member_terms','client_members','partners','partner_specialists','project_assignments','client_member_feedbacks','member_term_lineage','connection_attempts','reporting_periods'],'timesheets'=>['timesheet_entries','employee_confirmations','partner_daily_confirmations','final_approvals','final_approval_exceptions','reporting_freezes','timesheet_legacy_sources','timesheet_audit']] as $service=>$tables) {
    try {
        $prefix='TARGET_'.strtoupper($service).'_DB';
        invPg(['host'=>getenv($prefix.'_HOST')?:getenv('TARGET_SHARED_DB_HOST'),'port'=>getenv($prefix.'_PORT')?:5432,'database'=>getenv($prefix.'_DATABASE')?:getenv('TARGET_SHARED_DB_DATABASE'),'username'=>getenv($prefix.'_USERNAME'),'password'=>getenv($prefix.'_PASSWORD'),'sslmode'=>getenv($prefix.'_SSLMODE')?:'disable'],$service,$tables,'target_'.$service);
    } catch(Throwable $e) {$failed=true;invLine(['source'=>'target_'.$service,'status'=>'BLOCKED','error_class'=>get_class($e),'error_code'=>(string)$e->getCode()]);}
}
exit($failed?2:0);
