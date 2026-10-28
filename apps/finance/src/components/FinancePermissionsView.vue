<script setup>
import { computed, onMounted, ref } from 'vue';
import { auth } from '../auth';

const loading = ref(false);
const error = ref('');
const saving = ref('');
const policy = ref({ permissions: [], matrix: [], enabled: false });
const newRole = ref('');
const addedRoles = ref([]);
const seedRoles = ['org:irlix', 'org:accounting', 'head:accounting', 'special:personnel-officer'];
const rolePattern = /^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/;
const roles = computed(() => [...new Set([
  ...seedRoles, ...policy.value.matrix.map(row => row.role), ...addedRoles.value,
])].filter(role => role !== 'special:platform-admin').sort());
const cell = (role, permission) => {
  const row = policy.value.matrix.find(item => item.role === role && item.permission === permission);
  return !row ? 'inherit' : (row.allowed && row.scope === 'all' ? 'all' : 'deny');
};
const roleError = computed(() => newRole.value !== '' && !rolePattern.test(newRole.value.trim())
  ? 'Введите системный идентификатор org:…, head:… или special:…' : '');
async function api(init = {}) {
  const response = await auth.fetch('/api/finance/permissions', {
    ...init, headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(result.message || 'Недостаточно прав или сервис недоступен');
  return result.data;
}
async function load() {
  loading.value = true;
  error.value = '';
  try { policy.value = await api(); }
  catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
async function update(role, permission, scope) {
  if (saving.value) return;
  saving.value = role + ':' + permission;
  error.value = '';
  try {
    policy.value = await api(scope === 'inherit'
      ? { method: 'DELETE', body: JSON.stringify({ role, permission }) }
      : { method: 'PUT', body: JSON.stringify({
          role, permission, allowed: scope === 'all', scope: scope === 'all' ? 'all' : 'none',
        }) });
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}
function addRole() {
  const role = newRole.value.trim();
  if (!rolePattern.test(role) || role === 'special:platform-admin') return;
  addedRoles.value = [...addedRoles.value, role];
  newRole.value = '';
}
onMounted(load);
</script>

<template>
  <section class="finance-permissions">
    <h2>Настройки разрешений Finance</h2>
    <p>Каждое правило действует только внутри сервиса финансов. Наличие роли в Keycloak само по себе не открывает финансовые данные. Администратор платформы имеет полный доступ.</p>
    <p v-if="!policy.enabled" class="finance-permissions-notice" role="status">Новая матрица находится в режиме подготовки: настройки сохраняются, но не применяются до проверки и безопасного переключения авторизации.</p>
    <div v-if="error" class="finance-error" role="alert">{{ error }} <button type="button" @click="load">Повторить</button></div>
    <p v-if="loading">Загрузка матрицы…</p>
    <template v-else>
      <div class="finance-permissions-add">
        <label>Добавить колонку роли <input v-model="newRole" placeholder="org:department-alias" /></label>
        <button type="button" :disabled="!newRole.trim() || !!roleError || newRole.trim()==='special:platform-admin'" @click="addRole">Добавить</button>
        <small v-if="roleError" role="alert">{{ roleError }}</small>
      </div>
      <div class="finance-permissions-table">
        <table>
          <thead><tr><th>Разрешение</th><th v-for="role in roles" :key="role">{{ role }}</th></tr></thead>
          <tbody>
            <tr v-for="permission in policy.permissions" :key="permission">
              <th scope="row">{{ permission }}</th>
              <td v-for="role in roles" :key="role">
                <select :value="cell(role, permission)" :disabled="!!saving"
                  :aria-label="role + ' — ' + permission"
                  @change="update(role, permission, $event.target.value)">
                  <option value="inherit">Не задано</option>
                  <option value="deny">Запретить</option>
                  <option value="all">Все данные</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="finance-permissions-footnote">Области «Свои» и «Команда» станут доступны только после реализации серверной фильтрации каждой финансовой операции. Сейчас они намеренно запрещены.</p>
    </template>
  </section>
</template>

<style scoped>
.finance-permissions{display:flex;flex-direction:column;gap:12px;min-width:0}
.finance-permissions h2{margin:0;font-size:18px}
.finance-permissions p{margin:0;font-size:13px;line-height:1.5;color:var(--irlix-color-text-muted)}
.finance-permissions-notice{background:var(--irlix-color-surface-muted);border:1px solid var(--irlix-color-border);border-radius:7px;padding:10px}
.finance-permissions-add{display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap}
.finance-permissions-add label{display:flex;flex-direction:column;gap:4px;font-size:12px}
.finance-permissions-add input,.finance-permissions-add button,.finance-permissions-table select{padding:6px 8px;border:1px solid var(--irlix-color-border);background:var(--irlix-color-surface);color:var(--irlix-color-text);border-radius:6px;font:inherit}
.finance-permissions-add small{color:var(--irlix-status-danger-text)}
.finance-permissions-table{overflow:auto;max-height:calc(100dvh - 310px);border:1px solid var(--irlix-color-border);border-radius:7px}
.finance-permissions-table table{border-collapse:separate;border-spacing:0;min-width:850px}
.finance-permissions-table th,.finance-permissions-table td{padding:8px;border-bottom:1px solid var(--irlix-color-border);border-right:1px solid var(--irlix-color-border);white-space:nowrap;text-align:left;font-size:12px}
.finance-permissions-table thead th{position:sticky;top:0;background:var(--irlix-color-surface-muted)}
.finance-permissions-table th:first-child{position:sticky;left:0;background:var(--irlix-color-surface-muted)}
</style>
