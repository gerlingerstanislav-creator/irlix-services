<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../api';

const policy = ref({ permissions: [], matrix: [], scopes: [], enabled: false });
const loading = ref(false);
const error = ref('');
const saving = ref('');
const newRole = ref('');
const addedRoles = ref([]);
const defaultRoles = [
  'org:irlix', 'org:hr', 'head:hr', 'special:personnel-officer',
];
const validRole = computed(() =>
  /^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/.test(newRole.value.trim())
    && newRole.value.trim() !== 'special:platform-admin');
const roles = computed(() => [...new Set([
  ...defaultRoles, ...addedRoles.value, ...policy.value.matrix.map(row => row.role),
])].filter(role => role !== 'special:platform-admin').sort());
const cell = (role, permission) => {
  const item = policy.value.matrix.find(row => row.role === role && row.permission === permission);
  return !item ? 'inherit' : (item.allowed ? item.scope : 'deny');
};
const labels = {
  'vacations.mine.view': 'Мои отпуска — просмотр',
  'vacations.mine.manage': 'Мои отпуска — изменение',
  'vacations.department.view': 'Отпуска подразделения — просмотр',
  'vacations.department.manage': 'Отпуска подразделения — создание',
  'vacations.manage.view': 'Управление отсутствиями — просмотр',
  'vacations.manage.manage': 'Управление отсутствиями — изменение',
  'vacations.approvals.view': 'Задачи согласования — просмотр',
  'vacations.approvals.manage': 'Задачи согласования — решение',
  'vacations.documents.view': 'Документы — содержимое',
  'vacations.documents.manage': 'Документы — изменение',
  'vacations.history.view': 'История — просмотр',
  'vacations.client-calendar.view': 'Клиентский календарь — просмотр',
};
async function load() {
  loading.value = true; error.value = '';
  try { policy.value = (await api('/api/vacations/permissions')).data; }
  catch (e) { error.value = e.message || 'Не удалось получить матрицу'; }
  finally { loading.value = false; }
}
async function save(role, permission, effect) {
  if (saving.value) return;
  saving.value = role + permission; error.value = '';
  try {
    const options = effect === 'inherit'
      ? { method: 'DELETE', body: { role, permission } }
      : { method: 'PUT', body: {
          role, permission, allowed: effect !== 'deny',
          scope: effect === 'deny' ? 'none' : effect,
        } };
    policy.value = (await api('/api/vacations/permissions', options)).data;
  } catch (e) { error.value = e.message || 'Не удалось изменить правило'; }
  finally { saving.value = ''; }
}
function addRole() {
  if (!validRole.value) return;
  addedRoles.value = [...addedRoles.value, newRole.value.trim()];
  newRole.value = '';
}
onMounted(load);
</script>

<template>
  <section class="vacation-access-settings">
    <h2>Разрешения Vacations</h2>
    <p>Матрица управляет доступом только внутри Vacations. Бизнес-правила согласований, конфиденциальность документов, текущие статусы и конкретные назначения согласующих остаются обязательными ограничениями.</p>
    <p v-if="!policy.enabled" class="vacation-access-note" role="status">Новая матрица пока в режиме подготовки и не влияет на действующие права. Переключение допускается после сверки старых и новых разрешений.</p>
    <p v-if="error" class="vacation-access-error" role="alert">{{error}} <button type="button" @click="load">Повторить</button></p>
    <p v-if="loading">Загрузка матрицы…</p>
    <template v-else>
      <div class="vacation-access-add">
        <label>Добавить роль из Keycloak <input v-model="newRole" placeholder="org:department-alias" /></label>
        <button type="button" :disabled="!validRole" @click="addRole">Добавить колонку</button>
      </div>
      <div class="vacation-access-table">
        <table>
          <thead><tr><th>Действие</th><th v-for="role in roles" :key="role">{{role}}</th></tr></thead>
          <tbody>
            <tr v-for="permission in policy.permissions" :key="permission">
              <th scope="row">{{labels[permission] || permission}}</th>
              <td v-for="role in roles" :key="role">
                <select :value="cell(role,permission)" :disabled="!!saving" :aria-label="role+' — '+permission" @change="save(role,permission,$event.target.value)">
                  <option value="inherit">Не назначено</option>
                  <option value="deny">Явно запретить</option>
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
.vacation-access-settings{padding:16px;display:flex;flex-direction:column;gap:12px;min-width:0;width:100%}
.vacation-access-settings h2{font-size:18px;margin:0}
.vacation-access-settings p{font-size:13px;margin:0;line-height:1.5}
.vacation-access-note{background:var(--irlix-color-surface-muted);border:1px solid var(--irlix-color-border);border-radius:7px;padding:12px}
.vacation-access-error{color:var(--irlix-status-danger-text)}
.vacation-access-add{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap}
.vacation-access-add label{display:flex;flex-direction:column;gap:5px;font-size:12px}
.vacation-access-add input,.vacation-access-add button,.vacation-access-table select{padding:6px 9px;border:1px solid var(--irlix-color-border);border-radius:7px;font:inherit;color:var(--irlix-color-text);background:var(--irlix-color-surface)}
.vacation-access-table{max-height:calc(100dvh - 295px);overflow:auto;border:1px solid var(--irlix-color-border);border-radius:8px}
.vacation-access-table table{min-width:900px;border-spacing:0;border-collapse:separate}
.vacation-access-table th,.vacation-access-table td{font-size:12px;padding:9px 10px;border-right:1px solid var(--irlix-color-border);border-bottom:1px solid var(--irlix-color-border);white-space:nowrap;text-align:left}
.vacation-access-table th{background:var(--irlix-color-surface-muted)}
.vacation-access-table thead th{position:sticky;top:0}
.vacation-access-table th:first-child{position:sticky;left:0}
</style>
