import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = p => readFileSync(new URL('../src/' + p, import.meta.url), 'utf8');
test('one persistent department tree selection drives both dashboard and specialists list', () => {
  const app = read('App.vue');
  assert.match(app, /selectedDepartments/);
  assert.match(app, /irlix\.specialists\.departments\.v2/);
  assert.match(app, /employeesWithinDepartments/);
  assert.match(app, /UiDepartmentTreeSelect/);
  assert.doesNotMatch(app, /v-model="filters\.department"/);
  assert.doesNotMatch(app, /Выбранные направления/);
});
test('specialist drawer uses common tabs, history feeds and graded primary technologies', () => {
  const app = read('App.vue');
  const body = read('SpecialistProfileBody.vue');
  assert.match(app, /SpecialistProfileBody/);
  assert.match(app, /<UiDrawer :open="route.section==='person' && canViewProfiles"/);
  assert.match(body, /История/);
  assert.match(body, /О специалисте/);
  assert.match(body, /UiHistoryTimeline/);
  assert.match(body, /\/api\/employees\/specialists-history/);
  assert.match(body, /\/api\/clients\/specialists\/people/);
  assert.match(body, /UiTechnologyGrade/);
  assert.match(body, /startPrimary\(-1\)/);
  assert.match(body, /creatable/);
  assert.doesNotMatch(body, /Комментарий руководителя<\/label>/);
  assert.doesNotMatch(body, /<h3>Компетенции<\/h3>/);
});
test('technology directory is a separate alphabetical route and list', () => {
  assert.match(read('routes.js'), /path:'\/technologies'/);
  assert.match(read('accessNavigation.js'), /id: 'technologies'/);
  assert.match(read('App.vue'), /sortedTechnologies/);
});
