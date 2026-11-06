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

test('professional card autosaves all technology edits without overall grade or manual submit', () => {
  const body = read('SpecialistProfileBody.vue');
  const app = read('App.vue');
  const css = read('style.css');
  assert.match(body, /createProfessionalAutosave/);
  assert.match(body, /autosave\.queue/);
  assert.match(body, /queueProfessionalSave\(\)/);
  assert.match(body, /onBeforeUnmount\(\(\) => \{ void autosave\.close\(\)/);
  assert.match(body, /Повторить сохранение/);
  assert.match(body, /teleport compact/);
  assert.doesNotMatch(body, /Общий грейд/);
  assert.doesNotMatch(body, /generalGrade/);
  assert.doesNotMatch(body, /Сохранить изменения/);
  assert.doesNotMatch(body, /'grade':/);
  assert.doesNotMatch(css, /\.specialists-profile-section\{[^}]*border-bottom:1px/);
  assert.doesNotMatch(css, /\.specialists-profile-identity\{[^}]*border-bottom:1px/);
  assert.match(app, /Autosave owns the live editor state/);
});
