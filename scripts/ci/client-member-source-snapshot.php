<?php
/** Fixed SELECT-only exporter for the CURRENT deployed Migration container.
 * STDOUT IS PRIVATE INPUT, NEVER A LOG/ARTIFACT. No application bootstrap.
 * Credentials stay here; only the legacy data-decryption key travels in the
 * private snapshot. Every PostgreSQL read is in an enforced read-only snapshot.
 */
ini_set('display_errors','0');
ini_set('log_errors','0');
require '/app/vendor/autoload.php';
use Illuminate\Encryption\Encrypter;
function snapOut(array $value):void {echo json_encode($value,JSON_THROW_ON_ERROR)."\n";}
function snapSecret(string $value):string {
    if(str_starts_with($value,'migration:v1:'))return (new App\Migration\Core\MigrationCredentialCipher)->decryptString($value);
    $key=getenv('MIGRATION_APP_KEY')?:getenv('APP_KEY');
    if(str_starts_with($key,'base64:'))$key=base64_decode(substr($key,7),true);
    return (new Encrypter($key,'AES-256-CBC'))->decryptString($value);
}
function snapPg(array $c,string $schema,array $tables,string $label):void {
    $db=new PDO('pgsql:host='.$c['host'].';port='.(int)($c['port']??5432).';dbname='.$c['database'].';sslmode='.($c['sslmode']??'disable'),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    try {
        $db->exec("SET LOCAL statement_timeout='30s'");$db->exec("SET LOCAL lock_timeout='2s'");
        if($db->query('SHOW transaction_read_only')->fetchColumn()!=='on')throw new RuntimeException('Read-only enforcement');
        foreach($tables as $table) {
            if(!preg_match('/^[a-z_]+$/D',$table)||!preg_match('/^[a-z_]+$/D',$schema))throw new LogicException('Fixed identifier');
            $q=$db->prepare('SELECT a.attname AS name,pg_catalog.format_type(a.atttypid,a.atttypmod) AS type FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_class c ON c.oid=a.attrelid JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=? AND c.relname=? AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum');
            $q->execute([$schema,$table]);$columns=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$columns){snapOut(['kind'=>'missing','database'=>$label,'table'=>$table]);continue;}
            snapOut(['kind'=>'table','database'=>$label,'table'=>$table,'columns'=>$columns]);
            $q=$db->query('SELECT * FROM "'.$schema.'"."'.$table.'"');$count=0;
            while($row=$q->fetch(PDO::FETCH_ASSOC)){snapOut(['kind'=>'row','database'=>$label,'table'=>$table,'row'=>$row]);$count++;}
            snapOut(['kind'=>'count','database'=>$label,'table'=>$table,'rows'=>$count]);
        }
    } finally {$db->rollBack();}
}
try {
    $meta=new PDO('sqlite:'.(getenv('MIGRATION_METADATA_DATABASE')?:'/data/migration.sqlite'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
    $meta->exec('PRAGMA query_only=ON');$meta->beginTransaction();
    // Exact extraction registry; no remote application/config execution.
    $sources=[
        'clients'=>['users','clients','projects','members','rates','leads','contacts','client_contact','lead_contact','legal_entities','client_requests','positions','attempts','results','attempt_result','reporting_periods','reporting_period_rate','sectors','technologies','grades','grade_rates','departments','feedback','interviews','notes','reviews','subcontracts','attachments','legal_documents','activity_log','settings','client_technology','user_technology'],
        'timesheets'=>['users','members','rates','timesheets','month_confirmations','time_records','vacations','business_dates','departments'],
    ];
    foreach($meta->query("SELECT * FROM migration_connections WHERE service IN ('clients','timesheets','vacations')") as $profile) {
        $service=$profile['service'];
        $sourceKey=hash('sha256',json_encode([$service,mb_strtolower(trim($profile['host'])),(int)$profile['port'],$profile['database'],'public']));
        snapOut(['kind'=>'identity_scope','service'=>$service,'source_key'=>$sourceKey]);
        if(!isset($sources[$service]))continue;
        if(!(bool)$profile['readonly_acknowledged'])throw new RuntimeException('Unacknowledged profile');
        $profile['password']=snapSecret($profile['password_encrypted']);
        if($service==='clients'&&!empty($profile['legacy_app_key_encrypted']))snapOut(['kind'=>'legacy_key','key'=>snapSecret($profile['legacy_app_key_encrypted']),'cipher'=>$profile['legacy_cipher']]);
        snapPg($profile,'public',$sources[$service],'legacy_'.$service);
    }
    foreach(['migration_runs','migration_mappings','migration_overrides'] as $table) {
        foreach($meta->query('SELECT * FROM '.$table." WHERE service IN ('employees','clients','timesheets','vacations')") as $row)snapOut(['kind'=>'metadata','table'=>$table,'row'=>$row]);
    }
    $meta->rollBack();
    $identityPath=getenv('MIGRATION_IDENTITY_DATABASE')?:'/identity/employee-logins.sqlite';
    if(is_file($identityPath)) {
        $identity=new PDO('sqlite:'.$identityPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
        $identity->exec('PRAGMA query_only=ON');$identity->beginTransaction();
        foreach($identity->query('SELECT source_key,old_login,new_login,actor,updated_at FROM employee_logins') as $row)snapOut(['kind'=>'identity','row'=>$row]);
        $identity->rollBack();
    }
    $targets=[
        'employees'=>['employees'],
        'clients'=>['migrations','clients','projects','project_members','member_terms','leads','contact_people','contact_relations','client_requests','positions','connection_attempts','connection_attempt_events','reporting_periods','attempt_interviews','client_legal_entities','client_notes','reporting_period_history','project_member_feedbacks','lead_events','lead_legal_entities','partner_specialists','client_account_delegations','client_history_events','reporting_period_approved_hours','contact_methods','client_contour_permissions','client_contour_permission_audit'],
        'timesheets'=>['migrations','timesheet_entries','employee_confirmations','final_approvals','period_locks','timesheet_audit','final_approval_exceptions'],
    ];
    foreach($targets as $service=>$tables) {
        $prefix='TARGET_'.strtoupper($service).'_DB';
        snapPg(['host'=>getenv($prefix.'_HOST')?:getenv('TARGET_SHARED_DB_HOST'),'port'=>getenv($prefix.'_PORT')?:5432,'database'=>getenv($prefix.'_DATABASE')?:getenv('TARGET_SHARED_DB_DATABASE'),'username'=>getenv($prefix.'_USERNAME'),'password'=>getenv($prefix.'_PASSWORD'),'sslmode'=>getenv($prefix.'_SSLMODE')?:'disable'],$service,$tables,'target_'.$service);
    }
    snapOut(['kind'=>'complete','version'=>1]);
} catch(Throwable $e) {
    // Never print exception messages/queries/connection strings or partial rows.
    fwrite(STDERR,'Snapshot BLOCKED: '.get_class($e).' code '.preg_replace('/[^A-Za-z0-9]/','',(string)$e->getCode())."\n");exit(2);
}
