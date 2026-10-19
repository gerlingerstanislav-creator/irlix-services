import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (file) => readFileSync(new URL('../../../' + file, import.meta.url), 'utf8');

test('shared registry keeps gutters and a two-axis scroller with sticky headers', () => {
  const css = read('packages/ui/src/styles/base.css');
  assert.match(css, /\.irlix-registry-panel\s*\{/);
  assert.match(css, /\.irlix-registry-scroll\s*\{/);
  assert.match(css, /overflow:auto;/);
  assert.match(css, /\.irlix-registry-scroll table thead th\s*\{[\s\S]*?position:sticky;/);
  assert.doesNotMatch(css, /margin-left:calc\(var\(--irlix-workspace-padding-x\) \* -1\)/);
});

test('employees permissions and all employee data registries use bounded scrollers', () => {
  const app = read('apps/web/src/App.vue');
  const permissions = read('apps/web/src/components/EmployeePermissionsView.vue');
  assert.match(app, /registry-scroll-panel irlix-registry-panel/);
  assert.match(permissions, /irlix-registry-panel employee-permissions-panel/);
  assert.match(permissions, /UiCheckbox/);
  for (const path of ['StaffPositionsView.vue', 'PositionsCatalogView.vue', 'AuditLogView.vue']) {
    assert.match(read('apps/web/src/components/' + path), /irlix-registry-scroll/);
  }
});

test('Clients tree/regular lists, Timesheets and Vacations keep independent scrolling', () => {
  assert.match(read('apps/clients/src/style.css'), /content--clients>\.clients-registry/);
  assert.match(read('apps/clients/src/App.vue'), /clients-simple-registry/);
  assert.match(read('apps/clients/src/components/RequestsWorkflowView.vue'), /workflow-list-scroll/);
  assert.match(read('apps/timesheets/src/extra.css'), /management-page>\.matrix-wrap/);
  assert.match(read('apps/vacations/src/layout-fixes.css'), /margin:12px 16px 16px!important/);
});

test('cashflow and resource monitor keep list gutters and sticky headers', () => {
  const cashflow = read('apps/clients/src/components/CashFlowView.vue');
  const resources = read('apps/portal/src/resources.css');
  assert.match(cashflow, /\.cashflow-table-scroll\{[^}]*margin:12px 16px 0/);
  assert.match(resources, /\.resource-table-scroll thead th\s*\{position:sticky/);
});
