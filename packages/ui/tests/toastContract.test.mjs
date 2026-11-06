import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('UiToast is exported, body-teleported and owns timer cleanup and progress', () => {
  const toast = read('../src/components/UiToast.vue');
  const api = read('../src/index.js');
  assert.match(api, /export \{ default as UiToast \}/);
  assert.match(toast, /<Teleport to="body">/);
  assert.match(toast, /right:max\(16px, env\(safe-area-inset-right\)\);bottom:max\(16px, env\(safe-area-inset-bottom\)\)/);
  assert.match(toast, /onMounted\(\(\) => \{/);
  assert.match(toast, /setTimeout\(\(\) => emit\('close'\), props\.duration\)/);
  assert.match(toast, /onBeforeUnmount\(\(\) => \{/);
  assert.match(toast, /clearTimeout\(closeTimer\)/);
  assert.match(toast, /animationDuration: duration \+ 'ms'/);
  assert.match(toast, /@keyframes irlix-toast-countdown/);
  assert.match(toast, /:role="tone === 'error' \? 'alert' : 'status'"/);
  assert.match(toast, /@click="emit\('action'\)"/);
  assert.match(toast, /@click="emit\('close'\)"/);
});

test('live design catalog has interactive toast success/error examples', () => {
  const catalog = read('../../../apps/design-system/src/catalogSections.js');
  const app = read('../../../apps/design-system/src/App.vue');
  assert.match(catalog, /id: 'notifications'/);
  assert.match(app, /UiToast/);
  assert.match(app, /showToastPreview\('success'\)/);
  assert.match(app, /showToastPreview\('error'\)/);
});

test('Specialists uses the toast without an inline autosave banner', () => {
  const body = read('../../../apps/specialists/src/SpecialistProfileBody.vue');
  assert.match(body, /notify\('success', 'Изменения сохранены'\)/);
  assert.match(body, /notify\('error', error\?\.message/);
  assert.match(body, /<UiToast v-if="toast"/);
  assert.match(body, /@action="autosave.retry\(\)"/);
  assert.doesNotMatch(body, /specialist-autosave-retry/);
  assert.doesNotMatch(body, /v-if="autosaveStatus === 'saved'"/);
  assert.match(body, /\.specialist-aux-plus\{[^}]*height:28px/);
  assert.match(body, /\.specialist-aux-tag\{[^}]*align-items:center;[^}]*height:28px/);
});
