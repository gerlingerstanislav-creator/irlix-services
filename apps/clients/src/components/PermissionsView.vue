<script setup>
import { computed, onMounted, ref } from 'vue';
import { UiButton, UiLoader, UiSearchSelect } from '@irlix/ui';

const data = ref({ roles: [], available_roles: [], permissions: [], matrix: {}, scopes: [], can_manage: false });
const loading = ref(false);
const error = ref('');
const saving = ref('');
const groups = [
  { key: 'clients', label: 'Лиды и клиенты', prefixes: ['leads.', 'clients.', 'contacts.', 'reports.'] },
  { key: 'requests', label: 'Запросы', prefixes: ['requests.', 'positions.', 'attempts.'] },
  { key: 'other', label: 'Прочее', prefixes: [] },
];

const permissionGroups = computed(() => {
  const assigned = new Set();
  return groups.map(group => {
    const permissions = group.key === 'other'
      ? data.value.permissions.filter(permission => !assigned.has(permission.key))
      : data.value.permissions.filter(permission => group.prefixes.some(prefix => permission.key.startsWith(prefix)) && !assigned.has(permission.key));
    permissions.forEach(permission => assigned.add(permission.key));
    return { ...group, permissions };
  }).filter(group => group.permissions.length);
});

const unassignedRoles = computed(() => {
  const assigned = new Set(data.value.roles.map(role => role.key));
  return (data.value.available_roles || [])
    .filter(role => !role.locked && !assigned.has(role.key))
    .map(role => ({ value: role.key, label: role.label }));
});

async function api(url, options = {}) {
  const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || `HTTP ${response.status}`);
  return body;
}

async function load() {
  loading.value = true;
  error.value = '';
  try { data.value = (await api('/api/clients/permissions')).data; }
  catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}

async function addRole(role) {
  if (!role || !data.value.can_manage || saving.value || !unassignedRoles.value.some(option => option.value === role)) return;
  saving.value = `add:${role}`;
  error.value = '';
  try {
    data.value = (await api('/api/clients/permissions/roles', {
      method: 'POST', body: JSON.stringify({ role }),
    })).data;
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}

async function removeRole(role) {
  if (!data.value.can_manage || role.locked || saving.value) return;
  if (!window.confirm(`Удалить роль «${role.label}» из матрицы клиентов? Все её разрешения будут отозваны.`)) return;
  saving.value = `remove:${role.key}`;
  error.value = '';
  try {
    await api(`/api/clients/permissions/roles/${encodeURIComponent(role.key)}`, { method: 'DELETE' });
    await load();
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}

async function setPermission(role, permission, scope) {
  if (!data.value.can_manage || role.locked || data.value.matrix?.[role.key]?.[permission.key]?.locked || saving.value) return;
  saving.value = `${role.key}:${permission.key}`;
  error.value = '';
  try {
    data.value = (await api('/api/clients/permissions', {
      method: 'PUT', body: JSON.stringify({
        role: role.key, permission: permission.key,
        allowed: !['none', 'deny'].includes(scope), denied: scope === 'deny',
        scope: scope === 'deny' ? 'none' : scope,
      }),
    })).data;
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}
onMounted(load);
</script>

<template>
  <section class="permissions-page">
    <div v-if="data.can_manage" class="permissions-toolbar">
      <label for="permissions-role-picker">Добавить роль</label>
      <UiSearchSelect
        id="permissions-role-picker"
        class="permissions-role-select"
        :model-value="''"
        :options="unassignedRoles"
        :disabled="loading || !!saving || !unassignedRoles.length"
        :placeholder="unassignedRoles.length ? 'Выберите роль платформы' : 'Все роли добавлены'"
        search-placeholder="Найти роль"
        aria-label="Добавить роль в матрицу клиентов"
        :clearable="false"
        @update:model-value="addRole"
      />
    </div>
    <div v-if="error" class="error-banner">{{ error }}<button type="button" @click="load">Повторить</button></div>
    <UiLoader v-if="loading" page label="Загрузка разрешений" />
    <div v-else class="permissions-wrap">
      <table class="permissions-table">
        <colgroup>
          <col class="permissions-name-col">
          <col v-for="role in data.roles" :key="role.key" class="permissions-role-col">
        </colgroup>
        <thead>
          <tr>
            <th scope="col">Разрешение</th>
            <th v-for="role in data.roles" :key="role.key" scope="col">
              <div class="permissions-role-heading">
                <span :title="role.label">{{ role.label }}</span>
                <UiButton
                  v-if="data.can_manage && !role.locked"
                  variant="ghost"
                  :disabled="!!saving"
                  :title="`Удалить роль «${role.label}»`"
                  :aria-label="`Удалить роль «${role.label}»`"
                  @click="removeRole(role)"
                >×</UiButton>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <template v-for="group in permissionGroups" :key="group.key">
            <tr class="permissions-group-row">
              <th class="permissions-group-title" scope="rowgroup">{{ group.label }}</th>
              <td v-for="role in data.roles" :key="role.key" aria-hidden="true"></td>
            </tr>
            <tr v-for="permission in group.permissions" :key="permission.key">
              <th scope="row" class="permissions-label">{{ permission.label }}</th>
              <td v-for="role in data.roles" :key="role.key">
                <select
                  :aria-label="`${permission.label}: ${role.label}`"
                  :value="data.matrix?.[role.key]?.[permission.key]?.denied ? 'deny' : (data.matrix?.[role.key]?.[permission.key]?.scope || 'none')"
                  :disabled="!data.can_manage || role.locked || data.matrix?.[role.key]?.[permission.key]?.locked || !!saving"
                  @change="setPermission(role, permission, $event.target.value)"
                >
                  <option v-for="scope in data.scopes" :key="scope.key" :value="scope.key">{{ scope.label }}</option>
                  <option value="deny">Явно запретить</option>
                </select>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.permissions-page {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
}
.permissions-toolbar {
  display: flex;
  flex: none;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  font-size: var(--irlix-font-size-caption);
}
.permissions-role-select { width: min(360px, 100%); }
.permissions-wrap {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
  overflow: auto;
  overscroll-behavior: contain;
  border-top: 1px solid var(--irlix-color-border);
  background: var(--irlix-color-surface);
}
.permissions-table {
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed;
  width: max-content;
  background: var(--irlix-color-surface);
}
.permissions-name-col { width: 260px; }
.permissions-role-col { width: 158px; }
.permissions-table th,
.permissions-table td {
  box-sizing: border-box;
  padding: 6px 8px;
  border-right: 1px solid var(--irlix-color-border);
  border-bottom: 1px solid var(--irlix-color-border);
  text-align: left;
  font-size: var(--irlix-font-size-caption);
}
.permissions-table thead th {
  position: sticky;
  top: 0;
  z-index: 3;
  height: 48px;
  background: var(--irlix-color-surface-muted);
}
.permissions-table thead th:first-child {
  left: 0;
  z-index: 5;
}
.permissions-table tbody th:first-child {
  position: sticky;
  left: 0;
  z-index: 2;
  width: 260px;
  background: var(--irlix-color-surface);
}
.permissions-table .permissions-group-row th,
.permissions-table .permissions-group-row td {
  background: var(--irlix-color-surface-muted);
}
.permissions-table .permissions-group-row .permissions-group-title {
  position: sticky;
  left: 0;
  z-index: 2;
  font-weight: 700;
}
.permissions-table .permissions-label { font-weight: 500; }
.permissions-role-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 2px;
}
.permissions-role-heading span {
  min-width: 0;
  overflow-wrap: anywhere;
  line-height: 1.15;
}
.permissions-role-heading .ui-button { flex: none; padding: 0 4px; }
.permissions-table select {
  width: 100%;
  height: 30px;
  padding: 0 4px;
  border: 1px solid var(--irlix-color-border);
  border-radius: 7px;
  background: var(--irlix-color-surface);
  color: var(--irlix-color-text);
  font: inherit;
}
.permissions-table select:disabled {
  background: var(--irlix-color-surface-muted);
  color: var(--irlix-color-text);
}
@media (max-width: 720px) {
  .permissions-toolbar { flex-wrap: wrap; padding: 10px; }
  .permissions-name-col,
  .permissions-table tbody th:first-child { width: 210px; }
  .permissions-role-col { width: 148px; }
}
</style>
