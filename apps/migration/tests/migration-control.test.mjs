import test from 'node:test';
import assert from 'node:assert/strict';
import {text,entityRows,inventoryRows,causeGroups,stageRows,tableEvidenceRows,serviceEvidenceRows,migrationTree,diagnosticItems,progressText} from '../src/migration-control-model.js';
test('unknown values remain unknown while confirmed zero remains zero',()=>{assert.equal(text(null),'Нет данных');assert.equal(text(undefined),'Нет данных');assert.equal(text(0),'0');});
test('data outcomes only use migration stage and preserve unknown counters',()=>{const report={runs:[{service:'clients',mode:'inspect',tables:[{table_name:'users',total:99}]},{service:'clients',mode:'migrate',tables:[{table_name:'users',total:null,source_outcomes:{metadata_only:7}}]}]};assert.equal(entityRows(report).length,1);assert.equal(entityRows(report)[0].total,null);assert.equal(entityRows(report,'employees').length,0);});
test('inventory deduplicates snapshots by service and includes unknown fields',()=>{const inventory={service:'clients',tables:[{name:'legacy',columns:[{name:'unexpected',mapping:{fate:'unmapped'}}]}]};assert.equal(inventoryRows({inventory:[inventory,inventory,null]}).length,1);assert.equal(inventoryRows({inventory:[inventory]})[0].mapping.fate,'unmapped');});
test('groups are independent of paged errors and examples limited to three',()=>{const report={root_cause_groups:Array.from({length:31},(_,i)=>({service:'clients',code:`C${i}`,count:201})),runs:[{service:'clients',diagnostics:{items:Array.from({length:205},(_,i)=>({code:'C0',legacy_id:i}))}}]};assert.equal(causeGroups(report).length,31);assert.equal(causeGroups(report)[0].count,201);assert.equal(causeGroups(report)[0].examples.length,3);assert.equal(causeGroups(report,'vacations').length,0);});
test('stages include dry-run contract and no synthetic success for unregistered stage',()=>{const rows=stageRows({services:['clients'],runs:[{service:'clients',mode:'dry-run',status:'completed'}]},null);assert.equal(rows.find(row=>row.mode==='dry-run').status,'completed');assert.equal(rows.find(row=>row.mode==='validate').known,false);});
test('live counters replace queued archived stage and snapshot remains a separate stage',()=>{const rows=stageRows({scope:'clients',services:['clients'],phase:'snapshot',status:'running',runs:[{service:'clients',mode:'migrate',status:'running',tables:[{total:10,processed_count:4}]}]},{runs:[{service:'clients',mode:'migrate',status:'queued',tables:[]}]});assert.equal(rows[0].mode,'snapshot');assert.equal(rows[0].status,'running');const migrate=rows.find(row=>row.mode==='migrate');assert.equal(migrate.status,'running');assert.equal(migrate.run.total,10);assert.equal(migrate.run.processed_count,4);});

test('explicit field rules classify tables without guessing from zero counters',()=>{
 const inventory={service:'clients',complete:true,tables:[
  {name:'clients',total:10,columns:[{name:'title',mapping:{fate:'direct'}},{name:'mystery',mapping:{fate:'unmapped'}}]},
  {name:'notes',total:4,columns:[{name:'body',mapping:{fate:'metadata_only'}}]},
  {name:'legacy_unknown',total:0,columns:[{name:'x',mapping:{fate:'unmapped'}}]},
 ]};
 const rows=tableEvidenceRows({inventory:[inventory],runs:[]});
 assert.equal(rows.find(row=>row.table_name==='clients').expectation.status,'expected');
 assert.equal(rows.find(row=>row.table_name==='clients').expectation.decision_complete,false);
 assert.equal(rows.find(row=>row.table_name==='notes').expectation.status,'excluded');
 assert.equal(rows.find(row=>row.table_name==='legacy_unknown').expectation.status,'undocumented');
 assert.equal(rows.find(row=>row.table_name==='legacy_unknown').total,0);
 assert.equal(rows.find(row=>row.table_name==='legacy_unknown').state,'unknown');
});
test('service summary only counts unprocessed rows for explicitly expected tables',()=>{
 const report={inventory:[{service:'clients',complete:true,tables:[
  {name:'clients',total:10,expectation:{status:'expected'},columns:[]},
  {name:'notes',total:40,expectation:{status:'excluded'},columns:[]},
  {name:'mystery',total:7,expectation:{status:'undocumented'},columns:[]},
 ]}],runs:[{service:'clients',mode:'migrate',tables:[
  {table_name:'clients',total:10,expectation:{status:'expected'},source_outcomes:{unprocessed:3}},
  {table_name:'notes',total:40,expectation:{status:'excluded'},source_outcomes:{unprocessed:40}},
  {table_name:'mystery',total:7,expectation:{status:'undocumented'},source_outcomes:{unprocessed:7}},
 ]}]};
 const row=serviceEvidenceRows(report,{services:['clients'],service_results:[]})[0];
 assert.equal(row.expected_unprocessed,3);
 assert.equal(row.excluded_tables,1);
 assert.equal(row.undocumented_tables,1);
});
test('stages distinguish proven not-started from an unregistered unknown stage',()=>{
 const failed={services:['clients'],service_results:[{service:'clients',status:'failed',stage:'migrate'}],runs:[{service:'clients',mode:'migrate',status:'failed'}]};
 const rows=stageRows(failed,null);
 assert.equal(rows.find(row=>row.mode==='validate').status,'not_started');
 assert.equal(rows.find(row=>row.mode==='validate').known,true);
 assert.equal(rows.find(row=>row.mode==='dry-run').status,'unknown');
 assert.equal(rows.find(row=>row.mode==='dry-run').known,false);
});

test('UTC database timestamps and explicit UTC timestamps display the same instant', async () => {
 const {dateText}=await import('../src/migration-control-model.js');
 assert.equal(dateText('2026-01-01 00:00:00'),dateText('2026-01-01T00:00:00Z'));
});
// This suite also runs inside migration-web, which intentionally contains no System sources.
// The actual System import and route are exercised by migration-control.browser.cjs
// with MIGRATION_EMBEDDED=1 against the System build.
test('public Migration facade keeps console and control as separate routed pages',async()=>{
 const {readFile}=await import('node:fs/promises');
 const read=relative=>readFile(new URL(relative,import.meta.url),'utf8');
 const [facade,workspace,navigation,legacy]=await Promise.all([read('../src/MigrationConsole.vue'),read('../src/MigrationWorkspace.vue'),read('../src/navigation.js'),read('../src/MigrationConsoleLegacy.vue')]);
 assert.match(facade,/<div v-if="embedded"/);
 assert.doesNotMatch(facade,/<UiTabs/);
 assert.match(facade,/view==='console'/);
 assert.match(facade,/<MigrationControl v-else/);
 assert.doesNotMatch(facade,/<UiAppShell/);
 assert.match(workspace,/startsWith\('\/migration\/control'\)/);
 assert.match(navigation,/href: '\/migration\/control\/'/);
 assert.match(legacy,/Пульт переноса/);
});

test('tree binds root, services and tables to one selected operation only',()=>{
 const operation={id:'100',services:['employees','clients'],scope:'all',status:'partial',service_results:[]};
 const report={operation:{id:'100'},coverage:{semantic_complete:false,expected_unprocessed_rows:3},
  inventory:[{service:'employees',complete:true,tables:[{name:'employees',total:10,columns:[{name:'name',mapping:{fate:'direct'}}]}]}],
  runs:[{service:'employees',mode:'migrate',tables:[{table_name:'employees',total:10,processed_count:7,source_outcomes:{unprocessed:3}}]}],
  root_cause_groups:[{service:'employees',table:'employees',stage:'migrate',severity:'error',code:'BAD',count:24,affected_rows:8}]};
 const root=migrationTree(report,operation);
 assert.equal(root.id,'100');assert.equal(root.services.length,2);
 const employee=root.services.find(x=>x.service==='employees');
 assert.equal(employee.issues.errors,24);
 assert.equal(employee.entities[0].issues.errors,24);
 assert.equal(employee.entities[0].processed,7);
 assert.equal(root.issues.errors,24);
 assert.equal(root.coverage.expected_unprocessed_rows,3);
 const other=migrationTree({operation:{id:'99'},inventory:[],runs:[],root_cause_groups:[]},{id:'99',services:['clients'],status:'failed'});
 assert.equal(other.id,'99');assert.equal(other.services.length,1);assert.equal(other.issues.errors,0);
 assert.equal(other.services[0].entities.length,0);
});
test('tree does not infer zero or progress when migrate report is absent',()=>{
 const root=migrationTree({inventory:[{service:'clients',complete:true,tables:[{name:'clients',total:120,columns:[]}]}],runs:[]},{id:'104',services:['clients'],status:'failed'});
 const row=root.services[0].entities[0];
 assert.equal(row.total,120);
 assert.equal(row.processed,null);
 assert.equal(row.issues.errors,0);
 assert.equal(progressText(row.processed,row.total),'Обработано: нет данных / 120');
 assert.equal(progressText(959,null),'Обработано: 959 / нет данных');
 assert.equal(progressText(7198,7962),'Обработано: 7 198 / 7 962 (90%)');
 assert.equal(progressText(0,0),'Обработано: 0 / 0');
});
test('all 24 individual diagnostic events can be displayed for clicked entity only',()=>{
 const items=Array.from({length:24},(_,i)=>({table:'employees',code:'BAD',severity:'error',legacy_id:String(i),message:'synthetic error '+i}));
 const report={root_cause_groups:[{service:'employees',table:'employees',code:'BAD',stage:'migrate',severity:'error',count:24}],
 runs:[{service:'employees',mode:'migrate',run_id:12,diagnostics:{items}},
       {service:'clients',mode:'migrate',run_id:13,diagnostics:{items:[{table:'employees',code:'BAD',severity:'error',legacy_id:'other'}]}}]};
 const rows=diagnosticItems(report,{service:'employees',table:'employees',severity:'error'});
 assert.equal(rows.length,24);
 assert.equal(rows[0].run_id,12);
 assert.equal(diagnosticItems(report,{service:'clients',table:'employees',severity:'error'}).length,0);
});
