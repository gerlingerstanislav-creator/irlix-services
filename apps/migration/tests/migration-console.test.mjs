import test from 'node:test';
import assert from 'node:assert/strict';
import { operationDiagnostics, restoreStage, databaseOutcome, restoreMaintenance, orderedModules, percent, ready, shownRuns, displayRun, messages, tableStatus, historyForScope } from '../src/migration-console-model.js';
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
  const op={id:'8008',runs:[{id:1,service:'employees',mode:'inspect'},{id:2,service:'employees',mode:'migrate'},{id:3,service:'employees',mode:'validate'},{id:4,service:'vacations',mode:'migrate'}]};
  const runs=shownRuns(module,op,{2:{reportOperationId:'8008',id:2,mode:'migrate',success_count:40}});
  assert.equal(runs.length,3);assert.equal(displayRun(runs).success_count,40);
  assert.equal(shownRuns(module,{runs:[]},{}).length,0);
});
test('diagnostics identify actual table, severity and run',()=>{
  const result=messages({key:'employees'},[{id:3,mode:'migrate',conflicts:[{id:1,entity_type:'employee_role',severity:'warning',message:'synthetic warning'}]}]);
  assert.equal(result[0].table,'employee_roles');assert.equal(result[0].kind,'warning');assert.equal(result[0].run_id,3);
});

test('restored run ID cannot substitute a report belonging to another operation',()=>{
  const result=shownRuns({key:'vacations'},{id:'8008',runs:[{id:7,service:'vacations',status:'failed'}]},{7:{id:7,reportOperationId:'8009',status:'completed'}});
  assert.equal(result[0].status,'failed');
  assert.equal(tableStatus({state:'checked',error_count:7}),'conflicts');
  assert.equal(tableStatus({state:'checked',warning_count:7}),'checked');
});

test('service history contains own and shared operations touching that service',()=>{
  const history=[{id:1,scope:'employees'},{id:2,scope:'vacations'},{id:3,scope:'all',services:['employees','vacations']},{id:4,scope:'all',runs:[{service:'clients'}]}];
  assert.deepEqual(historyForScope(history,'vacations').map(h=>h.id),[2,3]);
  assert.deepEqual(historyForScope(history,'clients').map(h=>h.id),[4]);
  assert.equal(historyForScope(history,'all').length,4);
});

test('restore status separates operational polling from replaced metadata',()=>{
  assert.equal(restoreMaintenance({}),false);
  assert.equal(restoreMaintenance({restore_status:{state:'running'}}),true);
  assert.equal(restoreMaintenance({restore_status:{state:'failed',database_outcome:'unknown'}}),true);
  assert.equal(restoreMaintenance({restore_status:{state:'failed',database_committed:true,metadata_complete:false}}),true);
  assert.equal(restoreMaintenance({restore_status:{state:'failed',database_outcome:'unmodified'}}),false);
  assert.equal(restoreMaintenance({restore_status:{state:'completed'}}),false);
});


test('restore report survives history selection and filters unrelated legacy errors',()=>{
  const operation={id:'42',scope:'employees',action:'restore',status:'failed',snapshot_id:'123',diagnostics:{diagnostic_id:'a'.repeat(32),stage:'preflight',error_code:'SCHEMA_DEPENDENCY',database_outcome:'unmodified'}};
  const legacy={id:'43',service:'vacations',action:'restore',state:'failed',diagnostics:{diagnostic_id:'b'.repeat(32)}};
  assert.deepEqual(operationDiagnostics(operation,legacy,'employees').map(r=>r.operation_id),['42']);
  assert.equal(operationDiagnostics(operation,legacy,'all').length,2);
  assert.equal(operationDiagnostics(operation,legacy,'all',true).length,1);
  assert.equal(operationDiagnostics({...operation,status:'completed'},null,'all').length,0);
  assert.equal(operationDiagnostics({id:'44',action:'migrate',status:'failed',scope:'employees'},null,'all').length,0);
  assert.equal(operationDiagnostics(null,legacy,'vacations')[0].scope,'vacations');
  assert.equal(restoreStage('preflight'),'Проверка совместимости');
  assert.equal(databaseOutcome('unmodified'),'БД не изменялась');
});
