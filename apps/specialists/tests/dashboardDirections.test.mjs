import test from 'node:test';
import assert from 'node:assert/strict';
import { scopedDashboardPeople, validDashboardDepartmentSelection } from '../src/dashboardDirections.js';

const departments=[{id:1,parent_id:null},{id:2,parent_id:1},{id:3,parent_id:2},{id:4,parent_id:null}];
const people=[{id:10,department_id:1},{id:20,department_id:2},{id:30,department_id:3},{id:40,department_id:4}];

test('selected parent direction includes descendants without sibling',()=>{
  assert.deepEqual(scopedDashboardPeople(people,departments,['1']).map(row=>row.id),[10,20,30]);
  assert.deepEqual(scopedDashboardPeople(people,departments,['2','4']).map(row=>row.id),[20,30,40]);
});
test('stale local selection cannot affect scope and empty selection shows all',()=>{
  assert.deepEqual(validDashboardDepartmentSelection(departments,['1','999','1']),['1']);
  assert.equal(scopedDashboardPeople(people,departments,[]).length,4);
  assert.equal(scopedDashboardPeople(people,departments,['999']).length,4);
});
