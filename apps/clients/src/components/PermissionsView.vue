<script setup>
import { computed, onMounted, ref } from 'vue';

const data = ref({ roles: [], permissions: [], matrix: {}, scopes: [] });
const loading = ref(false);
const error = ref('');
const saving = ref('');
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
  if (role.locked || saving.value) return;
  saving.value = `${role.key}:${permission.key}`; error.value = '';
  try {
    data.value = (await api('/api/clients/permissions', {
      method: 'PUT', body: JSON.stringify({ role: role.key, permission: permission.key, allowed: scope !== 'none', scope }),
    })).data;
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}
onMounted(load);
</script>

<template>
  <section class="permissions-page">
    <div class="permissions-intro"><h2>Разрешения контура клиентов</h2><p>Настройки применяются к Clients, Timesheets и следующим сервисам этого контура. Администратор платформы всегда имеет полный доступ.</p></div>
    <div v-if="error" class="error-banner">{{error}}<button type="button" @click="load">Повторить</button></div>
    <div v-if="loading" class="empty">Загрузка разрешений…</div>
    <div v-else class="permissions-wrap"><table class="permissions-table"><thead><tr><th>Действие</th><th v-for="role in data.roles" :key="role.key">{{role.label}}</th></tr></thead><tbody><template v-for="group in permissionGroups" :key="group.key"><tr class="permission-group"><th :colspan="data.roles.length+1">{{group.label}}</th></tr><tr v-for="permission in group.permissions" :key="permission.key"><td>{{permission.label}}</td><td v-for="role in data.roles" :key="role.key"><select :value="data.matrix?.[role.key]?.[permission.key]?.scope || 'none'" :disabled="role.locked || saving===`${role.key}:${permission.key}`" @change="setPermission(role, permission, $event.target.value)"><option v-for="scope in data.scopes" :key="scope.key" :value="scope.key">{{scope.label}}</option></select></td></tr></template></tbody></table></div>
  </section>
</template>

<style scoped>
.permissions-page{padding:16px}.permissions-intro{margin-bottom:14px}.permissions-intro h2{margin:0 0 5px;font-size:19px}.permissions-intro p{margin:0;color:#69737e;font-size:12px}.permissions-wrap{overflow:auto;border:1px solid #dfe3e7;border-radius:10px}.permissions-table{border-collapse:separate;border-spacing:0;min-width:1550px;width:100%;background:#fff}.permissions-table th,.permissions-table td{min-width:180px;padding:8px;border-right:1px solid #e4e7ea;border-bottom:1px solid #e4e7ea;font-size:11px;text-align:left}.permissions-table th{position:sticky;top:0;background:#f4f5f6;z-index:2}.permissions-table th:first-child,.permissions-table td:first-child{position:sticky;left:0;min-width:255px;background:#fff;z-index:1;font-weight:600}.permissions-table th:first-child{background:#f4f5f6;z-index:3}.permissions-table select{width:100%;height:30px;border:1px solid #d8dde2;border-radius:7px;background:#fff;font:inherit}.permissions-table select:disabled{background:#f1f3f4;color:#68717a}
.permission-group th{position:static!important;left:auto!important;padding:7px 12px!important;background:#e7eaed!important;color:#59636e;font-size:11px!important;font-weight:700!important;letter-spacing:.02em;text-transform:uppercase;z-index:auto!important}
</style>
