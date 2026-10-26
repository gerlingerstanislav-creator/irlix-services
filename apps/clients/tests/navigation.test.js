import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { allowedClientSections, sectionPermissions } from '../src/navigation.js';
const permissions = Object.fromEntries(Object.values(sectionPermissions).map(key=>[key,{allowed:true,scope:'all'}]));
test('Clients staff see pages granted by the permission matrix',()=>{
 for(const role of ['account-manager','sales-manager','accounting-head','sales-head','client-service-head']) {
  const pages=allowedClientSections({roles:[role],permissions});
  assert.ok(pages.includes('positions'));assert.ok(pages.includes('requests'));
  assert.ok(pages.includes('members'));assert.ok(!pages.includes('tenders'));
 }
});
test('production heads can access every page explicitly granted by the matrix',()=>{
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions}),Object.keys(sectionPermissions));
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{'clients.view':{allowed:true,scope:'team'}}}),['clients']);
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{'clients.view':{allowed:true,scope:'team'},'members.view':{allowed:true,scope:'team'}}}),['clients','members']);
});
test('members page requires members.view independently of client management',()=>{
 const access={roles:['account-manager'],permissions:{'clients.view':{allowed:true,scope:'all'},'clients.manage':{allowed:true,scope:'all'}}};
 assert.ok(!allowedClientSections(access).includes('members'));
 assert.ok(allowedClientSections({...access,permissions:{...access.permissions,'members.view':{allowed:true,scope:'own'}}}).includes('members'));
});
test('page policy never grants missing permissions, preserves funnel-only access and admin exception',()=>{
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{}}),[]);
 assert.deepEqual(allowedClientSections({roles:['department-manager'],permissions:{'attempts.analytics.view':{allowed:true}}}),['attempts']);
 assert.deepEqual(allowedClientSections({platform_admin:true,roles:['department-manager'],permissions}),Object.keys(sectionPermissions));
 assert.deepEqual(allowedClientSections({roles:['platform-tester'],permissions:{'clients.view':{allowed:true,scope:'all'}}}),['clients']);
});

test('Clients routes use documented URLs and preserve legacy query and hash', async () => {
 const { routes } = await import('../src/routes.js');
 const named = Object.fromEntries(routes.filter(r=>r.name).map(r=>[r.name,r]));
 assert.equal(named.clients.path, '/clients/');
 assert.equal(named.reports.path, '/reporting-periods/');
 assert.equal(named['attempt-funnel'].path, '/attempt-funnel/');
 assert.equal(named['attempt-funnel'].meta.section, 'attempts');
 const legacy = Object.fromEntries(routes.filter(r=>r.redirect).map(r=>[r.path,r]));
 for (const [path,name] of [['/','clients'],['/reports/','reports']]) {
   const redirect=legacy[path].redirect;
   assert.deepEqual(redirect({query:{month:'2026-10',client_id:'4'},hash:'#detail'}),{
     name,query:{month:'2026-10',client_id:'4'},hash:'#detail'
   });
 }
 assert.equal(named['not-found'].meta.section,'not-found');
});
test('Clients funnel direct links respect the analytics grant and browser route state',()=>{
 const app=readFileSync(new URL('../src/App.vue', import.meta.url),'utf8');
 assert.match(app,/watch\(\(\) => route\.name,[\s\S]*?immediate:\s*true/);
 assert.match(app,/route\.name === 'attempt-funnel' && !can\('attempts\.analytics\.view'\)/);
 assert.match(app,/@update:attempt-mode="changeAttemptMode"/);
 assert.match(app,/mode === 'funnel' \? 'attempt-funnel' : 'attempts'/);
});
