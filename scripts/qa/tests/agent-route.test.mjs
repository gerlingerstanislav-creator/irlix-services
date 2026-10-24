import { test } from 'node:test';
import assert from 'node:assert/strict';
import { routeChanges,chooseSpecs,reviewDecision } from '../../agents/route.mjs';
import { renderHandoff } from '../../agents/handoff.mjs';

const behaviors=r=>r.behaviorHints.map(h=>h.behavior);

test('Full-stack service paths provide coexisting hints, not intent or role assignments',()=>{
 const paths=['apps/timesheets/src/App.vue','services/timesheets/app/Timesheet.php'];
 const r=routeChanges(paths);
 assert.deepEqual(r.services,['timesheets']);
 assert.deepEqual(r.developers,['Developer:timesheets']);
 assert.deepEqual(r.roles,[]);
 assert.deepEqual(behaviors(r),['frontend','backend','security']);
 assert.equal(r.advisory,true);
 assert.equal(r.source,'paths');
 assert.equal(r.scopeKnown,true);
 assert.equal(r.intent,'unknown');
 assert.equal(r.risk,'unknown');
 assert.ok(r.unknowns.includes('required verification'));
 assert.ok(r.assessmentRequired.some(x=>x.includes('uncertainty')));
 assert.deepEqual(r.behaviorHints[0].evidence,[paths[0]]);
 assert.deepEqual(r.behaviorHints[1].evidence,[paths[1]]);
});

test('Cross-service paths suggest investigating contracts without loading neighboring services',()=>{
 const r=routeChanges(['services/clients/app/a.php','services/timesheets/app/b.php']);
 assert.deepEqual(r.services,['clients','timesheets']);
 assert.ok(behaviors(r).includes('integration'));
 assert.ok(!behaviors(r).includes('frontend'));
 assert.deepEqual(r.roles,[]);
 assert.equal(r.intent,'unknown');
});

test('Shared UI, auth, CI and test paths expose distinct compatible behaviors',()=>{
 const ui=routeChanges(['packages/ui/src/index.js']);
 assert.deepEqual(behaviors(ui),['frontend','integration']);
 assert.deepEqual(ui.services,[]);
 assert.equal(ui.shared,true);
 const auth=routeChanges(['packages/auth/src/tokens.js']);
 assert.deepEqual(behaviors(auth),['security','integration']);
 const ci=routeChanges(['.github/workflows/ci.yml']);
 assert.deepEqual(behaviors(ci),['delivery']);
 const tests=routeChanges(['scripts/qa/tests/agent-route.test.mjs']);
 assert.deepEqual(behaviors(tests),['verification']);
});

test('Existing special service selectors include both migration backends and portal',()=>{
 for(const [service,paths] of [
  ['employees',['apps/web/src/main.js','services/employees/app/A.php']],
  ['migration',['apps/migration/src/main.js','services/migration/app/X.php']],
  ['migration',['services/migration-ops/app/X.php']],
  ['cv',['apps/cv/src/main.js','services/cv-converter/app/X.php']],
  ['portal',['apps/portal/src/main.js','services/platform-core/app/X.php']]
 ])assert.deepEqual(routeChanges(paths).services,[service]);
 assert.deepEqual(routeChanges(['apps/web-other/App.vue','services/migration-extra/app/X.php']).services,[]);
});

test('Unknown, empty and unmapped app paths do not invent a task or complete scope',()=>{
 for(const paths of [[],['README.md'],['apps/brandbook/src/App.vue'],['apps/system/src/App.vue']]){
  const r=routeChanges(paths);
  assert.deepEqual(r.services,[]);
  assert.deepEqual(r.roles,[]);
  assert.equal(r.scopeKnown,false);
  assert.equal(r.intent,'unknown');
  assert.equal(r.risk,'unknown');
 }
 assert.deepEqual(behaviors(routeChanges(['apps/brandbook/src/App.vue'])),['frontend']);
 assert.deepEqual(behaviors(routeChanges([])),[]);
 assert.throws(()=>routeChanges(null),TypeError);
 assert.throws(()=>routeChanges([null]),TypeError);
});

test('Data and security hints preserve scoped evidence and never determine risk severity',()=>{
 const migration='services/timesheets/database/migrations/create.php';
 const r=routeChanges([migration,migration]);
 assert.deepEqual(behaviors(r),['backend','data']);
 assert.deepEqual(r.behaviorHints.find(h=>h.behavior==='data').evidence,[migration]);
 assert.equal(r.database,true);
 assert.equal(r.security,false);
 assert.equal(r.risk,'unknown');
 assert.ok(behaviors(routeChanges(['services/migration-ops/README.md'])).includes('data'));
 assert.deepEqual(behaviors(routeChanges(['docs/AI_TEAM.md'])),['analysis']);
});

test('Idea selection stays limited to one service and prioritized specs',()=>{
 const paths=['company-internal-services-81-2.4/services/clients/UI.md','company-internal-services-81-2.4/services/clients/README.md','company-internal-services-81-2.4/services/clients/ACCEPTANCE.md','company-internal-services-81-2.4/services/employees/REQUIREMENTS.md'];
 assert.deepEqual(chooseSpecs('clients',paths,2),[paths[2],paths[1]]);
});

test('Only explicit no-findings review outcomes can aggregate to reviewed',()=>{
 assert.equal(reviewDecision([{status:'NO_FINDINGS'}]),'REVIEWED_NO_FINDINGS');
 assert.equal(reviewDecision([{status:'NO_FINDINGS'},{status:'REVIEWED_NO_FINDINGS'}]),'REVIEWED_NO_FINDINGS');
 assert.equal(reviewDecision([]),'NOT_TESTED');
 for(const result of [{status:'PASS'},{status:'UNKNOWN'},{status:''},{},null,undefined]){
  assert.equal(reviewDecision([result]),'NEEDS_INFO');
  assert.equal(reviewDecision([{status:'NO_FINDINGS'},result]),'NEEDS_INFO');
 }
 assert.equal(reviewDecision(Array(1)),'NEEDS_INFO');
 assert.throws(()=>reviewDecision(null),TypeError);
});

test('Failure, blockers, uncertainty and untested work cannot disappear behind no-findings',()=>{
 const noFindings={status:'NO_FINDINGS'};
 for(const [status,expected] of [
  ['CHANGES_REQUESTED','CHANGES_REQUESTED'],['FAIL','CHANGES_REQUESTED'],
  ['BLOCKED','BLOCKED'],['NEEDS_INFO','NEEDS_INFO'],
  ['NOT_TESTED','NOT_TESTED'],['NOT TESTED','NOT_TESTED']
 ]){
  assert.equal(reviewDecision([noFindings,{status}]),expected);
  assert.equal(reviewDecision([{status},noFindings]),expected);
 }
 assert.equal(reviewDecision([{status:'FAIL'},{status:'BLOCKED'},{status:'UNKNOWN'}]),'CHANGES_REQUESTED');
 assert.equal(reviewDecision([{status:'NOT_TESTED'},{status:'BLOCKED'}]),'BLOCKED');
});

test('Rendered handoff presents advisory behaviors, owner assessment and canonical links',()=>{
 const text=renderHandoff({prNumber:42,sha:'a'.repeat(40),repo:'example/services',routing:routeChanges(['apps/timesheets/src/App.vue'])});
 assert.match(text,/frontend/);
 assert.match(text,/не обязательные роли/);
 assert.match(text,/Владелец задачи/);
 assert.match(text,/намерение, неопределённость и риск/);
 assert.match(text,/НЕ AI-review и НЕ подтверждение тестирования/);
 assert.match(text,/https:\/\/github.com\/example\/services\/blob\/development\/AGENTS.md/);
 assert.match(text,/https:\/\/github.com\/example\/services\/blob\/development\/docs\/agent\/WORKFLOW.md/);
 assert.doesNotMatch(text,/Developer:|Analyst|Designer|\*\*PM:|\*\*Этапы/);
 assert.match(text,/Неизвестно по путям/);
 const unknown=renderHandoff({prNumber:42,sha:'a'.repeat(40),repo:'example/services',routing:routeChanges([])});
 assert.match(unknown,/Область по путям не распознана/);
 assert.match(unknown,/НЕ AI-review/);
});
