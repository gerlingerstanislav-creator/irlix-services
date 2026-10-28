import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { canVacations, canEnterVacations, visibleVacationsSections } from '../src/authorization.js';

const grant = (scope='own') => ({ allowed:true, scope });
const elevated = {admin:false,elevated:true};

test('local Vacations does not inherit Employees role or legacy admission', () => {
  assert.equal(canEnterVacations('local', {}), false);
  assert.equal(canEnterVacations('local', {'service:vacations':true}), false);
  assert.equal(canEnterVacations('unknown', {'vacations.mine.view': grant()}), false);
  assert.equal(canEnterVacations('legacy', {}), true);
  assert.equal(canVacations('local', {}, 'vacations.mine.view', true), false);
  assert.equal(canVacations('local', {'vacations.mine.view':grant()},'vacations.mine.view'),true);
});

test('independent read grants determine Vacations navigation and history', () => {
  const own = visibleVacationsSections('local', {'vacations.mine.view':grant()}, elevated);
  assert.deepEqual(own, {mine:true,department:false,manage:false,history:false,permissions:false});
  const manager = visibleVacationsSections('local', {
    'vacations.department.view':grant('team'),
    'vacations.manage.view':grant('team'),
    'vacations.history.view':grant('own'),
  }, {admin:false,elevated:false});
  assert.deepEqual(manager,{mine:false,department:true,manage:true,history:true,permissions:false});
  const legacy = visibleVacationsSections('legacy',{},elevated);
  assert.equal(legacy.mine,true);
  assert.equal(legacy.manage,true);
  assert.equal(legacy.department,true);
  assert.equal(legacy.history,true);
});

test('browser starts through Vacations effective grants, not Employees entitlement', () => {
  const main=readFileSync(new URL('../src/main.js',import.meta.url),'utf8');
  const app=readFileSync(new URL('../src/App.vue',import.meta.url),'utf8');
  assert.match(main, /api\/vacations\/permissions\/me/);
  assert.doesNotMatch(main,/api\/employees\/access\/me/);
  assert.match(app,/visibleVacationsSections/);
  assert.match(app,/can\('vacations\.department\.manage'/);
  assert.match(app,/availableSections=computed/);
});
