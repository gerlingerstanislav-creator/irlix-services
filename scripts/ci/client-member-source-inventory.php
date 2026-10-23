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
function invForeignKeys(PDO $db,string $schema,?string $table=null):array {
    // information_schema.table_constraints can hide FKs from SELECT-only roles.
    // Catalog metadata remains readable and does not imply row access.
    $sql="SELECT src.relname AS table_name,sa.attname AS column_name,dst.relname AS referenced_table,da.attname AS referenced_column,
        CASE con.confdeltype WHEN 'a' THEN 'NO ACTION' WHEN 'r' THEN 'RESTRICT' WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL' WHEN 'd' THEN 'SET DEFAULT' END AS on_delete
        FROM pg_catalog.pg_constraint con JOIN pg_catalog.pg_class src ON src.oid=con.conrelid
        JOIN pg_catalog.pg_namespace ns ON ns.oid=src.relnamespace JOIN pg_catalog.pg_class dst ON dst.oid=con.confrelid
        CROSS JOIN LATERAL unnest(con.conkey,con.confkey) AS k(attnum,refattnum)
        JOIN pg_catalog.pg_attribute sa ON sa.attrelid=src.oid AND sa.attnum=k.attnum
        JOIN pg_catalog.pg_attribute da ON da.attrelid=dst.oid AND da.attnum=k.refattnum
        WHERE con.contype='f' AND ns.nspname=?".($table===null?'':' AND src.relname=?').' ORDER BY src.relname,sa.attname';
    $q=$db->prepare($sql);$q->execute($table===null?[$schema]:[$schema,$table]);return $q->fetchAll(PDO::FETCH_ASSOC);
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
            invLine(['source'=>$label,'table'=>$table,'foreign_keys'=>invForeignKeys($pdo,$schema,$table)]);
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
function invEvidence(PDO $source,PDO $metadata):void {
    $source->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    try {
        $source->exec("SET LOCAL statement_timeout='20s'");$source->exec("SET LOCAL lock_timeout='2s'");
        if($source->query('SHOW transaction_read_only')->fetchColumn()!=='on')throw new RuntimeException('Read-only not enforced');
        $catalog=$source->query("SELECT c.relname AS table_name,a.attname AS column_name,pg_catalog.format_type(a.atttypid,a.atttypmod) AS data_type,NOT a.attnotnull AS nullable,has_table_privilege(current_user,c.oid,'SELECT') AS readable FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace JOIN pg_catalog.pg_attribute a ON a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f') ORDER BY c.relname,a.attnum")->fetchAll(PDO::FETCH_ASSOC);
        $foreignKeys=invForeignKeys($source,'public');
        invLine(['source'=>'legacy_timesheets_auxiliary_schema','columns'=>$catalog,'foreign_keys'=>$foreignKeys]);
        // IDs and historical payloads remain in memory; only aggregate evidence escapes.
        $facts=$source->query('SELECT * FROM public.timesheets WHERE rate_id IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        $byId=array_column($facts,null,'id');$evidence=[];$strict=[];
        if($byId)foreach([['migration_mappings','metadata'],['migration_conflicts','context']] as [$table,$field]) {
            $marks=implode(',',array_fill(0,count($byId),'?'));
            $q=$metadata->prepare('SELECT legacy_id,'.$field.' AS payload FROM '.$table." WHERE service='timesheets' AND entity_type='timesheets' AND legacy_id IN (".$marks.')');
            $q->execute(array_map('strval',array_keys($byId)));
            while($item=$q->fetch(PDO::FETCH_ASSOC)) {
                $payload=json_decode($item['payload']??'{}',true);$old=$payload['source']??null;
                if(!is_array($old)||!isset($old['rate_id'])||!ctype_digit((string)$old['rate_id'])||(int)$old['rate_id']<1)continue;
                $id=(string)$item['legacy_id'];$rate=(string)$old['rate_id'];$evidence[$id][$rate]=true;
                $current=$byId[$id];$complete=true;
                foreach(['id','date','hours','description','is_submitted'] as $key)
                    if(!array_key_exists($key,$old))$complete=false;
                // Strong evidence requires the entire retained original row except
                // the missing reference to agree, not a coincidentally equal hour.
                foreach($current as $key=>$value)if($key!=='rate_id') {
                    if(!array_key_exists($key,$old)){$complete=false;continue;}
                    $previous=$old[$key];
                    if($value===null||$previous===null){if($value!==$previous)$complete=false;}
                    elseif(is_bool($value)||is_bool($previous)){if($value!==$previous)$complete=false;}
                    elseif((string)$previous!==(string)$value)$complete=false;
                }
                if($complete)$strict[$id][$rate]=true;
            }
        }
        $rates=$source->query('SELECT * FROM public.rates')->fetchAll(PDO::FETCH_ASSOC);$rateById=array_column($rates,null,'id');
        $memberIds=array_fill_keys($source->query('SELECT id FROM public.members')->fetchAll(PDO::FETCH_COLUMN),true);
        $withEvidence=count($evidence);$unique=0;$existing=0;$memberBound=0;$savedTarget=0;$canonicalTarget=0;
        $target=null;$canonicalAvailable=null;
        if($strict) {
            $prefix='TARGET_CLIENTS_DB';
            $target=new PDO('pgsql:host='.(getenv($prefix.'_HOST')?:getenv('TARGET_SHARED_DB_HOST')).';port='.(int)(getenv($prefix.'_PORT')?:5432).';dbname='.(getenv($prefix.'_DATABASE')?:getenv('TARGET_SHARED_DB_DATABASE')).';sslmode='.(getenv($prefix.'_SSLMODE')?:'disable'),getenv($prefix.'_USERNAME'),getenv($prefix.'_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $target->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $target->exec("SET LOCAL statement_timeout='20s'");$target->exec("SET LOCAL lock_timeout='2s'");
            if($target->query('SHOW transaction_read_only')->fetchColumn()!=='on')throw new RuntimeException('Read-only not enforced');
            $canonicalAvailable=in_array($target->query("SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='clients' AND table_name='member_terms' AND column_name='client_member_id')")->fetchColumn(),[true,1,'1','t','true'],true);
        }
        try {
            foreach($strict as $id=>$candidates) {
                if(count($candidates)!==1)continue;$unique++;$rate=(string)array_key_first($candidates);$row=$rateById[$rate]??null;
                if(!$row)continue;$existing++;
                if(!isset($memberIds[$row['member_id']]))continue;$memberBound++;
                $q=$metadata->prepare("SELECT target_id,metadata FROM migration_mappings WHERE service='timesheets' AND entity_type='rates' AND legacy_id=?");$q->execute([$rate]);$mapping=$q->fetch(PDO::FETCH_ASSOC);
                if(!$mapping)continue;$old=json_decode($mapping['metadata']??'{}',true);
                if(($old['source']??null)!=$row||($old['target_table']??'member_terms')!=='member_terms')continue;
                $q=$target->prepare('SELECT * FROM clients.member_terms WHERE id=?');$q->execute([$mapping['target_id']]);$term=$q->fetch(PDO::FETCH_ASSOC);
                if(!$term)continue;
                $payload=$old['target_payload']??[];$matches=!empty($payload);
                foreach($payload as $field=>$expected) {
                    $actual=$term[$field]??null;
                    if($expected===$actual||($expected!==null&&$actual!==null&&(string)$expected===(string)$actual))continue;
                    if(is_numeric($expected)&&is_numeric($actual)) {
                        // Only fractional zeroes may be removed; integer 100 != 1.
                        $normalize=static fn($v)=>str_contains((string)$v,'.')?rtrim(rtrim((string)$v,'0'),'.'):(string)$v;
                        if($normalize($expected)===$normalize($actual))continue;
                    }
                    $matches=false;
                }
                if(!$matches)continue;$savedTarget++;
                if($canonicalAvailable&&!empty($term['client_member_id'])&&empty($term['superseded_by_term_id']))$canonicalTarget++;
            }
        } finally {if($target&&$target->inTransaction())$target->rollBack();}
        invLine(['source'=>'null_rate_historical_evidence','null_rate_facts'=>count($facts),'with_any_saved_nonnull_source_rate'=>$withEvidence,'with_unique_full_row_historical_evidence'=>$unique,'evidenced_rates_still_exist'=>$existing,'evidenced_rates_have_source_member'=>$memberBound,'verified_saved_target_term_payloads'=>$savedTarget,'canonical_target_term_bindings'=>$canonicalTarget,'canonical_terms_available'=>$canonicalAvailable]);
        $partners=$source->query("SELECT id,employee_id FROM public.members WHERE lower(memberable_type) IN ('subcontract','contractor','external','partner')")->fetchAll(PDO::FETCH_ASSOC);
        $saved=0;$overrides=0;$historicalUuid=0;
        foreach($partners as $partner) {
            $q=$metadata->prepare("SELECT metadata FROM migration_mappings WHERE service='timesheets' AND entity_type='members' AND legacy_id=?");$q->execute([(string)$partner['id']]);$raw=$q->fetchColumn();
            if($raw!==false){$saved++;$old=json_decode($raw,true);if(!empty($old['source']['employee_id']))$historicalUuid++;}
            $q=$metadata->prepare("SELECT count(*) FROM migration_overrides WHERE service='timesheets' AND entity_type='members' AND legacy_id=?");$q->execute([(string)$partner['id']]);$overrides+=(int)$q->fetchColumn();
        }
        invLine(['source'=>'partner_saved_identity_evidence','partner_members'=>count($partners),'saved_member_mappings'=>$saved,'saved_member_overrides'=>$overrides,'saved_sources_with_uuid'=>$historicalUuid]);
    } finally {$source->rollBack();}
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
        invEvidence($src,$metadata);
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
