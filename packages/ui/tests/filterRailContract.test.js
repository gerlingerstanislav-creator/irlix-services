import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (relative) => readFileSync(new URL(relative, import.meta.url), 'utf8');

test('shared filter rail keeps controls paired with icons and reset at the bottom', () => {
  const source = read('../src/components/UiFilterRail.vue');
  assert.match(source, /filter-\$\{item\.id\}/);
  assert.match(source, /irlix-filter-rail__filter-row/);
  assert.match(source, /irlix-filter-rail__icon-cell/);
  assert.match(source, /irlix-filter-rail__footer-row/);
  assert.match(source, /UiIcon name="reset"/);
  assert.match(source, /width: var\(--irlix-filter-rail-width\);/);
  assert.match(source, /grid-template-columns: minmax\(0, 1fr\) var\(--irlix-filter-rail-width\);/);
  assert.match(source, /irlix-filter-rail\.open \.irlix-filter-rail__layout/);
  assert.doesNotMatch(source, /grid-template-columns: 0 var\(--irlix-filter-rail-width\);/);
});

test('filter rail dimensions stay compact without shrinking filter icons', () => {
  const tokens = read('../src/styles/tokens.css');
  const rail = read('../src/components/UiFilterRail.vue');
  assert.match(tokens, /--irlix-filter-rail-width: 48px;/);
  assert.match(tokens, /--irlix-filter-rail-panel-width: 300px;/);
  assert.match(rail, /width: 40px;\n  height: 40px;/);
});

test('Employees uses hierarchical department and position filter options', () => {
  const app = read('../../../apps/web/src/App.vue');
  const tree = read('../../../apps/web/src/staffTree.js');
  assert.match(app, /departmentOptions\(departments\.value\)/);
  assert.match(app, /staffPositionTreeOptions\(departments\.value, positions\.value\)/);
  assert.match(app, /#filter-department/);
  assert.match(app, /#filter-position/);
  assert.match(tree, /export function staffPositionTreeOptions/);
  assert.match(tree, /kind: 'group'/);
  assert.match(tree, /depth: row\.depth \+ 1/);
});


test('filter rail uses the same heading typography for filters and groupings', () => {
  const source = read('../src/components/UiFilterRail.vue');
  assert.match(source, /irlix-filter-rail__section-title[^\n]*<strong>\{\{ groupingTitle \}\}<\/strong>/);
  assert.match(source, /irlix-filter-rail__header strong,.irlix-filter-rail__section-title strong \{ font-size: 13px; \}/);
});


test('app shell reserves topbar space for a fixed filter rail', () => {
  const shell = read('../src/components/UiAppShell.vue');
  assert.match(shell, /:has\(\.irlix-filter-rail:not\(\.contained\)\) > \.irlix-app-topbar/);
  assert.match(shell, /width:calc\(100% - var\(--irlix-filter-rail-width\)\)/);
});

test('filter rail keeps icon rows geometrically stable while opening', () => {
  const rail = read('../src/components/UiFilterRail.vue');
  assert.match(rail, /\.irlix-filter-rail__filter-row \{ height: 66px; \}/);
  assert.match(rail, /\.irlix-filter-rail__panel-part \{\n  min-width: 0;\n  overflow: visible;/);
});


test('filter rail does not clip nested select popovers', () => {
  const rail = read('../src/components/UiFilterRail.vue');
  const select = read('../src/components/UiSearchSelect.vue');
  assert.match(rail, /\.irlix-filter-rail__panel-part \{\n  min-width: 0;\n  overflow: visible;/);
  assert.match(select, /\.ui-search-select__menu \{ position: absolute;/);
});
