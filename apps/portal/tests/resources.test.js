import test from 'node:test';
import assert from 'node:assert/strict';
import { canViewResources, dashboardSection } from '../src/resourceAccess.js';

test('resource monitor allows platform-admin and system-admin only',()=>{
  for (const access of [null,{}, {roles:['employee']},{roles:['platform-tester']},{roles:'platform-admin'}]) assert.equal(canViewResources(access),false);
  assert.equal(canViewResources({roles:['platform-admin']}),true);
  assert.equal(canViewResources({roles:['system-admin']}),true);
});
test('resource monitor has a stable direct URL',()=>{
  assert.equal(dashboardSection('/resources/'),'resources');
  assert.equal(dashboardSection('/resources'),'resources');
  assert.equal(dashboardSection('/'),'dashboard');
});
