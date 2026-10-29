import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = service => readFileSync(new URL(`../apps/${service}/src/App.vue`, import.meta.url), 'utf8');

test('Finance distinguishes pending, rejected, and failed authorization before showing a section', () => {
  const app = source('finance');
  assert.match(app, /const accessPhase = ref\('loading'\)/);
  assert.match(app, /accessPhase\.value=can\('finance\.view'\)\?'allowed':'forbidden'/);
  assert.match(app, /<UiLoader v-if="accessPhase==='loading'" page/);
  assert.match(app, /<UiErrorState v-else-if="accessPhase==='error'" :status="503"/);
  assert.match(app, /accessPhase==='forbidden' \|\| \(accessPhase==='allowed' && !busy && !sectionAllowed\(section\)\)/);
});

test('Clients waits for initial permission and directory data before evaluating section denial', () => {
  const app = source('clients');
  assert.match(app, /const startupPhase = ref\('loading'\)/);
  assert.match(app, /startupPhase\.value = 'ready'/);
  assert.match(app, /<UiLoader v-if="startupPhase==='loading'" page/);
  assert.match(app, /<UiErrorState v-else-if="startupPhase==='error'" :status="503"/);
  assert.match(app, /<UiErrorState v-else-if="!loading && \(!allowedSections\.includes\(view\)/);
});

for (const service of ['equipment', 'specialists']) {
  test(`${service} does not show 403 before the role-permissions check settles`, () => {
    const app = source(service);
    assert.match(app, /const roleAccessPhase=ref\('loading'\)/);
    assert.match(app, /roleAccessPhase\.value=e\.status===403\?'forbidden':'error'/);
    assert.match(app, /roleAccessPhase==='loading'/);
    assert.match(app, /roleAccessPhase==='error'.*:status="503"/);
    assert.match(app, /roleAccessPhase==='forbidden'.*:status="403"/);
  });
}
