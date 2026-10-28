<script setup>
import { computed, onMounted, ref } from 'vue';
import { UiLoader } from '@irlix/ui';

const data = ref({ roles: [], permissions: [], matrix: {}, scopes: [], can_manage: false });
const loading = ref(false);
const error = ref('');
const saving = ref('');
const newRole = ref('');
const roleError = computed(() => newRole.value.trim() !== '' && !/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/.test(newRole.value.trim())
  ? 'Используйте org:…, head:… или special:…' : '');
function addRole() {
  const key = newRole.value.trim();
  if (roleError.value || !key || key === 'special:platform-admin'
    || data.value.roles.some(role => role.key === key)) return;
  data.value.roles = [...data.value.roles, { key, label: key, locked: false }];
  newRole.value = '';
}

const groups = [
  { key:'clients', label:'Лиды и клиенты', prefixes:['leads.','clients.','contacts.','reports.'] },
  { key:'requests', label:'Запросы', prefixes:['requests.','positions.','attempts.'] },
  { key:'timesheets', label:'ТШ', prefixes:['timesheets.'] },
  { key:'other', label:'Прочее', prefixes:[] },
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

async function api(url, options = {}) {
  const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || `HTTP ${response.status}`);
  return body;
}
async function load() {
  loading.value = true; error.value = '';
  try { data.value = (await api('/api/clients/permissions')).data; }
  catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
async function setPermission(role, permission, scope) {
  if (!data.value.can_manage || role.locked || data.value.matrix?.[role.key]?.[permission.key]?.locked || saving.value) return;
  saving.value = `${role.key}:${permission.key}`; error.value = '';
  try {
    data.value = (await api('/api/clients/permissions', {
      method: 'PUT', body: JSON.stringify({ role: role.key, permission: permission.key,
        allowed: !['none', 'deny'].includes(scope), denied: scope === 'deny',
        scope: scope === 'deny' ? 'none' : scope }),
    })).data;
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}
onMounted(load);
</script>

<template>
  <section class="permissions-page">
    <div class="permissions-intro"><h2>Разрешения контура клиентов</h2><p>Настройки применяются к Clients; до отдельного переключения Timesheets здесь также отображаются исторические разрешения таймшитов. Администратор платформы всегда имеет полный доступ. Тестировщику платформы доступ к сервису клиентов закрыт.</p></div>
    <div v-if="error" class="error-banner">{{error}}<button type="button" @click="load">Повторить</button></div>
    <div class="permissions-add-role" v-if="data.can_manage"><label>Добавить роль из Keycloak <input v-model="newRole" placeholder="org:department-alias" /></label><button type="button" :disabled="!newRole.trim() || !!roleError || newRole.trim()==='special:platform-admin'" @click="addRole">Добавить колонку</button><small v-if="roleError" role="alert">{{roleError}}</small></div>
    <UiLoader v-if="loading" page label="Загрузка разрешений" />
    <div v-else class="permissions-wrap"><table class="permissions-table"><thead><tr><th>Действие</th><th v-for="role in data.roles" :key="role.key">{{role.label}}</th></tr></thead><tbody><template v-for="group in permissionGroups" :key="group.key"><tr class="irlix-table-group"><th :colspan="data.roles.length+1">{{group.label}}</th></tr><tr v-for="permission in group.permissions" :key="permission.key"><td>{{permission.label}}</td><td v-for="role in data.roles" :key="role.key"><select :value="data.matrix?.[role.key]?.[permission.key]?.denied ? 'deny' : (data.matrix?.[role.key]?.[permission.key]?.scope || 'none')" :disabled="!data.can_manage || role.locked || data.matrix?.[role.key]?.[permission.key]?.locked || saving===`${role.key}:${permission.key}`" @change="setPermission(role, permission, $event.target.value)"><option v-for="scope in data.scopes" :key="scope.key" :value="scope.key">{{scope.label}}</option><option value="deny">Явно запретить</option></select></td></tr></template></tbody></table></div>
  </section>
</template>

<style scoped>
.permissions-page{padding:16px}.permissions-intro{margin-bottom:14px}.permissions-intro h2{margin:0 0 5px;font-size:var(--irlix-font-size-section-title)}.permissions-intro p{margin:0;color:var(--irlix-color-text);font-size:var(--irlix-font-size-caption)}.permissions-wrap{overflow:auto;border:1px solid var(--irlix-color-border);border-radius:10px}.permissions-table{border-collapse:separate;border-spacing:0;min-width:1550px;width:100%;background:var(--irlix-color-surface)}.permissions-table th,.permissions-table td{min-width:180px;padding:8px;border-right:1px solid var(--irlix-color-border);border-bottom:1px solid var(--irlix-color-border);font-size:var(--irlix-font-size-caption);text-align:left}.permissions-table th{position:sticky;top:0;background:var(--irlix-color-surface-muted);z-index:2}.permissions-table th:first-child,.permissions-table td:first-child{position:sticky;left:0;min-width:255px;background:var(--irlix-color-surface);z-index:1;font-weight:600}.permissions-table th:first-child{background:var(--irlix-color-surface-muted);z-index:3}.permissions-table select{width:100%;height:30px;border:1px solid var(--irlix-color-border);border-radius:7px;background:var(--irlix-color-surface);font:inherit}.permissions-table select:disabled{background:var(--irlix-color-surface-muted);color:var(--irlix-color-text)}

/* Keep matrix headings visible with both scroll axes contained in the card. */
.permissions-wrap{max-height:calc(100dvh - var(--irlix-topbar-height, 45px) - 155px);overscroll-behavior:contain}
</style>

<style scoped>
.permissions-add-role{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap;margin:0 0 12px;font-size:12px}
.permissions-add-role label{display:flex;flex-direction:column;gap:4px}
.permissions-add-role input,.permissions-add-role button{min-height:30px;border:1px solid var(--irlix-color-border);background:var(--irlix-color-surface);color:var(--irlix-color-text);padding:5px 8px;border-radius:6px}
.permissions-add-role small{color:var(--irlix-status-danger-text)}
</style>
