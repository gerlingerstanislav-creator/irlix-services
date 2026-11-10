import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('employee roles tab is only offered to platform admin', () => {
  const source = readFileSync(new URL('../src/components/EmployeeCardDrawer.vue', import.meta.url), 'utf8');
  assert.match(source, /v-if="isPlatformAdmin"[^>]*activeTab === 'roles'/);
  assert.match(source, /v-else-if="activeTab === 'roles' && isPlatformAdmin"/);
  assert.match(source, /if \(!isPlatformAdmin\.value\) return;\s*activeTab\.value = 'roles'/);
  assert.match(source, /\/api\/employees\/access\/roles\/employee\//);
  assert.match(source, /assignedRoles\.value = \[\]/);
});

test('server reads live roles only for administrators', () => {
  const source = readFileSync(new URL('../../../services/employees/app/Http/Controllers/AccessRoleController.php', import.meta.url), 'utf8');
  const start = source.indexOf('public function employeeRoles');
  const end = source.indexOf('public function assign', start);
  const method = source.slice(start, end);
  assert.ok(start > 0 && end > start);
  assert.match(method, /!\$this->isPlatformAdmin\(\$request\)/);
  assert.match(method, /403/);
  assert.match(method, /RoleLabels::publishedRoleClaims/);
  assert.match(method, /RoleLabels::resolve/);
});
