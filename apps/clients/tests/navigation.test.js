import { test } from 'node:test';
import assert from 'node:assert/strict';
import { allowedClientSections, sectionPermissions } from '../src/navigation.js';
const permissions = Object.fromEntries(Object.values(sectionPermissions).map(key=>[key,{allowed:true,scope:'all'}]));
test('Clients staff retain position entity permissions without a standalone page',()=>{
 for(const role of ['account-manager','sales-manager','accounting-head','sales-head','client-service-head']) {
  const pages=allowedClientSections({roles:[role],permissions});
  assert.ok(!pages.includes('positions'));assert.ok(pages.includes('requests'));
  assert.ok(!pages.includes('members'));assert.ok(!pages.includes('tenders'));
 }
});
test('production heads have exactly the two workflow pages even with extra matrix grants',()=>{
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions}),['positions','attempts']);
 assert.deepEqual(allowedClientSections({roles:['department-manager','account-manager'],permissions}),['positions','attempts']);
});
test('page policy never grants missing permissions, preserves funnel-only access and admin exception',()=>{
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{}}),[]);
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{'attempts.analytics.view':{allowed:true}}}),['attempts']);
 assert.deepEqual(allowedClientSections({platform_admin:true,roles:['department-manager'],permissions}),Object.keys(sectionPermissions));
 assert.deepEqual(allowedClientSections({clients_service_blocked:true,permissions}),[]);
});
