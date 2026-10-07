import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(new URL('../src/components/UiSearchSelect.vue', import.meta.url), 'utf8');

test('UiSearchSelect supports teleported menus for clipped containers', () => {
  assert.match(source, /teleport:\s*\{\s*type:\s*Boolean/);
  assert.match(source, /<Teleport to="body" :disabled="!teleport">/);
  assert.match(source, /menu\.value\?\.contains\(event\.target\)/);
  assert.match(source, /position:\s*'fixed'/);
  assert.match(source, /--ui-search-select-options-max-height/);
});
