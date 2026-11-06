import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('system accounts view requests authoritative directory whenever it mounts', () => {
  const component = readFileSync(new URL('../src/components/SystemAccountsView.vue', import.meta.url), 'utf8');
  assert.match(component, /onMounted\(\(\)\s*=>\s*\{\s*void load\(\);\s*\}\)/);
  assert.match(component, /request\('\/api\/employees\/system-accounts'\)/);
  assert.match(component, /v-else-if="loadFailed"/);
  assert.match(component, /v-if="!accounts.length"/);
});

test('production browser regression exercises API read and renders bootstrap admin', () => {
  const script = readFileSync(new URL('../../../scripts/ci/check_employees_navigation.cjs', import.meta.url), 'utf8');
  assert.match(script, /systemAccountFetches > 0/);
  assert.match(script, /getByText\('IRLIX Admin',\{exact:true\}\)\.waitFor\(\)/);
});
