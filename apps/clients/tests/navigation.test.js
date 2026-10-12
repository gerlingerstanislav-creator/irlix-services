import { test } from 'node:test';
import assert from 'node:assert/strict';
import { allowedClientSections, sectionPermissions } from '../src/navigation.js';
const permissions = Object.fromEntries(Object.values(sectionPermissions).map(key=>[key,{allowed:true,scope:'all'}]));
test('Clients staff see pages granted by the permission matrix',()=>{
 for(const role of ['account-manager','sales-manager','accounting-head','sales-head','client-service-head']) {
  const pages=allowedClientSections({roles:[role],permissions});
  assert.ok(pages.includes('positions'));assert.ok(pages.includes('requests'));
  assert.ok(!pages.includes('members'));assert.ok(!pages.includes('tenders'));
 }
});
test('production heads can access every page explicitly granted by the matrix',()=>{
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions}),Object.keys(sectionPermissions));
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{'clients.view':{allowed:true,scope:'team'}}}),['clients']);
});
test('page policy never grants missing permissions, preserves funnel-only access and admin exception',()=>{
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{}}),[]);
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{'attempts.analytics.view':{allowed:true}}}),['attempts']);
 assert.deepEqual(allowedClientSections({platform_admin:true,roles:['department-manager'],permissions}),Object.keys(sectionPermissions));
 assert.deepEqual(allowedClientSections({roles:['platform-tester'],permissions:{'clients.view':{allowed:true,scope:'all'}}}),['clients']);
});
