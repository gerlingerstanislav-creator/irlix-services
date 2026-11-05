<script setup>
import { computed, onMounted, ref } from 'vue';
import { UiButton, UiLoader, UiSearchSelect } from '@irlix/ui';
import { auth } from './auth';
import { moveRoleTo, orderedRoles, roleTreeOptions, shiftRole } from './permissionRoleLayout.js';

const data = ref({
  can_manage: false, enabled: false, roles: [], available_roles: [],
  role_tree: [], permissions: [], scopes: [], matrix: [],
});
const loading = ref(false);
const error = ref('');
const saving = ref('');
const draggedRole = ref('');
const hoveredRole = ref('');
const COLUMN_ORDER_KEY = 'irlix.specialists.permissions.column-order.v1';

function savedColumnOrder() {
  try {
    const keys = JSON.parse(window.localStorage.getItem(COLUMN_ORDER_KEY) || '[]');
    return Array.isArray(keys) && keys.every(key => typeof key === 'string') ? keys : [];
  } catch { return []; }
}
const columnOrder = ref(savedColumnOrder());
const displayedRoles = computed(() => orderedRoles(data.value.roles, data.value.role_tree, columnOrder.value));
function keepColumnOrder(next) {
  columnOrder.value = next.map(role => role.key);
  try { window.localStorage.setItem(COLUMN_ORDER_KEY, JSON.stringify(columnOrder.value)); }
  catch { /* Storage may be unavailable; keep the in-memory order. */ }
}
function shiftColumn(key, direction) {
  const current = displayedRoles.value;
  const next = shiftRole(current, key, direction);
  if (next !== current) keepColumnOrder(next);
}
function dragStart(role, event) {
  draggedRole.value = role.key;
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', role.key);
}
function dragOver(role, event) {
  if (!draggedRole.value || draggedRole.value === role.key) return;
  event.preventDefault();
  event.dataTransfer.dropEffect = 'move';
  hoveredRole.value = role.key;
}
function dropColumn(role, event) {
  event.preventDefault();
  const source = draggedRole.value;
  if (source && source !== role.key) {
    const next = moveRoleTo(displayedRoles.value, source, role.key);
    if (next !== displayedRoles.value) keepColumnOrder(next);
  }
  dragEnd();
}
function dragEnd() {
  draggedRole.value = '';
  hoveredRole.value = '';
}

const groups = [
  { key: 'profiles', label: 'Специалисты', prefix: 'specialists.profiles.' },
  { key: 'catalog', label: 'Технологии и компетенции', prefix: 'specialists.catalog.' },
];
const permissionGroups = computed(() => groups.map(group => ({
  ...group,
  permissions: data.value.permissions.filter(permission => permission.key.startsWith(group.prefix)),
})).filter(group => group.permissions.length));
const unassignedRoles = computed(() => roleTreeOptions(
  data.value.role_tree, data.value.available_roles, data.value.roles,
));
const hasUnassignedRoles = computed(() => unassignedRoles.value.some(option => option.kind !== 'group'));

function cell(role, permission) {
  const row = data.value.matrix.find(entry => entry.role === role && entry.permission === permission);
  return !row ? 'inherit' : (row.allowed ? row.scope : 'deny');
}
async function request(url = '/api/specialists/role-permissions', options = {}) {
  const response = await auth.fetch(url, {
    ...options,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || 'Не удалось изменить разрешения (HTTP ' + response.status + ')');
  return body.data;
}
async function load() {
  loading.value = true;
  error.value = '';
  try { data.value = await request(); }
  catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
async function addRole(role) {
  if (!role || !data.value.can_manage || saving.value
    || !unassignedRoles.value.some(option => option.kind !== 'group' && option.value === role)) return;
  saving.value = 'add:' + role;
  error.value = '';
  try {
    data.value = await request('/api/specialists/role-permissions/roles', {
      method: 'POST', body: JSON.stringify({ role }),
    });
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}
async function removeRole(role) {
  if (!data.value.can_manage || role.locked || saving.value) return;
  if (!window.confirm('Удалить роль «' + role.label + '» из матрицы Specialists? Все её разрешения будут отозваны.')) return;
  saving.value = 'remove:' + role.key;
  error.value = '';
  try {
    await request('/api/specialists/role-permissions/roles/' + encodeURIComponent(role.key), { method: 'DELETE' });
    await load();
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}
async function setPermission(role, permission, scope) {
  if (!data.value.can_manage || role.locked || saving.value) return;
  saving.value = role.key + ':' + permission.key;
  error.value = '';
  try {
    const body = JSON.stringify({ role: role.key, permission: permission.key });
    data.value = await request('/api/specialists/role-permissions', scope === 'inherit'
      ? { method: 'DELETE', body }
      : { method: 'PUT', body: JSON.stringify({
          role: role.key, permission: permission.key,
          allowed: scope !== 'deny', scope: scope === 'deny' ? 'none' : scope,
        }) });
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}
onMounted(load);
</script>

<template>
  <section class="specialists-roles">
    <div v-if="data.can_manage" class="specialists-roles-toolbar">
      <label for="specialists-role-picker">Добавить роль</label>
      <UiSearchSelect
        id="specialists-role-picker"
        class="specialists-role-select"
        :model-value="''"
        :options="unassignedRoles"
        :disabled="loading || !!saving || !hasUnassignedRoles"
        :placeholder="hasUnassignedRoles ? 'Выберите роль платформы' : 'Все роли добавлены'"
        search-placeholder="Найти роль"
        aria-label="Добавить роль в матрицу специалистов"
        :clearable="false"
        @update:model-value="addRole"
      />
      <span v-if="!data.enabled" class="specialists-roles-hint">Ролевая модель пока не активирована в окружении</span>
    </div>
    <div v-if="error" class="specialists-roles-error" role="alert">
      {{ error }} <button type="button" @click="load">Повторить</button>
    </div>
    <UiLoader v-if="loading" page label="Загрузка разрешений" />
    <div v-else class="specialists-roles-wrap">
      <table class="specialists-roles-table">
        <colgroup>
          <col class="specialists-roles-name-col">
          <col v-for="role in displayedRoles" :key="role.key" class="specialists-roles-role-col">
        </colgroup>
        <thead>
          <tr>
            <th scope="col">Разрешение</th>
            <th v-for="role in displayedRoles" :key="role.key" scope="col"
                :class="{ 'specialists-role-drop-target': hoveredRole === role.key }"
                draggable="true"
                @dragstart="dragStart(role, $event)"
                @dragover="dragOver(role, $event)"
                @dragleave="hoveredRole = hoveredRole === role.key ? '' : hoveredRole"
                @drop="dropColumn(role, $event)"
                @dragend="dragEnd">
              <div class="specialists-role-heading">
                <span :title="role.label">{{ role.label }}</span>
                <UiButton v-if="data.can_manage && !role.locked" variant="ghost"
                  :disabled="!!saving" :title="'Удалить роль «' + role.label + '»'"
                  :aria-label="'Удалить роль «' + role.label + '»'"
                  @click="removeRole(role)">×</UiButton>
              </div>
              <div class="specialists-role-order">
                <span class="specialists-role-grip" aria-hidden="true" title="Перетащите столбец">⠿</span>
                <UiButton variant="ghost" :disabled="displayedRoles[0]?.key === role.key"
                  :aria-label="'Переместить «' + role.label + '» влево'"
                  @click="shiftColumn(role.key, -1)">←</UiButton>
                <UiButton variant="ghost" :disabled="displayedRoles[displayedRoles.length - 1]?.key === role.key"
                  :aria-label="'Переместить «' + role.label + '» вправо'"
                  @click="shiftColumn(role.key, 1)">→</UiButton>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <template v-for="group in permissionGroups" :key="group.key">
            <tr class="specialists-roles-group">
              <th scope="rowgroup">{{ group.label }}</th>
              <td v-for="role in displayedRoles" :key="role.key" aria-hidden="true"></td>
            </tr>
            <tr v-for="permission in group.permissions" :key="permission.key">
              <th scope="row" class="specialists-roles-label">{{ permission.label }}</th>
              <td v-for="role in displayedRoles" :key="role.key">
                <select :value="cell(role.key, permission.key)"
                  :aria-label="permission.label + ': ' + role.label"
                  :disabled="!data.can_manage || role.locked || !!saving"
                  @change="setPermission(role, permission, $event.target.value)">
                  <option v-for="scope in data.scopes.filter(item => item.key !== 'team' || group.key === 'profiles')"
                    :key="scope.key" :value="scope.key">{{ scope.label }}</option>
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
.specialists-roles { display:flex;flex:1 1 auto;flex-direction:column;min-width:0;min-height:0; }
.specialists-roles-toolbar { display:flex;align-items:center;gap:12px;flex:none;padding:10px 16px;font-size:var(--irlix-font-size-caption); }
.specialists-role-select { width:min(360px,100%); }
.specialists-roles-hint { color:var(--irlix-color-text-muted);margin-left:auto; }
.specialists-roles-error { padding:10px 16px;color:var(--irlix-status-danger-text);background:var(--irlix-color-surface-muted); }
.specialists-roles-wrap { flex:1 1 auto;min-width:0;min-height:0;overflow:auto;overscroll-behavior:contain;border-top:1px solid var(--irlix-color-border);background:var(--irlix-color-surface); }
.specialists-roles-table { border-collapse:separate;border-spacing:0;table-layout:fixed;width:max-content;background:var(--irlix-color-surface); }
.specialists-roles-name-col { width:260px; }
.specialists-roles-role-col { width:158px; }
.specialists-roles-table th,.specialists-roles-table td { box-sizing:border-box;padding:6px 8px;border-right:1px solid var(--irlix-color-border);border-bottom:1px solid var(--irlix-color-border);text-align:left;font-size:var(--irlix-font-size-caption);text-transform:none;letter-spacing:normal; }
.specialists-roles-table thead th { position:sticky;top:0;z-index:3;height:68px;vertical-align:top;background:var(--irlix-color-surface-muted); }
.specialists-roles-table thead th:first-child { left:0;z-index:5; }
.specialists-roles-table tbody th:first-child { position:sticky;left:0;z-index:2;width:260px;background:var(--irlix-color-surface); }
.specialists-roles-table .specialists-roles-group th,.specialists-roles-table .specialists-roles-group td { background:var(--irlix-color-surface-muted); }
.specialists-roles-table .specialists-roles-group th { font-weight:700; }
.specialists-roles-label { font-weight:500; }
.specialists-role-heading { display:flex;align-items:center;justify-content:space-between;gap:2px; }
.specialists-role-heading span { min-width:0;overflow-wrap:anywhere;line-height:1.15; }
.specialists-role-heading :deep(.ui-button) { flex:none;padding:0 4px; }
.specialists-roles-table thead th:not(:first-child) { cursor:grab; }
.specialists-roles-table thead th.specialists-role-drop-target { outline:2px solid var(--irlix-color-primary);outline-offset:-2px; }
.specialists-role-order { display:flex;align-items:center;justify-content:flex-end;gap:3px;padding-top:3px; }
.specialists-role-order :deep(.ui-button) { min-width:25px;padding:0 4px; }
.specialists-role-grip { color:var(--irlix-color-text-muted);margin-right:auto;font-size:15px; }
.specialists-roles-table select { width:100%;height:30px;padding:0 4px;border:1px solid var(--irlix-color-border);border-radius:7px;background:var(--irlix-color-surface);color:var(--irlix-color-text);font:inherit; }
.specialists-roles-table select:disabled { background:var(--irlix-color-surface-muted); }
@media(max-width:720px) {
  .specialists-roles-toolbar { flex-wrap:wrap;padding:10px; }
  .specialists-roles-hint { margin-left:0; }
  .specialists-roles-name-col,.specialists-roles-table tbody th:first-child { width:210px; }
  .specialists-roles-role-col { width:148px; }
}
</style>
