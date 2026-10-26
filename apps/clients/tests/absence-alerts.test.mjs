import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadClientAbsenceAlerts, resetClientAbsenceAlertsForTest } from '../src/absenceAlertCache.js';

test('one absence alert request is shared by parallel calls and tabs until TTL expires',async()=>{
 resetClientAbsenceAlertsForTest();
 let calls=0;
 const fake=async()=>{calls++;return{ok:true,json:async()=>({data:{11:'red',12:'yellow'}})};};
 const [first,second]=await Promise.all([loadClientAbsenceAlerts(fake,10000),loadClientAbsenceAlerts(fake,10000)]);
 assert.deepEqual(first,{11:'red',12:'yellow'});
 assert.deepEqual(second,first);
 await loadClientAbsenceAlerts(fake,50000);
 assert.equal(calls,1,'No request per attempt or re-render');
 await loadClientAbsenceAlerts(fake,200000);
 assert.equal(calls,2,'Refresh only after TTL');
 resetClientAbsenceAlertsForTest();
});
test('a failed Vacations request does not leave stale warnings cached',async()=>{
 resetClientAbsenceAlertsForTest();
 const failed=await loadClientAbsenceAlerts(async()=>({ok:false}),10000);
 assert.deepEqual(failed,{});
 const good=await loadClientAbsenceAlerts(async()=>({ok:true,json:async()=>({data:{11:'red'}})}),10001);
 assert.deepEqual(good,{11:'red'});
 resetClientAbsenceAlertsForTest();
});
test('warnings are displayed in attempt tree, selected attempt, and create form but not for partners',()=>{
 const view=readFileSync(new URL('../src/components/RequestsWorkflowView.vue',import.meta.url),'utf8');
 const badge=readFileSync(new URL('../src/components/AttemptAbsenceAlert.vue',import.meta.url),'utf8');
 assert.match(view,/const alertFor = attempt => attempt && !Boolean\(Number\(attempt\.is_external\)\)/);
 assert.ok((view.match(/<AttemptAbsenceAlert/g)||[]).length>=5);
 assert.match(view,/levelForId\(form\.specialist_id\)/);
 assert.match(badge,/attempt-absence-alert--red/);
 assert.match(badge,/attempt-absence-alert--yellow/);
});
test('pagination hides idle scrolling message without disabling sentinel',()=>{
 const app=readFileSync(new URL('../src/App.vue',import.meta.url),'utf8');
 assert.doesNotMatch(app,/Прокрутите для загрузки следующих записей/);
 assert.match(app,/clients-load-more--sentinel/);
 assert.match(app,/IntersectionObserver/);
 assert.match(app,/loadingMore \|\| moreError/);
});
