<script setup>
import { onMounted, ref } from 'vue';
import { UiCheckbox, UiPanel } from '@irlix/ui';

const matrix = ref(null);
const error = ref('');
const saving = ref('');
const defaults = {
  'platform-admin': ['*'],
  'platform-tester': ['*'],
  'personnel-officer': ['employees.read','employees.application.create','employees.hire','employees.salary.read','organization.read','staff_positions.read'],
  hr: ['employees.read','employees.application.create','organization.read','staff_positions.read'],
  finance: ['employees.read','employees.salary.read','employees.salary.manage','organization.read','staff_positions.read'],
  manager: ['employees.read','employees.application.create','employees.salary.read','organization.read','staff_positions.read'],
  'system-admin': [],
};
const load = async () => {
  try {
    const response = await fetch('/api/employees/access/permissions', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Не удалось загрузить матрицу');
    matrix.value = (await response.json()).data;
  } catch (e) { error.value = e.message; }
};
const enabled = (role, permission) => {
  if (role === 'platform-admin') return true;
  const rule = matrix.value?.rules?.find(item => item.role === role && item.permission === permission);
  return rule ? Boolean(rule.enabled) : (defaults[role] || []).includes('*') || (defaults[role] || []).includes(permission);
};
const toggle = async (role, permission) => {
  const newValue = !enabled(role, permission);
  const key = role + ':' + permission;
  saving.value = key; error.value = '';
  try {
    const response = await fetch(`/api/employees/access/permissions/${role}/${permission}`, {
      method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ enabled: newValue }),
    });
    if (!response.ok) throw new Error((await response.json()).message || 'Не удалось сохранить');
    const existing = matrix.value.rules.find(item => item.role === role && item.permission === permission);
    if (existing) existing.enabled = newValue;
    else matrix.value.rules.push({ role, permission, enabled: newValue });
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
};
onMounted(load);
</script>
<template>
  <UiPanel class="irlix-registry-panel employee-permissions-panel">
    <div class="employee-permissions-intro">
      <h2>Разрешения сервиса сотрудников</h2>
      <p>Доступы назначаются ролям. Администратор платформы имеет полный доступ. Изменения применяются на сервере.</p>
    </div>
    <p v-if="error" class="employee-form-error">{{ error }}</p>
    <div v-if="matrix" class="table-wrap irlix-registry-scroll">
      <table class="irlix-data-table employee-permissions-table">
        <thead><tr><th>Разрешение</th><th v-for="(label, role) in matrix.roles" :key="role">{{ label }}</th></tr></thead>
        <tbody>
          <tr v-for="(label, permission) in matrix.permissions" :key="permission">
            <td>{{ label }}</td>
            <td v-for="(_, role) in matrix.roles" :key="role" class="employee-permissions-cell">
              <UiCheckbox :model-value="enabled(role, permission)" :disabled="role === 'platform-admin' || saving !== ''" :aria-label="label + ': ' + matrix.roles[role]" @change="toggle(role, permission)" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else>Загрузка…</p>
  </UiPanel>
</template>

<style scoped>
.employee-permissions-panel { background:var(--irlix-color-surface); }
.employee-permissions-intro { flex:none; padding:18px 20px 16px; border-bottom:1px solid var(--irlix-color-border); }
.employee-permissions-intro h2 { margin:0 0 6px; font-size:var(--irlix-font-size-section-title); }
.employee-permissions-intro p { margin:0; color:var(--irlix-color-text-muted); font-size:var(--irlix-font-size-caption); line-height:1.45; }
.employee-permissions-table { min-width:1020px; }
.employee-permissions-table th:first-child { min-width:255px; position:sticky; left:0; z-index:4; }
.employee-permissions-table td:first-child { position:sticky; left:0; z-index:3; background:var(--irlix-color-surface); font-weight:550; }
.employee-permissions-cell { text-align:center; }
.employee-permissions-cell :deep(.ui-checkbox) { justify-content:center; }
.employee-form-error { margin:10px 18px; color:var(--irlix-color-danger); }
</style>
