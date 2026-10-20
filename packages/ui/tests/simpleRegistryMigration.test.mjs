import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL('../../../' + path, import.meta.url), 'utf8');

// Six services, eighteen user-facing simple list pages. Vacations reuses one
// AbsenceTable component across three different screens.
const migrated = new Map([
  ['apps/web/src/App.vue',1],
  ['apps/web/src/components/AuditLogView.vue',1],
  ['apps/web/src/components/SpecialRolesView.vue',1],
  ['apps/clients/src/App.vue',2],
  ['apps/clients/src/components/RequestsWorkflowView.vue',2],
  ['apps/vacations/src/components/AbsenceTable.vue',1],
  ['apps/vacations/src/pages/ActionHistoryPage.vue',1],
  ['apps/timesheets/src/App.vue',2],
  ['apps/specialists/src/App.vue',1],
  ['apps/recruitment/src/AppMvp.vue',4],
]);

test('all sixteen flat registry implementations share the same split-header component', () => {
  let count=0;
  for (const [path, expected] of migrated) {
    const component=read(path);
    const usages=(component.match(/<UiRegistryTable\b/g)||[]).length;
    assert.equal(usages,expected,path+' registry count');
    assert.equal((component.match(/<\/UiRegistryTable>/g)||[]).length,expected,path+' closing tags');
    assert.equal((component.match(/<template #head>/g)||[]).length,expected,path+' heading slots');
    assert.match(component,/import \{[^}]*UiRegistryTable[^}]*\} from '@irlix\/ui';/,path+' import');
    assert.match(component,/density="compact"/,path+' compact layout');
    count+=usages;
  }
  assert.equal(count,16);
});

test('shared table reuses service table classes on both head and body and aligns dynamic tracks', () => {
  const shared=read('packages/ui/src/components/UiRegistryTable.vue');
  assert.match(shared,/tableClass: \{ type: String, default: '' \}/);
  assert.equal((shared.match(/:class="tableClass"/g)||[]).length,2);
  const absences=read('apps/vacations/src/components/AbsenceTable.vue');
  assert.match(absences,/:columns="registryColumns" :min-width="registryMinWidth"/);
  for(const state of ['showEmployee','showStage','showDocuments','showActions']) assert.match(absences,new RegExp('props\\.'+state));
  const timesheets=read('apps/timesheets/src/App.vue');
  assert.match(timesheets,/:columns="analyticsRegistryColumns" :min-width="analyticsRegistryMinWidth"/);
  assert.match(timesheets,/Object\.keys\(analytics\.value\?\.totals\?\.absences/);
  const css=read('apps/vacations/src/layout-fixes.css');
  assert.match(css,/min-width:var\(--irlix-registry-min-width\)!important/);
});

test('nonflat matrices and calendars retain their specialized renderers and business interactions', () => {
  const timesheets=read('apps/timesheets/src/App.vue');
  assert.match(timesheets,/<table class="matrix">/,'timesheet management grid stays untouched');
  const vacations=read('apps/vacations/src/pages/DepartmentAbsencesPage.vue');
  assert.match(vacations,/<table class="absence-calendar">/,'department calendar remains independent');
  assert.match(vacations,/<AbsenceTable v-else-if="view==='list'"/,'list uses migrated shared component');
  for (const page of ['MyAbsencesPage.vue','ManageAbsencesPage.vue']) {
    assert.match(read('apps/vacations/src/pages/'+page),/<AbsenceTable /);
  }
  assert.match(read('apps/recruitment/src/main.js'),/import App from '.\/AppMvp\.vue';/,'real recruitment app migrated');
  assert.match(read('apps/specialists/src/App.vue'),/@click="openPerson\(person\.id\)"/);
  assert.match(read('apps/clients/src/App.vue'),/@click="openContactCard\(c\)"/);
});

test('standalone recruitment and specialists registry panels own bounded inner scroll', () => {
  for (const service of ['recruitment','specialists']) {
    const style=read('apps/'+service+'/src/style.css');
    assert.match(style,/\.table-card--registry \{/);
    assert.match(style,/overflow:hidden;/);
    assert.match(style,/> \.ui-registry-table \{/);
  }
});
