import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = p => readFileSync(new URL(p, import.meta.url), 'utf8');

test('drawer body is an actual scroll container independent of its flex header', () => {
  const css = read('../src/styles/base.css');
  const drawer = read('../src/components/UiDrawer.vue');
  assert.match(css, /\.irlix-drawer-body \{[\s\S]*?min-height:0;[\s\S]*?overflow-y:auto;[\s\S]*?overflow-x:hidden;/);
  assert.match(css, /\.irlix-drawer-body \{[\s\S]*?overscroll-behavior:contain;/);
  assert.match(drawer, /class="irlix-drawer-body"[^>]*@wheel\.stop/);
  assert.doesNotMatch(drawer, /class="irlix-drawer-body"[^>]*@wheel\.prevent/);
});

test('wheel prevention is limited to the backdrop, never the content', () => {
  const drawer = read('../src/components/UiDrawer.vue');
  assert.match(drawer, /irlix-drawer-backdrop"[^>]*@wheel\.prevent/);
  assert.match(drawer, /<aside[\s\S]*?class="irlix-drawer"/);
});
