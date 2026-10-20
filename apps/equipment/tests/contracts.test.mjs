import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../../..');
const read = file => readFileSync(resolve(root,file),'utf8');
const app = read('apps/equipment/src/App.vue');
const css = read('apps/equipment/src/style.css');
const drawer = read('apps/equipment/src/EquipmentCardDrawer.vue');
const routes = read('services/equipment/routes/api.php');
const icons = read('packages/ui/src/components/UiIcon.vue');

test('all Equipment lists use the shared semantic registry component', () => {
  assert.match(app, /UiPanel v-if="section==='registry'/);
  assert.equal((app.match(/<UiRegistryTable /g)||[]).length,3);
  assert.equal((app.match(/<\/UiRegistryTable>/g)||[]).length,3);
  assert.doesNotMatch(app, /equipment-table-wrap/);
  for (const label of ['Техника','Выдачи','Оценка стоимости']) {
    assert.ok(app.includes('aria-label="'+label+'"'));
  }
  assert.match(css, /\.equipment-registry-panel \.equipment-shared-registry/);
});
test('all Equipment rail icons are actually supported by the shared icon library', () => {
  const match = [...app.matchAll(/\{ id:'(?:search|issue|type|manufacturer|purpose|writeoff)'[^\n]*icon:'([^']+)'/g)];
  assert.equal(match.length,6);
  assert.equal(new Set(match.map(x=>x[1])).size,6,'filter icons must be distinct');
  for (const [,icon] of match) assert.ok(icons.includes("name === '"+icon+"'"),'missing UiIcon: '+icon);
});
test('flat panel overrides scoped UiPanel; modal captions use the design tokens', () => {
  assert.match(css, /\.equipment-page \.ui-panel\.equipment-registry-panel\s*\{[^}]*border-radius: 0/);
  assert.match(css, /\.equipment-modal label,\.equipment-history-editor label,\.damage-editor label\{[^}]*color:var\(--irlix-color-field-label\)/);
  assert.doesNotMatch(css, /\.equipment-modal label,\.equipment-history-editor label,\.damage-editor label\{[^}]*color:var\(--irlix-status-info-text\)/);
});
test('equipment removal is atomic and erases all owned child records', () => {
  const start = routes.indexOf("Route::delete('/items/{id}'");
  const stop = routes.indexOf("Route::get('/items/{id}'",start);
  assert.ok(start>=0 && stop>start);
  const block = routes.slice(start,stop);
  assert.match(block,/equipmentCanOperate/);
  assert.match(block,/DB::transaction/);
  assert.match(block,/lockForUpdate/);
  for(const relation of ['equipment_assignments','equipment_damages','equipment_write_offs','equipment_audit_log']){
    assert.ok(block.includes(relation),relation+' must be purged before deleting the asset');
  }
  assert.doesNotMatch(block,/history.*409|never-used/i);
  assert.match(app,/Безвозвратно удалить технику/);
  assert.match(drawer,/@click="emit\('delete', item\)"/);
});
test('registry holder search and issue list preserve user-visible contract', () => {
  assert.match(app,/employeeFullName\(item\.assignment\.employee_id\)/);
  assert.match(app, /record\.returned_on \|\| '—'/);
  assert.doesNotMatch(app, /<th>Комментарий после возврата<\/th>/);
  assert.match(app, /class="equipment-issued-column"/);
});

test('Equipment loading indicators overlay existing content without moving the shared registry', () => {
  assert.match(app, /UiPanel, UiRegistryTable, UiLoader, UiSearchSelect/);
  assert.doesNotMatch(app, /class="equipment-empty">Загрузка…/);
  assert.match(app, /v-if="!loading && !filtered\.length"/);
  assert.equal((app.match(/<UiLoader v-if="loading" overlay/g)||[]).length,4);
  assert.match(css, /\.equipment-page \.ui-panel\.equipment-registry-panel\s*\{[^}]*position: relative/);
  assert.match(css, /\.equipment-settings-panel\{position:relative/);
});

test('Equipment registry row text follows Employees hierarchy and semantic badges', () => {
  const employeesApp = read('apps/web/src/App.vue');
  assert.match(employeesApp, /<UiBadge :tone="employee\.employment_status/);
  // An informational text token is blue. Employee typography is the reference,
  // not the legacy blue text color used by some of its table rules.
  assert.match(css, /\.equipment-shared-registry \.ui-registry-table__table--body td:not\(\.equipment-empty\)\s*\{[^}]*color: var\(--irlix-color-text\);/);
  assert.doesNotMatch(css, /color:\s*var\(--irlix-status-info-text\)/, 'Equipment must never color ordinary rows blue');
  assert.match(css, /\.equipment-shared-registry \.ui-registry-table__table--body \.equipment-inventory-link\s*\{[^}]*color: var\(--irlix-color-text\);/);
  assert.match(app, /<UiBadge :tone="item\.condition === 'ok'/);
  assert.match(app, /<strong>\{\{ record\.inventory_number \}\}<\/strong>/);
  assert.doesNotMatch(app, /class="equipment-condition"/);
});

test('issue and return controls appear inline within the issued cell, never in an extra column', () => {
  assert.match(app, /:columns="\['12%','10%','270px','14%','10%','10%','auto'\]"/);
  assert.match(app, /<th class="equipment-issued-column">Выдана<\/th><\/tr>/);
  assert.match(app, /<td class="equipment-issued-column"><span>\{\{ item\.assignment \? shortEmployeeName\(item\.assignment\.employee_id\) : '—' \}\}<\/span> <UiButton/);
  assert.match(app, /v-if="permissions\.operate && !item\.written_off_at && item\.assignment"[^>]*@click="openReturn\(item\)">\(вернуть\)<\/UiButton>/);
  assert.match(app, /v-else-if="permissions\.operate && !item\.written_off_at"[^>]*@click="openAssign\(item\)">\(выдать\)<\/UiButton>/);
  assert.match(app, /<td colspan="7" class="equipment-empty">Нет данных<\/td>/);
  assert.doesNotMatch(app, /class="equipment-row-action"/);
});
