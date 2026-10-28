<script setup>
import { computed, onMounted, ref } from 'vue';
import { auth } from '../auth';

const policy = ref({permissions:[],matrix:[],scopes:[],enabled:false});
const error = ref('');
const saving = ref('');
const loading = ref(false);
const newRole = ref('');
const addedRoles = ref([]);
const defaultRoles = ['org:irlix','org:client-service','org:account-management','org:sales','head:client-service'];
const roles = computed(() => [...new Set([...defaultRoles,...addedRoles.value,
  ...policy.value.matrix.map(item => item.role)])].filter(r => r !== 'special:platform-admin').sort());
const roleValid = computed(() => /^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/.test(newRole.value.trim())
  && newRole.value.trim() !== 'special:platform-admin');
const matrixScope = (role, permission) => {
  const entry = policy.value.matrix.find(item => item.role === role && item.permission === permission);
  return !entry ? 'inherit' : (entry.allowed ? entry.scope : 'deny');
};
async function request(init={}) {
  const response = await auth.fetch('/api/timesheets/permissions', {
    ...init, headers: { Accept:'application/json', 'Content-Type':'application/json' },
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.message || 'Нет доступа к матрице Timesheets');
  return data.data;
}
async function load() {
  loading.value = true; error.value = '';
  try { policy.value = await request(); }
  catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
async function change(role,permission,scope) {
  if (saving.value) return;
  saving.value = role+permission; error.value = '';
  try {
    policy.value = await request(scope === 'inherit'
      ? {method:'DELETE',body:JSON.stringify({role,permission})}
      : {method:'PUT',body:JSON.stringify({
          role,permission,allowed:scope!=='deny',scope:scope==='deny'?'none':scope,
        })});
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}
function addRole() {
  if (!roleValid.value) return;
  addedRoles.value = [...addedRoles.value, newRole.value.trim()];
  newRole.value = '';
}
onMounted(load);
</script>

<template>
  <section class="timesheets-permissions-page">
    <h2>Настройки разрешений Timesheets</h2>
    <p>Права на разделы и действия определяются только матрицей этого сервиса. При отсутствии разрешения доступ закрыт; явный запрет имеет приоритет. Роль из Keycloak сама по себе не предоставляет права.</p>
    <p v-if="!policy.enabled" class="timesheets-permissions-notice" role="status">Матрица сохраняет настройки, но ещё не активирована: перед переключением необходимо проверить соответствие текущим доступам из Clients.</p>
    <p v-if="error" role="alert">{{ error }} <button type="button" @click="load">Повторить</button></p>
    <p v-if="loading">Загрузка матрицы…</p>
    <template v-else>
      <div class="timesheets-permissions-add">
        <label>Роль <input v-model="newRole" placeholder="org:department-alias" /></label>
        <button type="button" :disabled="!roleValid" @click="addRole">Добавить колонку</button>
      </div>
      <div class="timesheets-permissions-grid">
        <table>
          <thead><tr><th>Разрешение</th><th v-for="role in roles" :key="role">{{ role }}</th></tr></thead>
          <tbody>
            <tr v-for="permission in policy.permissions" :key="permission">
              <th scope="row">{{ permission }}</th>
              <td v-for="role in roles" :key="role">
                <select :value="matrixScope(role,permission)" :disabled="!!saving"
                  :aria-label="role+' — '+permission" @change="change(role,permission,$event.target.value)">
                  <option value="inherit">Не задано</option>
                  <option value="deny">Запретить</option>
                  <option value="own">Свои</option>
                  <option value="team">Команда</option>
                  <option value="all">Все</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.timesheets-permissions-page{padding:16px;display:flex;flex-direction:column;gap:12px;min-width:0}
.timesheets-permissions-page h2{margin:0;font-size:18px}
.timesheets-permissions-page p{margin:0;font-size:13px;line-height:1.5}
.timesheets-permissions-notice{border:1px solid var(--irlix-color-border);background:var(--irlix-color-surface-muted);padding:12px;border-radius:7px}
.timesheets-permissions-add{display:flex;align-items:flex-end;gap:8px}
.timesheets-permissions-add label{display:flex;flex-direction:column;gap:5px}
.timesheets-permissions-add input,.timesheets-permissions-add button,.timesheets-permissions-grid select{border:1px solid var(--irlix-color-border);border-radius:6px;background:var(--irlix-color-surface);color:var(--irlix-color-text);font:inherit;padding:6px 8px}
.timesheets-permissions-grid{overflow:auto;max-height:calc(100dvh - 295px);border:1px solid var(--irlix-color-border);border-radius:8px}
.timesheets-permissions-grid table{border-spacing:0;border-collapse:separate;min-width:900px}
.timesheets-permissions-grid th,.timesheets-permissions-grid td{padding:9px 11px;text-align:left;border-right:1px solid var(--irlix-color-border);border-bottom:1px solid var(--irlix-color-border);font-size:12px;white-space:nowrap}
.timesheets-permissions-grid th{background:var(--irlix-color-surface-muted)}
.timesheets-permissions-grid thead th{position:sticky;top:0}
.timesheets-permissions-grid tr th:first-child{position:sticky;left:0}
</style>
