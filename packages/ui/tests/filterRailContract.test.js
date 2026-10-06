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
  assert.match(source, /grid-template-columns: 0 var\(--irlix-filter-rail-width\);/);
  assert.match(source, /irlix-filter-rail\.open \.irlix-filter-rail__layout/);
  assert.match(source, /grid-template-columns: minmax\(0, var\(--irlix-filter-rail-panel-width\)\) var\(--irlix-filter-rail-width\)/);
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
