import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const actionMenu = readFileSync(new URL('../src/components/UiActionMenu.vue', import.meta.url), 'utf8');
const exports = readFileSync(new URL('../src/index.js', import.meta.url), 'utf8');
const example = readFileSync(new URL('../../../apps/design-system/src/App.vue', import.meta.url), 'utf8');

test('shared UiActionMenu teleports above scroll panels and uses anchored popover lifecycle', () => {
  assert.match(exports, /export \{ default as UiActionMenu \}/);
  assert.match(actionMenu, /useAnchoredPopover\(trigger, menu, \{ width: 272, align: 'right' \}\)/);
  assert.match(actionMenu, /<Teleport to="body">/);
  assert.match(actionMenu, /aria-haspopup="menu"/);
  assert.match(actionMenu, /aria-hidden="true">⋮<\/span>/);
  assert.match(actionMenu, /role="menu"/);
  assert.match(actionMenu, /role="menuitem"/);
  assert.match(actionMenu, /close\(true\);\s+emit\('select', item.id\)/);
});

test('shared menu supports keyboard actions, disabled and danger semantics', () => {
  assert.match(actionMenu, /ArrowDown/);
  assert.match(actionMenu, /ArrowUp/);
  assert.match(actionMenu, /Home/);
  assert.match(actionMenu, /End/);
  assert.match(actionMenu, /if \(event.key === 'Tab'\) close\(\)/);
  assert.match(actionMenu, /:disabled="item.disabled"/);
  assert.match(actionMenu, /item.danger/);
  assert.match(example, /<UiActionMenu/);
});
