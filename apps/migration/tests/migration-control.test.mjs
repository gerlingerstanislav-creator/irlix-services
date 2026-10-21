import test from 'node:test';
import assert from 'node:assert/strict';
import {text,entityRows,inventoryRows,causeGroups,stageRows} from '../src/migration-control-model.js';
test('unknown values remain unknown while confirmed zero remains zero',()=>{assert.equal(text(null),'Нет данных');assert.equal(text(undefined),'Нет данных');assert.equal(text(0),'0');});
test('data outcomes only use migration stage and preserve unknown counters',()=>{const report={runs:[{service:'clients',mode:'inspect',tables:[{table_name:'users',total:99}]},{service:'clients',mode:'migrate',tables:[{table_name:'users',total:null,source_outcomes:{metadata_only:7}}]}]};assert.equal(entityRows(report).length,1);assert.equal(entityRows(report)[0].total,null);assert.equal(entityRows(report,'employees').length,0);});
test('inventory deduplicates snapshots by service and includes unknown fields',()=>{const inventory={service:'clients',tables:[{name:'legacy',columns:[{name:'unexpected',mapping:{fate:'unmapped'}}]}]};assert.equal(inventoryRows({inventory:[inventory,inventory,null]}).length,1);assert.equal(inventoryRows({inventory:[inventory]})[0].mapping.fate,'unmapped');});
test('groups are independent of paged errors and examples limited to three',()=>{const report={root_cause_groups:Array.from({length:31},(_,i)=>({service:'clients',code:`C${i}`,count:201})),runs:[{service:'clients',diagnostics:{items:Array.from({length:205},(_,i)=>({code:'C0',legacy_id:i}))}}]};assert.equal(causeGroups(report).length,31);assert.equal(causeGroups(report)[0].count,201);assert.equal(causeGroups(report)[0].examples.length,3);assert.equal(causeGroups(report,'vacations').length,0);});
test('stages include dry-run contract and no synthetic success for unregistered stage',()=>{const rows=stageRows({services:['clients'],runs:[{service:'clients',mode:'dry-run',status:'completed'}]},null);assert.equal(rows.find(row=>row.mode==='dry-run').status,'completed');assert.equal(rows.find(row=>row.mode==='validate').known,false);});
test('live counters replace queued archived stage and snapshot remains a separate stage',()=>{const rows=stageRows({scope:'clients',services:['clients'],phase:'snapshot',status:'running',runs:[{service:'clients',mode:'migrate',status:'running',tables:[{total:10,processed_count:4}]}]},{runs:[{service:'clients',mode:'migrate',status:'queued',tables:[]}]});assert.equal(rows[0].mode,'snapshot');assert.equal(rows[0].status,'running');const migrate=rows.find(row=>row.mode==='migrate');assert.equal(migrate.status,'running');assert.equal(migrate.run.total,10);assert.equal(migrate.run.processed_count,4);});

test('UTC database timestamps and explicit UTC timestamps display the same instant', async () => {
 const {dateText}=await import('../src/migration-control-model.js');
 assert.equal(dateText('2026-01-01 00:00:00'),dateText('2026-01-01T00:00:00Z'));
});
test('actual System entry point reaches embedded Migration tabs without another shell',async()=>{
 const {readFile}=await import('node:fs/promises');
 const read=relative=>readFile(new URL(relative,import.meta.url),'utf8');
 const [system,facade,workspace,legacy]=await Promise.all([read('../../system/src/main.js'),read('../src/MigrationConsole.vue'),read('../src/MigrationWorkspace.vue'),read('../src/MigrationConsoleLegacy.vue')]);
 assert.match(system,/import MigrationConsole from '\.\.\/\.\.\/migration\/src\/MigrationConsole\.vue'/);
 assert.match(system,/h\(MigrationConsole,\{auth,embedded:true\}\)/);
 assert.match(facade,/<div v-if="embedded"/);
 assert.match(facade,/<UiTabs/);
 assert.match(facade,/<MigrationControl v-else/);
 assert.doesNotMatch(facade,/<UiAppShell/);
 assert.match(workspace,/from '\.\/MigrationConsoleLegacy\.vue'/);
 assert.match(legacy,/Пульт переноса/);
});
