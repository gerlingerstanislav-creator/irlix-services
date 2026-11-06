import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');

test('Clients loads only the narrow shared technology vocabulary', () => {
  assert.match(source, /api\/specialists\/clients-technology-catalog/);
  assert.doesNotMatch(source, /api\/specialists\/catalog['"`]/);
  assert.match(source, /specialistTechnologies\.value = catalog\.data\?\.technologies \|\| \[\]/);
});
