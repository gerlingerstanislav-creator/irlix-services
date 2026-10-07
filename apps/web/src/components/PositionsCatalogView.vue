<script setup>
import { computed, ref } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiPanel, UiSearchSelect, UiTreeToggle } from '@irlix/ui';
import { auth } from '../auth';
import { departmentOptions } from '../staffTree';
import OrganizationEntityDrawer from './OrganizationEntityDrawer.vue';

const props = defineProps({
  positions: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['updated']);

const collapsed = ref(new Set());
const selectedPositionId = ref(null);
const selectedPosition = computed(() => props.positions.find((item) => String(item.id) === String(selectedPositionId.value)) || null);
const showForm = ref(false);
const showImport = ref(false);
const importFile = ref(null);
const importing = ref(false);
const importError = ref('');
const importResult = ref(null);
const saving = ref(false);
const error = ref('');
const form = ref({ name: '', direction_id: '', base_salary: '' });
const departmentChoices = computed(() => departmentOptions(props.departments));

const childrenByParent = computed(() => {
  const map = new Map();
  for (const department of props.departments) {
    const key = department.parent_id == null ? 'root' : String(department.parent_id);
    if (!map.has(key)) map.set(key, []);
    map.get(key).push(department);
  }
  for (const list of map.values()) list.sort((a, b) => String(a.name).localeCompare(String(b.name), 'ru'));
  return map;
});

const positionsByDepartment = computed(() => {
  const map = new Map();
  for (const position of props.positions) {
    const key = String(position.direction_id ?? '');
    if (!map.has(key)) map.set(key, []);
    map.get(key).push(position);
  }
  for (const list of map.values()) list.sort((a, b) => String(a.name).localeCompare(String(b.name), 'ru'));
  return map;
});

const rows = computed(() => {
  const result = [];
  const visited = new Set();
  const walk = (department, depth) => {
    const key = String(department.id);
    if (visited.has(key)) return;
    visited.add(key);
    const children = childrenByParent.value.get(key) || [];
    const ownPositions = positionsByDepartment.value.get(key) || [];
    result.push({ kind: 'department', key: `department:${key}`, item: department, depth, hasChildren: children.length > 0 || ownPositions.length > 0 });
    if (collapsed.value.has(key)) return;
    for (const position of ownPositions) result.push({ kind: 'position', key: `position:${position.id}`, item: position, depth: depth + 1 });
    for (const child of children) walk(child, depth + 1);
  };

  for (const department of childrenByParent.value.get('root') || []) walk(department, 0);
  for (const department of props.departments) if (!visited.has(String(department.id))) walk(department, 0);
  return result;
});

const toggle = (id) => {
  const key = String(id);
  const next = new Set(collapsed.value);
  if (next.has(key)) next.delete(key); else next.add(key);
  collapsed.value = next;
};

const salary = (amount) => amount == null ? '—' : new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 2 }).format(amount);
const positionEmployees = (position) => Number(position.employee_count ?? position.active_employee_count ?? 0);

const openCreate = (departmentId = '') => {
  if (!props.canManage) return;
  selectedPositionId.value = null;
  form.value = { name: '', direction_id: departmentId ? String(departmentId) : '', base_salary: '' };
  error.value = '';
  showForm.value = true;
};
const openImport = () => {
  if (!props.canManage) return;
  importFile.value = null;
  importError.value = '';
  importResult.value = null;
  showImport.value = true;
};
const onImportFile = (event) => {
  importFile.value = event.target.files?.[0] || null;
  importError.value = '';
  importResult.value = null;
};
const submitImport = async () => {
  if (!props.canManage || importing.value || !importFile.value) return;
  importing.value = true;
  importError.value = '';
  importResult.value = null;
  try {
    if (!importFile.value.name.toLowerCase().endsWith('.json')) throw new Error('Выберите файл JSON.');
    const text = await importFile.value.text();
    let payload;
    try { payload = JSON.parse(text); } catch { throw new Error('Файл содержит некорректный JSON.'); }
    const response = await auth.fetch('/api/employees/staff-positions/import', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.message || `HTTP ${response.status}`);
    importResult.value = result.data;
    emit('updated');
  } catch (e) {
    importError.value = e.message;
  } finally {
    importing.value = false;
  }
};
defineExpose({ openCreate, openImport });

const submit = async () => {
  if (!props.canManage || saving.value) return;
  if (!form.value.direction_id) { error.value = 'Выберите подразделение.'; return; }
  saving.value = true; error.value = '';
  try {
    const response = await auth.fetch('/api/employees/staff-positions', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: form.value.name.trim(),
        direction_id: Number(form.value.direction_id),
        base_salary: form.value.base_salary === '' ? null : Number(form.value.base_salary),
      }),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
    showForm.value = false;
    emit('updated');
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
};

const remove = async (position) => {
  if (!props.canManage || saving.value) return;
  if (!window.confirm(`Удалить должность «${position.name}» полностью? Это действие нельзя отменить.`)) return;
  saving.value = true; error.value = '';
  try {
    const response = await auth.fetch(`/api/employees/staff-positions/${position.id}`, { method: 'DELETE', headers: { Accept: 'application/json' } });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`);
    if (String(selectedPositionId.value) === String(position.id)) selectedPositionId.value = null;
    emit('updated');
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
};
</script>

<template>
  <div class="positions-catalog-page">
    <div v-if="error" class="alert" role="alert">{{ error }}</div>
    <UiPanel class="positions-catalog-panel">
      <div v-if="!departments.length" class="empty-state"><strong>Подразделений пока нет</strong></div>
      <div v-else class="table-wrap positions-catalog-scroll">
        <table class="irlix-data-table positions-catalog-table">
          <thead>
            <tr><th>Подразделение / должность</th><th>Оклад</th><th>Статус</th><th>Сотрудники</th><th>Действия</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.key" :class="{ 'position-row': row.kind === 'position', 'department-row': row.kind === 'department' }">
              <template v-if="row.kind === 'department'">
                <td>
                  <div class="tree-name" :style="{ paddingLeft: `${row.depth * 18}px` }">
                    <UiTreeToggle v-if="row.hasChildren" variant="plus" :expanded="!collapsed.has(String(row.item.id))" :label="`${collapsed.has(String(row.item.id)) ? 'Развернуть' : 'Свернуть'} ${row.item.name}`" @click.stop="toggle(row.item.id)" />
                    <span v-else class="tree-spacer" />
                    <strong>{{ row.item.name }}</strong><span v-if="row.item.alias" class="alias"> / {{ row.item.alias }}</span>
                  </div>
                </td>
                <td>—</td><td>—</td><td>—</td>
                <td><UiButton v-if="canManage" compact variant="secondary" @click="openCreate(row.item.id)">+ Должность</UiButton></td>
              </template>
              <template v-else>
                <td>
                  <button type="button" class="position-name" :style="{ paddingLeft: `${row.depth * 18 + 22}px` }" @click="selectedPositionId = row.item.id">{{ row.item.name }}</button>
                </td>
                <td>{{ salary(row.item.base_salary) }}</td>
                <td><UiBadge :tone="row.item.closed_at ? 'neutral' : 'success'">{{ row.item.closed_at ? 'Закрыта' : 'Открыта' }}</UiBadge></td>
                <td>{{ positionEmployees(row.item) }}</td>
                <td class="position-actions">
                  <UiButton v-if="canManage" compact variant="secondary" @click="selectedPositionId = row.item.id">Редактировать</UiButton>
                  <UiButton v-if="canManage" compact variant="danger" :disabled="saving" @click="remove(row.item)">Удалить</UiButton>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
    </UiPanel>

    <div v-if="canManage && showImport" class="overlay" @click.self="!importing && (showImport = false)">
      <form class="modal" @submit.prevent="submitImport">
        <div class="drawer-head">
          <div><div class="eyebrow">STAFF POSITIONS</div><h2>Импорт штатного расписания</h2></div>
          <button type="button" class="close" :disabled="importing" @click="showImport = false">×</button>
        </div>
        <div v-if="importError" class="alert" role="alert">{{ importError }}</div>
        <div v-if="importResult" class="import-success">
          Импорт завершён: {{ importResult.total }} записей · создано {{ importResult.created }} · обновлено {{ importResult.updated }}.
        </div>
        <label class="irlix-field">
          <span>Файл JSON</span>
          <input type="file" accept=".json,application/json" :disabled="importing" @change="onImportFile" />
        </label>
        <p class="form-hint">Файл должен содержать массив объектов с полями department, name и base_salary.</p>
        <div class="form-actions">
          <UiButton type="button" variant="secondary" :disabled="importing" @click="showImport = false">Закрыть</UiButton>
          <UiButton type="submit" :disabled="importing || !importFile">{{ importing ? 'Импорт…' : 'Загрузить' }}</UiButton>
        </div>
      </form>
    </div>

    <OrganizationEntityDrawer
      :item="selectedPosition"
      kind="position"
      :departments="departments"
      :employees="employees"
      :can-manage="canManage"
      @close="selectedPositionId = null"
      @updated="emit('updated')"
    />

    <UiDrawer :open="canManage && showForm" title="Новая должность" width="30vw" :min-width="240" :inactive="saving" @close="showForm = false">
      <form class="position-form irlix-ui" @submit.prevent="submit">
        <div v-if="error" class="alert" role="alert">{{ error }}</div>
        <label class="irlix-field"><span>Подразделение *</span><UiSearchSelect v-model="form.direction_id" :options="departmentChoices" placeholder="Выберите подразделение" search-placeholder="Поиск подразделения" /></label>
        <label class="irlix-field"><span>Должность *</span><input v-model="form.name" required maxlength="255" placeholder="Название должности" /></label>
        <label class="irlix-field"><span>Оклад</span><input v-model="form.base_salary" type="number" min="0" step="0.01" placeholder="Не указан" /></label>
        <div class="form-actions"><UiButton type="button" variant="secondary" @click="showForm = false">Отмена</UiButton><UiButton type="submit" :disabled="saving || !form.direction_id">{{ saving ? 'Сохранение…' : 'Добавить' }}</UiButton></div>
      </form>
    </UiDrawer>
  </div>
</template>

<style scoped>
.positions-catalog-page, .positions-catalog-panel { flex: 1; min-width: 0; min-height: 0; display: flex; flex-direction: column; }
.positions-catalog-scroll { flex: 1; min-height: 0; overflow: auto; }
.positions-catalog-table { min-width: 860px; }
.positions-catalog-table thead { position: sticky; top: 0; z-index: 2; }
.department-row td { background: var(--irlix-table-group-background, #f7f8fa); font-weight: 600; }
.tree-name { display: flex; align-items: center; min-height: 30px; gap: 4px; }
.tree-spacer { width: 22px; flex: none; }
.alias { color: #7a8494; font-weight: 400; }
.position-name { border: 0; background: transparent; color: var(--irlix-color-primary-text); text-align: left; cursor: pointer; }
.position-name:hover { text-decoration: underline; }
.position-actions { display: flex; gap: 6px; white-space: nowrap; }
.position-form { display: grid; gap: 16px; }
.form-actions { display: flex; gap: 8px; justify-content: flex-end; }
.import-success { padding: 9px 12px; border: 1px solid #cceade; background: #f0fbf6; color: #287057; font-size: 13px; border-radius: 8px; }
</style>
