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

test('UiRegistryTable uses employee-like typography with flat full-bleed container and body-only scrolling', () => {
  const component = read('packages/ui/src/components/UiRegistryTable.vue');
  assert.match(component, /columns: \{ type: Array, required: true/);
  assert.match(component, /class="ui-registry-table__header"/);
  assert.match(component, /class="ui-registry-table__scroll"/);
  assert.ok(component.indexOf('class="ui-registry-table__header"') < component.indexOf('class="ui-registry-table__scroll"'));
  assert.match(component, /\.ui-registry-table \{[^}]*margin:0; padding:0; border:0; border-radius:0;/);
  assert.match(component, /\.ui-registry-table__scroll \{[^}]*overflow:auto;/);
  assert.match(component, /\.ui-registry-table__header-viewport \{ overflow:hidden/);
  assert.match(component, /onScroll.*scrollLeft = viewport\.value\.scrollLeft/);
  assert.match(component, /offsetWidth - el\.clientWidth/);
  assert.match(component, /vertical-align:middle/);
  assert.doesNotMatch(component, /\.ui-registry-table__header[^\n]*overflow:auto/);
  assert.match(read('apps/design-system/src/App.vue'), /UiRegistryTable\b[^>]*aria-label="Пример общего реестра" :columns=/);
});

test('UiLoader is a shared accessible overlay with reduced-motion support and a live example', () => {
  const component = read('packages/ui/src/components/UiLoader.vue');
  const index = read('packages/ui/src/index.js');
  const design = read('apps/design-system/src/App.vue');
  assert.match(index, /export \{ default as UiLoader \} from '\.\/components\/UiLoader\.vue'/);
  assert.match(component, /role="status"/);
  assert.match(component, /aria-live="polite"/);
  assert.match(component, /:aria-label="label"/);
  assert.match(component, /\.ui-loader--overlay\s*\{[^}]*position: absolute/);
  assert.match(component, /prefers-reduced-motion: reduce/);
  assert.match(design, /id="section-loaders"/);
  assert.match(design, /UiLoader v-if="registryState === 'loading'" overlay/);
});
