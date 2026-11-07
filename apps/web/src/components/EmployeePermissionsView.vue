<script setup>
import { computed, onMounted, ref } from 'vue';
import { UiButton, UiLoader, UiSearchSelect } from '@irlix/ui';
import {
  moveRoleColumn, orderedRoleColumns, permissionDisplayValue, permissionEnabled, permissionOptions, permissionSections, shiftRoleColumn,
} from '../employeePermissionLayout.js';

const matrix = ref(null);
const loading = ref(true);
const error = ref('');
const saving = ref('');
const draggedRole = ref('');
const hoveredRole = ref('');
const COLUMN_ORDER_KEY = 'irlix.employees.permissions.columns.v1';

function savedColumns() {
  try {
    const raw = window.localStorage.getItem(COLUMN_ORDER_KEY);
    if (raw === null) return null;
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) && parsed.every(key => typeof key === 'string') ? parsed : null;
  } catch { return null; }
}

const columnOrder = ref(savedColumns());
const allRoles = computed(() => Object.entries(matrix.value?.roles || {})
  .map(([key, label]) => ({ key, label, locked: key === 'platform-admin' })));
const displayedRoles = computed(() => orderedRoleColumns(matrix.value?.roles, columnOrder.value));
const availableRoles = computed(() => {
  const displayed = new Set(displayedRoles.value.map(role => role.key));
  const remaining = allRoles.value.filter(role => !displayed.has(role.key));
  const special = remaining.filter(role =>
    ['platform-admin', 'personnel-officer', 'managers', 'direction-hr', 'top-management', 'manager', 'system-admin', 'production-direction-head'].includes(role.key));
  const departments = remaining.filter(role => !special.includes(role));
  return [
    ...optionGroup('Специальные и собирательные роли', special),
    ...optionGroup('Подразделения', departments),
  ];
});
const hasAvailableRoles = computed(() => availableRoles.value.some(role => role.kind !== 'group'));
const groups = computed(() => permissionSections(matrix.value?.permissions));

function optionGroup(label, roles) {
  if (!roles.length) return [];
  return [
    { kind: 'group', value: 'group:' + label, label, depth: 0 },
    ...roles.map(role => ({ value: role.key, label: role.label, depth: 1 })),
  ];
}

function keepColumns(columns) {
  columnOrder.value = columns.map(role => role.key);
  try { window.localStorage.setItem(COLUMN_ORDER_KEY, JSON.stringify(columnOrder.value)); }
  catch { /* A blocked localStorage does not prevent changing the current view. */ }
}
function addRole(key) {
  const role = allRoles.value.find(item => item.key === key);
  if (!role || displayedRoles.value.some(item => item.key === key)) return;
  keepColumns([...displayedRoles.value, role]);
}
function hideRole(role) {
  if (role.locked) return;
  keepColumns(displayedRoles.value.filter(item => item.key !== role.key));
}
function shiftColumn(key, direction) {
  const current = displayedRoles.value;
  const next = shiftRoleColumn(current, key, direction);
  if (next !== current) keepColumns(next);
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
function dragEnd() {
  draggedRole.value = '';
  hoveredRole.value = '';
}
function dropColumn(role, event) {
  event.preventDefault();
  const source = draggedRole.value;
  if (source && source !== role.key) {
    const next = moveRoleColumn(displayedRoles.value, source, role.key);
    if (next !== displayedRoles.value) keepColumns(next);
  }
  dragEnd();
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/employees/access/permissions', { headers: { Accept: 'application/json' } });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || 'Не удалось загрузить матрицу');
    matrix.value = body.data;
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}

async function setPermission(role, permission, value) {
  if (role.locked || saving.value) return;
  const key = role.key + ':' + permission.key;
  // A legacy boolean allow may have no proven scope (rendered as "deny").
  // Allow explicitly disabling such a stored grant instead of doing nothing.
  if (permissionDisplayValue(role.key, permission.key, matrix.value?.rules, matrix.value?.scope_policies) === value
    && (value !== 'deny' || !permissionEnabled(role.key, permission.key, matrix.value?.rules))) return;
  const enabled = value !== 'deny';
  const scope = value === 'own' || value === 'all' ? value : null;
  saving.value = key;
  error.value = '';
  try {
    const response = await fetch('/api/employees/access/permissions/' +
      encodeURIComponent(role.key) + '/' + encodeURIComponent(permission.key), {
      method: 'PUT',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ enabled, scope }),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || 'Не удалось сохранить разрешение');
    const existing = matrix.value.rules.find(item => item.role === role.key && item.permission === permission.key);
    if (existing) { existing.enabled = enabled; existing.scope = scope; }
    else matrix.value.rules.push({ role: role.key, permission: permission.key, enabled, scope });
  } catch (e) { error.value = e.message; }
  finally { saving.value = ''; }
}

onMounted(load);
</script>

<template>
  <section class="permissions-page irlix-registry-panel employee-permissions-panel">
    <div class="permissions-toolbar">
      <label for="employee-permissions-role-picker">Добавить столбец роли</label>
      <UiSearchSelect
        id="employee-permissions-role-picker"
        class="permissions-role-select"
        :model-value="''"
        :options="availableRoles"
        :disabled="loading || !!saving || !hasAvailableRoles"
        :placeholder="hasAvailableRoles ? 'Выберите роль платформы' : 'Все роли показаны'"
        search-placeholder="Найти роль"
        aria-label="Показать роль в матрице сотрудников"
        :clearable="false"
        @update:model-value="addRole"
      />
      <span class="permissions-toolbar-hint">Область каждого действия задаётся отдельно. Для просмотра итоговых прав конкретного сотрудника откройте его карточку.</span>
    </div>

    <div v-if="error" class="error-banner" role="alert">
      {{ error }}
      <button type="button" @click="load">Повторить</button>
    </div>
    <UiLoader v-if="loading" page label="Загрузка разрешений" />
    <div v-else-if="matrix" class="permissions-wrap irlix-registry-scroll">
      <table class="permissions-table">
        <colgroup>
          <col class="permissions-name-col">
          <col v-for="role in displayedRoles" :key="role.key" class="permissions-role-col" :class="{ 'permissions-scope-col': ['managers', 'direction-hr'].includes(role.key) }">
        </colgroup>
        <thead>
          <tr>
            <th scope="col">Разрешение</th>
            <th
              v-for="role in displayedRoles"
              :key="role.key"
              scope="col"
              :class="{ 'permissions-drop-target': hoveredRole === role.key }"
              draggable="true"
              @dragstart="dragStart(role, $event)"
              @dragover="dragOver(role, $event)"
              @dragleave="hoveredRole = hoveredRole === role.key ? '' : hoveredRole"
              @drop="dropColumn(role, $event)"
              @dragend="dragEnd"
            >
              <div class="permissions-role-heading">
                <span :title="role.label">{{ role.label }}</span>
                <UiButton
                  v-if="!role.locked"
                  variant="ghost"
                  :title="'Скрыть столбец «' + role.label + '»'"
                  :aria-label="'Скрыть столбец «' + role.label + '»'"
                  @click="hideRole(role)"
                >×</UiButton>
              </div>
              <div class="permissions-role-order">
                <span class="permissions-role-grip" title="Перетащите заголовок, чтобы переместить столбец" aria-hidden="true">⠿</span>
                <UiButton
                  variant="ghost"
                  :disabled="displayedRoles[0]?.key === role.key"
                  :title="'Переместить «' + role.label + '» влево'"
                  :aria-label="'Переместить «' + role.label + '» влево'"
                  @click="shiftColumn(role.key, -1)"
                >←</UiButton>
                <UiButton
                  variant="ghost"
                  :disabled="displayedRoles[displayedRoles.length - 1]?.key === role.key"
                  :title="'Переместить «' + role.label + '» вправо'"
                  :aria-label="'Переместить «' + role.label + '» вправо'"
                  @click="shiftColumn(role.key, 1)"
                >→</UiButton>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <template v-for="group in groups" :key="group.key">
            <tr class="permissions-group-row">
              <th class="permissions-group-title" scope="rowgroup">{{ group.label }}</th>
              <td v-for="role in displayedRoles" :key="role.key" aria-hidden="true"></td>
            </tr>
            <tr v-for="permission in group.permissions" :key="permission.key">
              <th scope="row" class="permissions-label">{{ permission.label }}</th>
              <td v-for="role in displayedRoles" :key="role.key">
                <select
                  :aria-label="permission.label + ': ' + role.label"
                  :value="permissionDisplayValue(role.key, permission.key, matrix.rules, matrix.scope_policies)"
                  :disabled="role.locked || !!saving"
                  @change="setPermission(role, permission, $event.target.value)"
                >
                  <option v-for="option in permissionOptions(role.key, permission.key, matrix.scope_policies)" :key="option.value" :value="option.value">{{ option.label }}</option>
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
.permissions-page { display:flex; flex:1 1 auto; flex-direction:column; min-width:0; min-height:0; }
.permissions-toolbar { display:flex; flex:none; align-items:center; gap:12px; padding:10px 16px; font-size:var(--irlix-font-size-caption); flex-wrap:wrap; }
.permissions-role-select { width:min(360px, 100%); }
.permissions-toolbar-hint { color:var(--irlix-color-text-muted); }
.permissions-wrap { flex:1 1 auto; min-width:0; min-height:0; overflow:auto; overscroll-behavior:contain; border-top:1px solid var(--irlix-color-border); background:var(--irlix-color-surface); }
.permissions-table { border-collapse:separate; border-spacing:0; table-layout:fixed; width:max-content; background:var(--irlix-color-surface); }
.permissions-name-col { width:260px; }
.permissions-role-col { width:158px; }
.permissions-scope-col { width:238px; }
.permissions-table th, .permissions-table td { box-sizing:border-box; padding:6px 8px; border-right:1px solid var(--irlix-color-border); border-bottom:1px solid var(--irlix-color-border); text-align:left; font-size:var(--irlix-font-size-caption); }
.permissions-table thead th { position:sticky; top:0; z-index:3; height:68px; vertical-align:top; background:var(--irlix-color-surface-muted); }
.permissions-table thead th:first-child { left:0; z-index:5; }
.permissions-table tbody th:first-child { position:sticky; left:0; z-index:2; width:260px; background:var(--irlix-color-surface); }
.permissions-table .permissions-group-row th, .permissions-table .permissions-group-row td { background:var(--irlix-color-surface-muted); }
.permissions-table .permissions-group-row .permissions-group-title { position:sticky; left:0; z-index:2; font-weight:700; }
.permissions-table .permissions-label { font-weight:500; }
.permissions-role-heading { display:flex; align-items:center; justify-content:space-between; gap:2px; }
.permissions-role-heading span { min-width:0; overflow-wrap:anywhere; line-height:1.15; }
.permissions-role-heading .ui-button { flex:none; padding:0 4px; }
.permissions-table thead th:not(:first-child) { cursor:grab; }
.permissions-table thead th.permissions-drop-target { outline:2px solid var(--irlix-color-primary); outline-offset:-2px; }
.permissions-role-order { display:flex; align-items:center; justify-content:flex-end; gap:3px; padding-top:3px; }
.permissions-role-order .ui-button { min-width:25px; padding:0 4px; }
.permissions-role-grip { color:var(--irlix-color-text-muted); margin-right:auto; font-size:15px; }
.permissions-table select { width:100%; height:30px; padding:0 4px; border:1px solid var(--irlix-color-border); border-radius:7px; background:var(--irlix-color-surface); color:var(--irlix-color-text); font:inherit; }
.permissions-table select:disabled { background:var(--irlix-color-surface-muted); color:var(--irlix-color-text); }
@media (max-width:720px) {
  .permissions-toolbar { padding:10px; }
  .permissions-name-col, .permissions-table tbody th:first-child { width:210px; }
  .permissions-role-col { width:148px; }
  .permissions-scope-col { width:238px; }
}
</style>
