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
    'project_members'=>'project_id INTEGER,specialist_id INTEGER,partner_specialist_id INTEGER,specialist_name TEXT,source_attempt_id INTEGER,UNIQUE(project_id,specialist_id),UNIQUE(project_id,partner_specialist_id)',
    'client_member_feedbacks'=>'client_member_id INTEGER,project_id INTEGER,text TEXT,created_by_username TEXT,occurred_on TEXT,rating TEXT,legacy_interview_id INTEGER',
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
$feedback=['id'=>901,'member_id'=>21,'content'=>'Synthetic original feedback','date'=>'2026-01-15',
    'rating'=>'positive: 5/5','interview_id'=>77,'created_at'=>null,'updated_at'=>null];
$module->fixture['feedback']=[$feedback];
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
verifyCanonical($clients->table('project_members')->count()===2,'Undated source project evidence lost');
$savedFeedback=$clients->table('client_member_feedbacks')->first();
verifyCanonical($savedFeedback->text===$feedback['content']&&$savedFeedback->occurred_on===$feedback['date']&&$savedFeedback->rating===$feedback['rating']&&(int)$savedFeedback->legacy_interview_id===77,'Feedback fields lost');
verifyCanonical($savedFeedback->created_by_username===null&&$savedFeedback->created_at===null&&$savedFeedback->updated_at===null,'Feedback author/timestamps fabricated');
verifyCanonical($module->validate($store->beginRun('clients','validate'))['ok'],'Canonical alias reconciliation failed');
verifyCanonical($run(false)['conflicts']===0,'Canonical retry failed');
verifyCanonical($clients->table('member_terms')->count()===1,'Retry duplicated terms');
verifyCanonical($clients->table('client_member_feedbacks')->count()===1,'Retry duplicated feedback');
$clients->table('client_member_feedbacks')->where('id',$savedFeedback->id)->update(['text'=>'Manual correction']);
verifyCanonical($run(true)['conflicts']===1,'Manual feedback correction overwritten');
$clients->table('client_member_feedbacks')->where('id',$savedFeedback->id)->update(['text'=>$feedback['content']]);
$module->fixture['feedback'][0]['rating']='changed';
verifyCanonical($run(true)['conflicts']===1,'Feedback source drift ignored');
$module->fixture['feedback']=[$feedback];
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
$module->fixture['feedback']=[$feedback];
// Model an existing populated target after the Clients expand migration. Old
// mappings still point at retained ProjectMembers and the original term IDs.
$clients->statement('CREATE TABLE member_term_lineage (source_term_id INTEGER PRIMARY KEY,canonical_term_id INTEGER,original_client_member_id INTEGER,original_payload TEXT)');
$membership=(int)$store->mapping('clients','members',21);
$canonicalTerm=(int)$store->mapping('clients','rates',31);
$expectedPrior=[];
$module->fixture['members'][]=['id'=>23,'memberable_type'=>'employee','memberable_id'=>1,'client_id'=>1,'project_id'=>null];
$defaultProject=$clients->table('projects')->insertGetId(['client_id'=>(int)$store->mapping('clients','clients',1),'name'=>null,'is_default'=>true]);
$store->saveMapping($mappingRun,'clients','default_projects',1,$defaultProject);
foreach($module->fixture['members'] as $source){
    $historyId=empty($source['project_id']) ? $clients->table('project_members')->insertGetId([
        'project_id'=>$defaultProject,'specialist_id'=>10,'partner_specialist_id'=>null,'specialist_name'=>'Synthetic Staff','source_attempt_id'=>null])
        : (int)$store->mapping('clients','member_project_history',$source['id']);
    $old=(array)$clients->table('project_members')->find($historyId);unset($old['id'],$old['created_at'],$old['updated_at']);
    $metadata=['source'=>$source,'target_table'=>'project_members','target_payload'=>$old];
    $store->saveMapping($mappingRun,'clients','members',$source['id'],$historyId,$metadata);
    $expectedPrior['members'][$source['id']]=['target_id'=>$historyId,'metadata'=>$metadata];
}
foreach($module->fixture['rates'] as $i=>$source){
    $old=['client_member_id'=>$membership,'project_member_id'=>(int)$store->mapping('clients','member_project_history',$source['member_id']),
        'technology'=>'PHP','level'=>'Senior','hourly_rate'=>100,'hours_per_day'=>8,'valid_from'=>$source['start_date'],'valid_to'=>$source['end_date']];
    $oldId=$i===0?$canonicalTerm:$clients->table('member_terms')->insertGetId(array_replace($old,['client_member_id'=>null]));
    $metadata=['source'=>$source,'target_table'=>'member_terms','target_payload'=>array_diff_key($old,['client_member_id'=>true])];
    $store->saveMapping($mappingRun,'clients','rates',$source['id'],$oldId,$metadata);
    $clients->table('member_term_lineage')->insert(['source_term_id'=>$oldId,'canonical_term_id'=>$canonicalTerm,
        'original_client_member_id'=>$membership,'original_payload'=>json_encode($old)]);
    $expectedPrior['rates'][$source['id']]=['target_id'=>$oldId,'metadata'=>$metadata];
}
$clients->table('member_terms')->where('id',$canonicalTerm)->update(['project_member_id'=>(int)$store->mapping('clients','member_project_history',21)]);
// Project history mappings are newly introduced by this adapter.
DB::table('migration_mappings')->where(['service'=>'clients','entity_type'=>'member_project_history'])->delete();
$beforePreview=DB::table('migration_mappings')->where('service','clients')->orderBy('id')->get()->toJson();
$rebasedPreview=$run(true);
verifyCanonical($rebasedPreview['conflicts']===0,'Populated legacy mappings must pass proof-based dry-run: '.json_encode(DB::table('migration_conflicts')->orderByDesc('id')->limit(6)->get()));
verifyCanonical(DB::table('migration_mappings')->where('service','clients')->orderBy('id')->get()->toJson()===$beforePreview,'Dry-run changed mapping metadata');
verifyCanonical($run(false)['conflicts']===0,'Populated legacy mappings failed rebase');
foreach([false,false] as $dry)verifyCanonical($run($dry)['conflicts']===0,'Rebased legacy retry failed');
foreach($expectedPrior as $entity=>$items)foreach($items as $sourceId=>$previous){
    $metadata=json_decode(DB::table('migration_mappings')->where(['service'=>'clients','entity_type'=>$entity,'legacy_id'=>(string)$sourceId])->value('metadata'),true);
    verifyCanonical(($metadata['previous_mapping']??null)==$previous,'Prior '.$entity.' mapping evidence lost on retry');
}
verifyCanonical($module->validate($store->beginRun('clients','validate'))['ok'],'Rebased populated canonical mappings fail reconciliation');
verifyCanonical($clients->table('client_members')->count()===1&&$clients->table('project_members')->count()===3,'Rebase duplicated identities/history');
verifyCanonical($store->mapping('clients','member_project_history',23)===null,'Synthetic default container became source project history');
$clients->table('project_members')->where('id',(int)$store->mapping('clients','member_project_history',21))->update(['specialist_name'=>'Manual correction']);
verifyCanonical($run(true)['conflicts']>0,'Manual old ProjectMember edits silently overwritten');
$clients->table('project_members')->where('id',(int)$store->mapping('clients','member_project_history',21))->update(['specialist_name'=>'Synthetic Staff']);
$module->fixture['feedback']=[];
$clientId=(int)$store->mapping('clients','clients',1);
verifyCanonical(\App\Migration\Core\TimesheetsClientMemberMatching::sourceClientIds('A')===[$clientId],'Source project must resolve target client by evidence');
verifyCanonical(count(\App\Migration\Core\TimesheetsClientMemberMatching::candidates(10))===1,'Operator candidates must use ClientMember rather than ProjectMember');
$clients->table('client_members')->insert(['client_id'=>$clientId,'employee_id'=>null,'partner_specialist_id'=>700,'specialist_name'=>'Synthetic partner']);
$partnerCandidates=\App\Migration\Core\TimesheetsClientMemberMatching::candidates(null);
verifyCanonical(count($partnerCandidates)===1&&$partnerCandidates[0]['partner_specialist_id']===700&&$partnerCandidates[0]['employee_id']===null,'Partner operator candidates must preserve identity');
echo "Canonical Clients import: one membership, compatible term union, full lineage, no fabricated assignments/defaults, retries, validation, incompatible/unknown overlap guards PASS\n";
