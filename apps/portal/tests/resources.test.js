import test from 'node:test';
import assert from 'node:assert/strict';
import { canViewResources, dashboardSection } from '../src/resourceAccess.js';
import { resourceAllocation, resourceColor } from '../src/resourceAllocation.js';

test('resource monitor allows platform-admin and system-admin only',()=>{
  for (const access of [null,{}, {roles:['employee']},{roles:['platform-tester']},{roles:'platform-admin'}]) assert.equal(canViewResources(access),false);
  assert.equal(canViewResources({roles:['platform-admin']}),true);
  assert.equal(canViewResources({roles:['system-admin']}),true);
});

test('allocations preserve capacity, raw values and unknown measurements',()=>{
  const result=resourceAllocation([{id:'employees',working_bytes:20},{id:'postgres',working_bytes:30}],'working_bytes',100,70);
  assert.equal(result.segments.reduce((n,s)=>n+s.size,0),100);
  assert.equal(result.segments.find(s=>s.id==='__other__').value,20);
  assert.equal(result.segments.find(s=>s.id==='__free__').value,30);
  assert.equal(result.normalized,false);
  const mismatch=resourceAllocation([{id:'postgres',working_bytes:90}],'working_bytes',100,70);
  assert.equal(mismatch.segments[0].value,90);
  assert.equal(mismatch.segments[0].size,70);
  assert.equal(mismatch.segments.reduce((n,s)=>n+s.size,0),100);
  assert.equal(mismatch.normalized,true);
  assert.equal(resourceAllocation([{id:'a',cpu_cores:null}],'cpu_cores',4,null).unavailable,true);
  const unknown=resourceAllocation([{id:'a',disk_bytes:null}],'disk_bytes',100,70);
  assert.equal(unknown.incomplete,true);
  assert.equal(unknown.segments.some(s=>s.id==='a'),false);
  assert.equal(resourceColor('postgres'),resourceColor('postgres'));
});
test('resource monitor has a stable direct URL',()=>{
  assert.equal(dashboardSection('/resources/'),'resources');
  assert.equal(dashboardSection('/resources'),'resources');
  assert.equal(dashboardSection('/'),'dashboard');
});
