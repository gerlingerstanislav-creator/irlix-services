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

test('real table markup retains shared native column widths and sticky cells', () => {
  assert.doesNotMatch(app, /equipment-table--split-scroll|updateTableGutters/);
  assert.doesNotMatch(css, /grid-template-columns:\s*var\(--equipment-table-columns\)|equipment-table--split-scroll/);
  assert.match(css, /\.equipment-registry-panel \.equipment-table tbody td\s*\{\s*vertical-align: middle/);
  assert.match(css, /\.equipment-registry-panel \.equipment-table thead th\s*\{[^}]*position: sticky/);
  assert.match(css, /\.equipment-registry-panel \.equipment-table-wrap\s*\{[^}]*overflow: auto/);
  assert.match(css, /td\.equipment-model-column:not\(\.equipment-empty\)\s*\{[^}]*white-space: normal/);
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
