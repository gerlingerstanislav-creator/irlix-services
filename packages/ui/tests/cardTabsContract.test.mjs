import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const root = new URL('../../../', import.meta.url);
const read = path => readFile(new URL(path,root),'utf8');
test('card tabs, document icon and approval check use shared UI',async()=>{
 const [tabs,styles,icon,period,client,design,check] = await Promise.all([
   read('packages/ui/src/components/UiTabs.vue'), read('packages/ui/src/styles/base.css'),
   read('packages/ui/src/components/UiIcon.vue'),read('apps/clients/src/components/ReportingPeriodsView.vue'),
   read('apps/clients/src/components/ClientCard.vue'),read('apps/design-system/src/App.vue'),read('packages/ui/src/components/UiStatusCheck.vue')
 ]);
 assert.match(tabs,/variant === 'card'/);
 assert.match(tabs,/item\.complete/);
 assert.match(tabs,/iconName/);
 assert.match(styles,/\.irlix-tabs--card/);
 assert.match(styles,/\.irlix-status-check/);
 assert.match(styles,/border-radius:0; background:transparent; color:var\(--irlix-color-confirm-final\)/);
 assert.match(icon,/name === 'file-text'/);
 assert.match(period,/<UiTabs[^>]*variant="card"/);
 assert.match(period,/iconName:'file-text'/);
 assert.match(period,/class="ts-employee-title"/);
 assert.match(period,/shortEmployeeName\(row.employee_name\)/);
 assert.match(period,/<UiStatusCheck v-if="row\.worked_hours > 0 && row\.account_confirmed"/);
 assert.match(period,/v-for="row in groupedTimesheets"/);
 assert.match(period,/money\(row\.worked_amount\)/);
 assert.match(period,/class="ts-approved-input"/);
 assert.match(period,/ts-save-spinner/);
 assert.match(period,/ts-period-column/);
 assert.match(period, /\/\^\\d\+\(\?:\\\.\\d\{1,2\}\)\?\$\/\.test\(raw\)/);
 assert.doesNotMatch(period, /\/\^\\\\d/);
 assert.match(period, /grid-template-columns:minmax\(135px,1\.25fr\) minmax\(55px,\.85fr\) max-content minmax\(165px,max-content\)/);
 assert.match(period,/@blur="saveApprovedEdit\(term\)"/);
 assert.match(period,/term\.confirmed_hours/);
 assert.match(tabs,/<UiStatusCheck v-if=/);
 assert.match(check,/class="irlix-status-check"/);
 assert.match(period,/white-space:nowrap/);
 assert.match(period,/text-align:center/);
 assert.doesNotMatch(period,/class="timesheets-approved"/);
 assert.match(client,/<UiTabs[^>]*variant="card"/);
 assert.match(design,/Компактные вкладки карточки и подтверждения/);
});
