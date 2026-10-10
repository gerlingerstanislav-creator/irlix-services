import test from 'node:test';
import assert from 'node:assert/strict';
import { canViewResources, dashboardSection } from '../src/resourceAccess.js';
import { resourceAllocation, resourceColor } from '../src/resourceAllocation.js';
import { resourceRemainder } from '../src/resourceRemainder.js';
import { contours, domainContours, nodes, links, serviceDescriptions, canViewServiceMap } from '../src/serviceMapData.js';

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

test('map access is restricted to effective admin and tester roles',()=>{
  for(const access of [null,{}, {roles:['employee']},{roles:['system-admin']},{roles:'platform-admin'}]) {
    assert.equal(canViewServiceMap(access),Array.isArray(access?.roles) && access.roles.includes('platform-admin'));
  }
  assert.equal(canViewServiceMap({roles:['platform-tester']}),true);
  assert.equal(dashboardSection('/service-map/'),'service-map');
  assert.equal(dashboardSection('/service-map'),'service-map');
});
test('service map contains unique nodes with resolved, non-recursive links',()=>{
  const ids=contours.flatMap(c=>c.items);
  assert.equal(new Set(ids).size,ids.length);
  for(const id of ids) assert.ok(nodes[id]);
  for(const [source,target] of links){
    assert.ok(ids.includes(source),source);
    assert.ok(ids.includes(target),target);
    assert.notEqual(source,target);
  }
});
test('resource remainder does not double count cache or exclusive images',()=>{
  const snapshot={host:{memory_used:600,memory_cache:300,disk_total:1000,disk_available:300},
    services:[{working_bytes:220,disk_bytes:140},{working_bytes:80,disk_bytes:100}],
    disk:{images_exclusive_bytes:45}};
  const r=resourceRemainder(snapshot);
  assert.equal(r.ram.other,300);
  assert.equal(r.ram.cache,300);
  assert.equal(r.disk.other,460);
  assert.equal(r.disk.imageExclusive,45);
  assert.equal(resourceRemainder({host:{memory_used:null},services:[]}).ram.other,null);
});

test('each frontend/backend node belongs to exactly one horizontal domain',()=>{
  const front=contours.find(c=>c.id==='ui').items;
  const backend=['platform-core',...contours.find(c=>c.id==='business').items];
  const grouped=domainContours.flatMap(c=>c.items);
  assert.deepEqual(grouped.filter(id=>front.includes(id)),front);
  assert.deepEqual(grouped.filter(id=>backend.includes(id)),backend);
  assert.equal(new Set(grouped).size,grouped.length);
  assert.ok(domainContours.every(c=>c.label && c.items.some(id=>front.includes(id)) && c.items.some(id=>backend.includes(id))));
  assert.ok(!grouped.includes('postgres')&&!grouped.includes('keycloak'));
});
test('required frontend, PostgreSQL and integration edges do not disappear',()=>{
  const graph=new Set(links.map(([a,b])=>a+'>'+b));
  for(const pair of [
    ['employees','employees-api'],['vacations','vacations-api'],
    ['clients','clients-api'],['timesheets','timesheets-api'],
    ['specialists','specialists-api'],['equipment','equipment-api'],
    ['recruitment','recruitment-api'],['cv-converter','cv-api'],['migration','migration-api'],
    ['employees-api','postgres'],['vacations-api','postgres'],['clients-api','postgres'],
    ['timesheets-api','postgres'],['specialists-api','postgres'],['equipment-api','postgres'],
    ['platform-core','postgres'],['migration-api','postgres'],
    ['timesheets-api','vacations-api'],['timesheets-api','employees-api'],['timesheets-api','clients-api']
  ]) assert.ok(graph.has(pair.join('>')),pair.join(' → '));
});

test('service map inspector has descriptions and safe links for every visible node',()=>{
 const ids=contours.flatMap(c=>c.items);
 for(const id of ids){
  assert.equal(typeof serviceDescriptions[id],'string',id);
  assert.ok(serviceDescriptions[id].length>12,id);
  assert.ok(!nodes[id][1] || nodes[id][1].startsWith('/'),id);
 }
});

test('map domain ownership reflects platform, employees and HR',()=>{
 const domain = id=>domainContours.find(c=>c.id===id);
 assert.equal(domain('platform').label,'Платформа');
 for(const id of ['dashboard','migration','design-system','platform-core','migration-api']) assert.ok(domain('platform').items.includes(id));
 assert.equal(domain('people').label,'Сотрудники');
 assert.equal(domain('recruitment').label,'HR');
 assert.equal(domainContours.length,5);
});
