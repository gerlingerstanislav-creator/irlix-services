import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { serviceGroups } from '../../../packages/ui/src/serviceCatalog.js';
import { canViewResources, dashboardSection } from '../src/resourceAccess.js';
import { resourceAllocation, resourceColor } from '../src/resourceAllocation.js';
import { resourceRemainder } from '../src/resourceRemainder.js';
import { diskServiceBytes, diskServicePartial, diskTasks, diskTaskLabels } from '../src/diskInventory.js';
import { contours, domainContours, nodes, links, serviceDescriptions, canViewServiceMap } from '../src/serviceMapData.js';

test('resource monitor requires platform-admin role',()=>{
  for (const access of [null,{}, {roles:['employee']},{roles:['platform-tester']},{roles:'platform-admin'}]) assert.equal(canViewResources(access),false);
  assert.equal(canViewResources({roles:['platform-admin']}),true);
  assert.equal(canViewResources({roles:['system-admin']}),false);
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

test('map access requires platform-admin exclusively',()=>{
  for(const access of [null,{}, {roles:['employee']},{roles:['system-admin']},{roles:'platform-admin'}]) {
    assert.equal(canViewServiceMap(access),Array.isArray(access?.roles) && access.roles.includes('platform-admin'));
  }
  assert.equal(canViewServiceMap({roles:['platform-tester']}),false);
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
test('map follows the shared catalog and deployed frontend registry, not a stale copy',()=>{
  const front = contours.find(group=>group.id==='ui').items;
  const expected = serviceGroups.flatMap(group=>group.items)
    .filter(service=>service.available && service.showInLauncher !== false && service.href);
  assert.deepEqual([...front].sort(),expected.map(service=>service.key).sort());
  for(const service of expected) {
    assert.deepEqual(nodes[service.key],[service.label,service.href],service.key);
  }
  assert.ok(front.includes('system'));
  assert.ok(!front.includes('migration'),'embedded Migration is not an independent routed frontend');
  assert.ok(!front.includes('assessments'),'unavailable/planned service is not a deployed frontend');

  const registry=JSON.parse(readFileSync(new URL('../../../infra/ci/services.json',import.meta.url),'utf8'));
  const deployedPages=registry.components
    .filter(component=>component.kind==='frontend' && component.id!=='migration-web')
    .map(component=>component.page_path).sort();
  assert.deepEqual([...new Set(expected.map(service=>service.href))].sort(),deployedPages);
  // Adding a new backend to the CI registry must also classify it on the architecture map.
  const backendNodeAlias = {
    'platform-core':'platform-core','migration':'migration-api',
    'migration-ops':'migration-ops','cv-converter':'cv-api','resource-monitor':'resource-monitor',
  };
  // Employees role reconciliation is a background worker sharing the Employees
  // backend image, not a separate backend image/component in the CI registry.
  const authWorker = registry.components.find(component=>component.id==='employees')
    ?.aliases?.includes('employees-authorization-sync') ? ['employees-auth-sync'] : [];
  const expectedBackends=[
    ...registry.components.filter(component=>component.kind==='backend')
      .map(component=>backendNodeAlias[component.id] || component.id+'-api'),
    ...authWorker,
  ].sort();
  const mappedBackends=[...contours.find(group=>group.id==='business').items,
    'platform-core','resource-monitor'].sort();
  assert.deepEqual(mappedBackends,expectedBackends);
  const graph=new Set(links.map(([from,to])=>from+'>'+to));
  for(const pair of [
    'system>platform-core','system>migration-api','system>employees-api',
    'dashboard>employees-api','dashboard>vacations-api','dashboard>timesheets-api',
    'migration-api>migration-ops','platform-core>resource-monitor','resource-monitor>volumes',
  ]) assert.ok(graph.has(pair),'missing dependency '+pair);
  assert.ok(!graph.has('migration>migration-api'));
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
  assert.deepEqual(grouped.filter(id=>front.includes(id)).sort(),[...front].sort());
  assert.deepEqual(grouped.filter(id=>backend.includes(id)).sort(),[...backend].sort());
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
    ['recruitment','recruitment-api'],['cv-converter','cv-api'],['system','migration-api'],
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
 for(const id of ['system','design-system','platform-core','migration-api','migration-ops']) assert.ok(domain('platform').items.includes(id));
 assert.equal(domain('people').label,'Сотрудники');
 assert.ok(domain('people').items.includes('dashboard'),'Dashboard must be in Employees contour');
 assert.ok(!domain('platform').items.includes('dashboard'),'Dashboard cannot be duplicated in Platform contour');
 assert.ok(domain('people').items.includes('employees'));
 assert.ok(domain('people').items.includes('vacations'));
 const launcherEmployees = serviceGroups.find(group=>group.key==='employees');
 assert.ok(launcherEmployees.items.some(item=>item.key==='dashboard'),'map must agree with shared service catalog');
 assert.equal(domain('recruitment').label,'HR');
 assert.equal(domainContours.length,6);
  assert.equal(domain('finance').label,'Финансы');
  assert.ok(domain('finance').items.includes('finance') && domain('finance').items.includes('finance-api'));
});

test('disk task inventory only displays fixed safe categories and stays non-additive',()=>{
  const snapshot={host:{disk_total:1000,disk_available:200},
    disk:{host_inventory:{sections:[
      {id:'migration-timesheets',service:'migration',bytes:300,partial:false},
      {id:'docker-buildkit',service:null,bytes:100,partial:true},
      {id:'unknown',service:null,bytes:500,partial:false}
    ]}},
    services:[{id:'migration',disk_bytes:60,disk_host_bytes:300,
      disk_attributed_bytes:360,disk_attributed_partial:false}]};
  assert.equal(diskTasks(snapshot).length,2);
  assert.equal(diskTasks(snapshot)[0].label,diskTaskLabels['migration-timesheets']);
  assert.equal(diskServiceBytes(snapshot.services[0]),360);
  assert.equal(diskServicePartial(snapshot.services[0]),false);
  assert.equal(resourceRemainder(snapshot).disk.assigned,360);
  assert.equal(resourceRemainder(snapshot).disk.other,440);
  assert.equal(diskServiceBytes({disk_bytes:5}),5);
  assert.deepEqual(diskTasks({}),[]);
});
