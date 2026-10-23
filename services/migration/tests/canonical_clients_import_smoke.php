<?php
use Illuminate\Support\Facades\DB;
$path=tempnam(sys_get_temp_dir(),'canonical-clients-');
putenv('MIGRATION_METADATA_DATABASE='.$path);
putenv('MIGRATION_IDENTITY_DATABASE='.$path.'.identity');
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
class CanonicalClientsFixture extends \App\Migration\Services\ClientsMigration {
    public array $fixture=[];
    protected function extract(): array{return $this->fixture;}
    protected function assertCheckpoint(): void{}
}
function verifyCanonical(bool $condition,string $message): void{if(!$condition)throw new RuntimeException($message);}
$pdo=['driver'=>'sqlite','database'=>':memory:','prefix'=>''];
foreach(['employees','clients'] as $name){config(['database.connections.target_'.$name=>$pdo]);DB::purge('target_'.$name);}
$employees=DB::connection('target_employees');$clients=DB::connection('target_clients');
$employees->statement('CREATE TABLE employees(id INTEGER PRIMARY KEY,login TEXT,full_name TEXT)');
$employees->table('employees')->insert(['id'=>10,'login'=>'synthetic.staff','full_name'=>'Synthetic Staff']);
foreach([
    'clients'=>'name TEXT,description TEXT,type TEXT,sector TEXT,sales_employee_id INTEGER,account_employee_id INTEGER,act_approval_days INTEGER,payment_days INTEGER',
    'projects'=>'client_id INTEGER,name TEXT,is_default BOOLEAN',
    'partner_specialists'=>'full_name TEXT',
    'client_members'=>'client_id INTEGER,employee_id INTEGER,partner_specialist_id INTEGER,specialist_name TEXT,UNIQUE(client_id,employee_id),UNIQUE(client_id,partner_specialist_id)',
    'member_terms'=>'client_member_id INTEGER,project_member_id INTEGER,technology TEXT,level TEXT,hourly_rate DECIMAL(12,2),hours_per_day DECIMAL(5,2),valid_from TEXT,valid_to TEXT',
    'project_assignments'=>'client_member_id INTEGER,project_id INTEGER,valid_from TEXT,valid_to TEXT',
    'leads'=>'name TEXT', 'contact_people'=>'full_name TEXT','client_legal_entities'=>'name TEXT',
    'client_requests'=>'client_id INTEGER,lead_id INTEGER','positions'=>'name TEXT', 'connection_attempts'=>'name TEXT','reporting_periods'=>'client_id INTEGER',
] as $table=>$fields){
    // Table constraints must follow columns in SQLite.
    $constraint='';if(str_contains($fields,',UNIQUE')){[$fields,$constraint]=explode(',UNIQUE',$fields,2);$constraint=',UNIQUE'.$constraint;}
    $clients->statement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY AUTOINCREMENT,'.$fields.',created_at TEXT,updated_at TEXT'.$constraint.')');
}
$store=app(\App\Migration\Core\MigrationStore::class);
$mappingRun=$store->beginRun('employees','migrate');$store->saveMapping($mappingRun,'employees','employee','staff-uuid',10);$store->finishRun($mappingRun,'completed');
$module=new CanonicalClientsFixture($store);
$module->fixture=array_fill_keys(config('migration.legacy.clients.required_tables'),[]);
$module->fixture['users']=[['id'=>1,'email'=>'synthetic.staff@example.invalid','external_key'=>'staff-uuid','surname'=>'Synthetic','name'=>'Staff','patronymic'=>null]];
$module->fixture['clients']=[['id'=>1,'title'=>'Synthetic client','description'=>null,'type'=>null,'sector_id'=>null,'sales_manager_id'=>null,'account_manager_id'=>null,'approving_term'=>0,'payment_term'=>0]];
$module->fixture['projects']=[['id'=>11,'client_id'=>1,'title'=>'A'],['id'=>12,'client_id'=>1,'title'=>'B']];
$module->fixture['members']=[
    ['id'=>21,'memberable_type'=>'employee','memberable_id'=>1,'client_id'=>1,'project_id'=>11],
    ['id'=>22,'memberable_type'=>'employee','memberable_id'=>1,'client_id'=>1,'project_id'=>12],
];
$module->fixture['technologies']=[['id'=>1,'title'=>'PHP']];
$base=['technology_id'=>1,'grade'=>'Senior','rate'=>'100.00','workload'=>'8.00','is_revision'=>false,'reason'=>null];
$module->fixture['rates']=[
    ['id'=>31,'member_id'=>21,'start_date'=>'2026-01-01','end_date'=>'2026-01-20']+$base,
    ['id'=>32,'member_id'=>22,'start_date'=>'2026-01-10','end_date'=>'2026-01-31']+$base,
];
config(['migration.clients_defer_rate_amounts'=>false]);
$run=function(bool $dry)use($module,$store){$id=$store->beginRun('clients',$dry?'dry-run':'migrate');$r=$module->migrate($id,$dry);$store->finishRun($id,'completed',$r);return $r;};
verifyCanonical($run(true)['conflicts']===0,'Compatible source terms must pass');
verifyCanonical(!$clients->table('clients')->exists(),'Dry-run must not write clients');
verifyCanonical($run(false)['conflicts']===0,'Canonical import failed');
verifyCanonical($clients->table('client_members')->count()===1,'Multi-project person duplicated ClientMember');
verifyCanonical($clients->table('member_terms')->count()===1,'Compatible overlapping terms did not merge');
$term=$clients->table('member_terms')->first();
verifyCanonical($term->valid_from==='2026-01-01'&&$term->valid_to==='2026-01-31','Commercial union is incorrect');
verifyCanonical($store->mapping('clients','members',21)===$store->mapping('clients','members',22),'Member aliases lost');
verifyCanonical($store->mapping('clients','rates',31)===$store->mapping('clients','rates',32),'Term aliases lost');
verifyCanonical(!$clients->table('projects')->where('is_default',true)->exists(),'Synthetic default project created');
verifyCanonical(!$clients->table('project_assignments')->exists(),'Commercial dates fabricated project assignments');
verifyCanonical($module->validate($store->beginRun('clients','validate'))['ok'],'Canonical alias reconciliation failed');
verifyCanonical($run(false)['conflicts']===0,'Canonical retry failed');
verifyCanonical($clients->table('member_terms')->count()===1,'Retry duplicated terms');
$module->fixture['rates'][1]['rate']='101.00';
$blocked=false;try{$run(true);}catch(\App\Migration\Core\SourceRowConflict $e){$blocked=$e->reason==='SOURCE_PERIOD_OVERLAP';}
verifyCanonical($blocked,'Incompatible overlapping rates must block');
$module->fixture['rates'][1]['rate']='100.00';
config(['migration.clients_defer_rate_amounts'=>true]);
$blocked=false;try{$run(true);}catch(\App\Migration\Core\SourceRowConflict $e){$blocked=$e->reason==='SOURCE_PERIOD_OVERLAP';}
verifyCanonical($blocked,'Unknown encrypted overlap must not be merged as equal');
config(['migration.clients_defer_rate_amounts'=>false]);
$module->fixture['feedback']=[['id'=>901,'content'=>'Synthetic original feedback']];
verifyCanonical($run(true)['conflicts']===1,'Unproven feedback must block domain import');
verifyCanonical(!$module->validate($store->beginRun('clients','validate'))['ok'],'Unmapped feedback must fail reconciliation');
$module->fixture['feedback']=[];
$clientId=(int)$store->mapping('clients','clients',1);
verifyCanonical(\App\Migration\Core\TimesheetsClientMemberMatching::sourceClientIds('A')===[$clientId],'Source project must resolve target client by evidence');
verifyCanonical(count(\App\Migration\Core\TimesheetsClientMemberMatching::candidates(10))===1,'Operator candidates must use ClientMember rather than ProjectMember');
$clients->table('client_members')->insert(['client_id'=>$clientId,'employee_id'=>null,'partner_specialist_id'=>700,'specialist_name'=>'Synthetic partner']);
$partnerCandidates=\App\Migration\Core\TimesheetsClientMemberMatching::candidates(null);
verifyCanonical(count($partnerCandidates)===1&&$partnerCandidates[0]['partner_specialist_id']===700&&$partnerCandidates[0]['employee_id']===null,'Partner operator candidates must preserve identity');
echo "Canonical Clients import: one membership, compatible term union, full lineage, no fabricated assignments/defaults, retries, validation, incompatible/unknown overlap guards PASS\n";
