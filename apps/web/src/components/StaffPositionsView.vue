<script setup>
import { computed, ref } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiPanel, UiSearchSelect, UiTreeToggle } from '@irlix/ui';
import { auth } from '../auth';
import { buildStaffTree, departmentOptions, departmentPositions, shortEmployeeName } from '../staffTree';
import OrganizationEntityDrawer from './OrganizationEntityDrawer.vue';

const props = defineProps({
  positions: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
  canManageOrganization: { type: Boolean, default: false },
  canDeleteDepartments: { type: Boolean, default: false },
});
const emit = defineEmits(['updated', 'employees']);
const collapsed = ref(new Set());
const selection = ref(null);
const positionId = ref(null);
const selectedPosition = computed(() => props.positions.find(p => String(p.id) === String(positionId.value)) || null);
const selectedItem = computed(() => {
  if (!selection.value) return null;
  return (selection.value.kind === 'department' ? props.departments : props.positions).find(i => String(i.id) === String(selection.value.id)) || null;
});
const rows = computed(() => buildStaffTree(props.departments, props.positions, collapsed.value));
const toggle = (id) => {
  const key = String(id);
  const next = new Set(collapsed.value);
  if (next.has(key)) next.delete(key); else next.add(key);
  collapsed.value = next;
};
const openCard = (row, tab = 'info') => { positionId.value = null; selection.value = { kind: row.kind, id: row.item.id, tab }; };
const openPosition = (position) => { positionId.value = position.id; };
const positionCount = (department) => departmentPositions(props.positions, department.id).length;
const showForm = ref(false);
const showImport = ref(false);
const importFile = ref(null);
const importing = ref(false);
const importError = ref('');
const importResult = ref(null);
const form = ref({ name: '', direction_id: '' });
const saving = ref(false);
const error = ref('');
const options = computed(() => departmentOptions(props.departments));
const openCreate = (departmentId = '') => {
  if (!props.canManage) return;
  positionId.value = null;
  if (!departmentId) selection.value = null;
  form.value = { name: '', direction_id: departmentId ? String(departmentId) : '' };
  error.value = ''; showForm.value = true;
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
  if (!form.value.direction_id) { error.value = 'Выберите подразделение для должности.'; return; }
  saving.value = true; error.value = '';
  try {
    const response = await auth.fetch('/api/employees/staff-positions', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ name: form.value.name.trim(), direction_id: Number(form.value.direction_id), base_salary: null }) });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
    selection.value = { kind: 'department', id: Number(form.value.direction_id), tab: 'positions' };
    showForm.value = false; emit('updated');
  } catch (e) { error.value = e.message; }
  finally { saving.value = false; }
};
const count = (row) => row.item.active_employee_count ?? 0;
const employeeFilter = (row) => ({ department_id: row.item.id, employment_status: 'Трудоустроен' });
const employeesHref = (row) => `/employees/?${new URLSearchParams(employeeFilter(row))}`;
</script>

<template>
  <div class="staff-positions-page">
    <UiPanel class="staff-positions-panel">
      <div v-if="!departments.length" class="empty-state"><strong>Оргструктура пока пуста</strong><span>Сначала добавьте подразделение.</span></div>
      <div v-else class="table-wrap organization-table-wrap staff-tree-scroll" data-testid="staff-tree-scroll">
        <table class="irlix-data-table organization-table">
          <thead><tr><th>◇ Название / Алиас</th><th>♙ Руководитель</th><th>♙ HR</th><th>♧ Сотрудники</th><th>Должности</th><th>◇ ID (Яндекс)</th><th>◇ Группа (LDAP)</th></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.key" :data-department-id="row.item.id">
              <td>
                <div class="org-name" :style="{ paddingLeft: `${row.depth * 18}px` }">
                  <UiTreeToggle v-if="row.hasChildren" variant="plus" :expanded="!collapsed.has(String(row.item.id))" :label="`${collapsed.has(String(row.item.id)) ? 'Развернуть' : 'Свернуть'} ${row.item.name}`" @click.stop="toggle(row.item.id)" />
                  <span v-else class="staff-tree-spacer" />
                  <button type="button" class="org-entity-name" @click="openCard(row)">{{ row.item.name }}<small v-if="row.item.alias"> / {{ row.item.alias }}</small></button>
                  <UiBadge v-if="row.item.is_production" tone="info">Производственное</UiBadge>
                </div>
              </td>
              <td>{{ shortEmployeeName(row.item.manager_name) || '—' }}</td>
              <td>{{ shortEmployeeName(row.item.hr_name) || '—' }}</td>
              <td><a :href="employeesHref(row)" :aria-label="`Показать сотрудников ${row.item.name}`" @click.prevent="emit('employees', employeeFilter(row))">{{ count(row) }}</a></td>
              <td><button type="button" class="org-position-count" :aria-label="`Открыть должности ${row.item.name}`" @click="openCard(row, 'positions')">{{ positionCount(row.item) }}</button></td>
              <td>{{ row.item.yandex_id ?? '—' }}</td>
              <td>{{ row.item.ldap_group || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiPanel>
    <OrganizationEntityDrawer :item="selectedItem" kind="department" :departments="departments" :employees="employees" :positions="positions" :initial-tab="selection?.tab || 'info'" :inactive="Boolean(selectedPosition) || showForm" :can-create-positions="canManage" :can-manage-positions="canManage" :can-delete-department="canDeleteDepartments" @create="openCreate(selectedItem.id)" @position="openPosition" @employees="emit('employees', $event)" :can-manage="canManageOrganization" @close="selection = null" @updated="emit('updated')" />
    <OrganizationEntityDrawer :item="selectedPosition" kind="position" :departments="departments" :employees="employees" :can-manage="canManage" @close="positionId = null" @updated="emit('updated')" />
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
    <UiDrawer :open="canManage && showForm" title="Новая должность" width="30vw" :min-width="240" :z-index="1050" :inactive="saving" @close="showForm = false">
      <form class="staff-position-form irlix-ui" @submit.prevent="submit">
        <div v-if="error" class="alert" role="alert">{{ error }}</div>
        <label class="irlix-field"><span>Подразделение *</span><UiSearchSelect v-model="form.direction_id" :options="options" placeholder="Выберите подразделение" search-placeholder="Поиск подразделения" /></label>
        <label class="irlix-field"><span>Должность *</span><input v-model="form.name" required maxlength="255" placeholder="Название должности" /></label>
        <div class="staff-position-actions"><UiButton type="button" variant="secondary" @click="showForm = false">Отмена</UiButton><UiButton type="submit" :disabled="saving || !form.direction_id">{{ saving ? 'Сохранение…' : 'Добавить' }}</UiButton></div>
      </form>
    </UiDrawer>
  </div>
</template>

<style scoped>
.staff-positions-page, .staff-positions-panel { flex: 1; min-height: 0; min-width: 0; display: flex; flex-direction: column; overflow: hidden; }
.staff-tree-scroll { flex: 1; min-height: 0; min-width: 0; overflow: auto; }
.staff-tree-spacer { width: 22px; flex: none; }
.org-entity-name, .org-position-count { border: 0; background: transparent; padding: 0; color: inherit; text-align: left; cursor: pointer; }
.org-entity-name:hover, .org-position-count { color: var(--irlix-color-primary); }
.org-entity-name:focus-visible, .org-position-count:focus-visible { outline: 2px solid var(--irlix-color-primary); }
.staff-position-form { display: grid; gap: 16px; }
.staff-position-actions { display: flex; gap: 8px; justify-content: flex-end; }
.import-success { padding: 9px 12px; border: 1px solid #cceade; background: #f0fbf6; color: #287057; font-size: 13px; border-radius: 8px; }
@media (max-width: 720px) { .staff-tree-scroll { max-height: calc(100dvh - 150px); } }
</style>

