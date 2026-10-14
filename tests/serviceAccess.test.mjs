import test from 'node:test';
import assert from 'node:assert/strict';
import { getVisibleServiceGroups } from '../packages/ui/src/serviceCatalog.js';

const keys = access => getVisibleServiceGroups(false, access).flatMap(g => g.items.map(s => s.key));

test('HR user cannot see Equipment or restricted platform services', () => {
  const visible = keys({roles:['hr'], services:{dashboard:true, employees:true, vacations:true, equipment:false}});
  assert.deepEqual(visible.sort(), ['dashboard','employees','vacations'].sort());
});
test('system administrator sees Equipment only with explicit entitlement', () => {
  assert.ok(keys({roles:['system-admin'], services:{dashboard:true,equipment:true}}).includes('equipment'));
  assert.ok(!keys({roles:['system-admin'], services:{dashboard:true,equipment:false}}).includes('equipment'));
});
test('missing access never exposes business services', () => {
  assert.deepEqual(keys(null), ['dashboard']);
  assert.deepEqual(keys({roles:['platform-admin']}), ['dashboard']);
});
test('platform tester does not inherit Migration entitlement', () => {
  const visible=keys({roles:['platform-tester'],services:{dashboard:true,equipment:true,migration:false,'design-system':true}});
  assert.ok(visible.includes('equipment'));
  assert.ok(!visible.includes('migration'));
});
