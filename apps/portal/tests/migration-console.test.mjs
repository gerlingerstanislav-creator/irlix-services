import test from 'node:test';
import assert from 'node:assert/strict';
import { orderedModules, percent, ready, shownRuns, displayRun, messages } from '../src/migration-console-model.js';
test('dependency order is stable and cycles cannot produce a runnable queue',()=>{
  assert.deepEqual(orderedModules([{key:'vacations',dependencies:['employees']},{key:'employees',dependencies:[]}]).map(m=>m.key),['employees','vacations']);
  assert.throws(()=>orderedModules([{key:'a',dependencies:['b']},{key:'b',dependencies:['a']}]),/Циклическая/);
});
test('saved password and live verification are both required',()=>{
  assert.equal(ready({status:'implemented',connection:{verified_at:'now',credential_status:'ready'}}),true);
  assert.equal(ready({status:'planned',connection:{verified_at:'now',credential_status:'ready'}}),false);
  assert.equal(ready({status:'implemented',connection:{verified_at:'now',credential_status:'unreadable'}}),false);
});
test('unknown volume never invents a percent and warnings do not inflate it',()=>{
  assert.equal(percent({total:null,success_count:50}),null);
  assert.equal(percent({total:100,processed_count:61,success_count:60,error_count:2,warning_count:80}),61);
});
test('batch shows only its own runs and retains import results after validation',()=>{
  const module={key:'employees',latest_run:{id:900}};
  const op={runs:[{id:1,service:'employees',mode:'inspect'},{id:2,service:'employees',mode:'migrate'},{id:3,service:'employees',mode:'validate'},{id:4,service:'vacations',mode:'migrate'}]};
  const runs=shownRuns(module,op,{2:{id:2,mode:'migrate',success_count:40}});
  assert.equal(runs.length,3);assert.equal(displayRun(runs).success_count,40);
  assert.equal(shownRuns(module,{runs:[]},{}).length,0);
});
test('diagnostics identify actual table, severity and run',()=>{
  const result=messages({key:'employees'},[{id:3,mode:'migrate',conflicts:[{id:1,entity_type:'employee_role',severity:'warning',message:'synthetic warning'}]}]);
  assert.equal(result[0].table,'employee_roles');assert.equal(result[0].kind,'warning');assert.equal(result[0].run_id,3);
});
