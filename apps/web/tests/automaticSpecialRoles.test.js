import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('automatic production role remains visible inside existing special roles view', () => {
  const view = read('../src/components/SpecialRolesView.vue');
  assert.match(view, /role\.automatic/);
  assert.match(view, /selectedRole\.automatic/);
  assert.match(view, /role-auto-label/);
  assert.match(view, /Участников по оргструктуре/);
  assert.match(view, /!selectedRole\.value\?\.automatic/);
  assert.match(view, /if \(!canMutateSelectedRole\.value\) return/);
  assert.match(view, /v-if="canMutateSelectedRole"/);
});

test('special roles use one internal divider instead of intersecting UiPanel frames', () => {
  const view = read('../src/components/SpecialRolesView.vue');
  assert.match(view, /roles-catalog-panel\.ui-panel/);
  assert.match(view, /role-detail-panel\.ui-panel/);
  assert.match(view, /border:0;border-radius:0;box-shadow:none/);
  assert.match(view, /border-right:1px solid var\(--irlix-color-border\)/);
});
