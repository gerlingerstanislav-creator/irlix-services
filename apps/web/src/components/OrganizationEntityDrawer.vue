<script setup>
import { computed, ref, watch } from 'vue';
import { UiButton, UiDrawer, UiSearchSelect, UiTabs } from '@irlix/ui';
import { auth } from '../auth';
import { departmentOptions, departmentPositions, employeeTreeOptions } from '../staffTree';

const props = defineProps({
  item: { type: Object, default: null },
  kind: { type: String, required: true },
  departments: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  positions: { type: Array, default: () => [] },
  initialTab: { type: String, default: 'info' },
  inactive: { type: Boolean, default: false },
  canManagePositions: { type: Boolean, default: false },
  canCreatePositions: { type: Boolean, default: false },
  canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'updated', 'position', 'employees', 'create']);
const editing = ref(null);
const value = ref('');
const saving = ref(false);
const error = ref('');
const activeTab = ref('info');
watch(() => [props.kind, props.item?.id, props.initialTab], () => { editing.value = null; error.value = ''; activeTab.value = props.initialTab === 'positions' ? 'positions' : 'info'; }, { immediate: true });
const isDepartment = computed(() => props.kind === 'department');
const ownPositions = computed(() => departmentPositions(props.positions, props.item?.id));
const tabs = computed(() => [{ value: 'info', label: 'Инфо' }, { value: 'positions', label: 'Должности', count: ownPositions.value.length }]);
const salary = amount => amount == null ? '—' : new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 2 }).format(amount);
const employeeFilters = position => ({ department_id: String(props.item.id), position_id: String(position.id), employment_status: 'Трудоустроен' });
const employeeHref = position => '/employees?' + new URLSearchParams(employeeFilters(position));
const changeTab = tab => { activeTab.value = tab; editing.value = null; error.value = ''; };
const mutatePosition = async (position, action) => {
  if (!(isDepartment.value ? props.canManagePositions : props.canManage) || saving.value) return;
  if (action === 'close' && position.closed_at) return;
  if (action === 'delete' && !window.confirm(`Удалить должность «${position.name}» полностью? Это действие нельзя отменить.`)) return;
  saving.value = true; error.value = '';
  try {
    const response = await auth.fetch(`/api/employees/staff-positions/${position.id}${action === 'close' ? '/close' : ''}`, { method: action === 'close' ? 'POST' : 'DELETE', headers: { Accept: 'application/json' } });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.message || `HTTP ${response.status}`);
    emit('updated');
    if (action === 'delete' && !isDepartment.value) emit('close');
  } catch (e) { error.value = e.message; }
  finally { saving.value = false; }
};
const parentOptions = computed(() => {
  const excluded = new Set([String(props.item?.id)]);
  let changed = true;
  while (changed) {
    changed = false;
    for (const d of props.departments) if (excluded.has(String(d.parent_id)) && !excluded.has(String(d.id))) { excluded.add(String(d.id)); changed = true; }
  }
  return departmentOptions(props.departments).filter(o => !excluded.has(String(o.value)));
});
const peopleOptions = computed(() => employeeTreeOptions(props.departments, props.employees.filter(e => e.employment_status === 'Трудоустроен')));
const fields = computed(() => isDepartment.value ? [
  { key: 'name', label: 'Название', required: true },
  { key: 'alias', label: 'Алиас' },
  { key: 'parent_id', label: 'Родительское подразделение', options: parentOptions.value },
  { key: 'manager_id', label: 'Руководитель', options: peopleOptions.value },
  { key: 'hr_id', label: 'HR', options: peopleOptions.value },
  { key: 'yandex_id', label: 'ID (Яндекс)', type: 'number' },
  { key: 'ldap_group', label: 'Группа LDAP / Keycloak' },
  { key: 'is_production', label: 'Производственное подразделение', type: 'boolean' },
] : [
  { key: 'name', label: 'Название', required: true },
  { key: 'direction_id', label: 'Подразделение', required: true, options: departmentOptions(props.departments) },
  { key: 'base_salary', label: 'Оклад', type: 'number', min: 0, step: '0.01' },
  { key: 'status', label: 'Статус', readonly: true },
  { key: 'closed_at', label: 'Дата закрытия', type: 'date', readonly: true },
  { key: 'employee_count', label: 'Сотрудники', readonly: true },
  { key: 'id', label: 'ID', readonly: true },
  { key: 'created_at', label: 'Создана', type: 'date', readonly: true },
  { key: 'updated_at', label: 'Обновлена', type: 'date', readonly: true },
]);
const display = (field) => {
  const current = props.item?.[field.key];
  if (field.key === 'base_salary') return salary(current);
  if (field.key === 'status') return props.item?.closed_at ? 'Закрыта' : 'Открыта';
  if (field.type === 'date') { const date = current ? new Date(current) : null; return date && !Number.isNaN(date.getTime()) ? date.toLocaleString('ru-RU') : '—'; }
  if (field.type === 'boolean') return current ? 'Да' : 'Нет';
  if (field.key === 'parent_id') return props.departments.find(d => String(d.id) === String(current))?.name || '—';
  if (field.key === 'direction_id') return props.departments.find(d => String(d.id) === String(current))?.name || '—';
  if (field.key === 'manager_id') return props.item?.manager_name || props.employees.find(e => String(e.id) === String(current))?.full_name || '—';
  if (field.key === 'hr_id') return props.item?.hr_name || props.employees.find(e => String(e.id) === String(current))?.full_name || '—';
  return current == null || current === '' ? '—' : current;
};
const startEdit = (field) => {
  if (!props.canManage || field.readonly || saving.value) return;
  editing.value = field.key;
  value.value = field.type === 'boolean' ? Boolean(props.item[field.key]) : String(props.item[field.key] ?? '');
  error.value = '';
};
const cancel = () => { editing.value = null; error.value = ''; };
const save = async (field) => {
  if (!props.canManage || field.readonly || saving.value) return;
  if (field.required && !String(value.value).trim()) { error.value = 'Заполните обязательное поле.'; return; }
  if (field.key === 'base_salary' && value.value !== '' && (!Number.isFinite(Number(value.value)) || Number(value.value) < 0)) { error.value = 'Оклад должен быть неотрицательным числом.'; return; }
  const draft = { ...props.item, [field.key]: value.value };
  let payload;
  if (isDepartment.value) {
    payload = { name: draft.name.trim(), alias: draft.alias || null, ldap_group: draft.ldap_group || null, is_production: Boolean(draft.is_production) };
    for (const key of ['parent_id', 'manager_id', 'hr_id', 'yandex_id']) payload[key] = draft[key] == null || draft[key] === '' ? null : Number(draft[key]);
  } else payload = { name: draft.name.trim(), direction_id: Number(draft.direction_id), base_salary: draft.base_salary == null || draft.base_salary === '' ? null : Number(draft.base_salary) };
  saving.value = true; error.value = '';
  try {
    const response = await auth.fetch(`/api/employees/${isDepartment.value ? 'departments' : 'staff-positions'}/${props.item.id}`, { method: 'PUT', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat()[0] : result.message || `HTTP ${response.status}`);
    editing.value = null;
    emit('updated');
  } catch (e) { error.value = e.message; }
  finally { saving.value = false; }
};
</script>

<template>
  <UiDrawer :open="Boolean(item)" :title="item?.name || ''" :width="isDepartment ? '40vw' : '30vw'" :min-width="isDepartment ? 320 : 240" :z-index="isDepartment ? 1000 : 1050" :inactive="saving || inactive" @close="emit('close')">
    <div v-if="item" class="organization-card irlix-ui" :data-testid="isDepartment ? 'department-card' : 'position-card'">
      <UiTabs v-if="isDepartment" :model-value="activeTab" :items="tabs" @update:model-value="changeTab" />
      <div v-if="error" class="alert" role="alert">{{ error }}</div>
      <div v-if="!isDepartment && canManage" class="position-card-actions"><UiButton compact variant="secondary" :disabled="Boolean(item.closed_at)" aria-label="Закрыть должность" @click="mutatePosition(item, 'close')">Закрыть должность</UiButton><UiButton compact variant="danger" aria-label="Удалить должность" @click="mutatePosition(item, 'delete')">Удалить должность</UiButton></div>
      <dl v-if="!isDepartment || activeTab === 'info'">
        <div v-for="field in fields" :key="field.key" class="organization-card-field">
          <dt>{{ field.label }}</dt>
          <dd v-if="editing !== field.key">{{ display(field) }}</dd>
          <form v-else class="organization-card-editor" @submit.prevent="save(field)">
            <UiSearchSelect v-if="field.options" v-model="value" :options="field.options" :aria-label="field.label" :disabled="saving" :placeholder="field.required ? 'Выберите подразделение' : 'Не назначен'" />
            <input v-else-if="field.type === 'boolean'" v-model="value" type="checkbox" :aria-label="field.label" :disabled="saving" />
            <input v-else v-model="value" :type="field.type || 'text'" :min="field.min" :step="field.step" :required="field.required" :aria-label="field.label" :disabled="saving" maxlength="255" />
            <UiButton compact type="submit" :disabled="saving" :aria-label="`Сохранить ${field.label}`">✓</UiButton>
            <UiButton compact type="button" variant="secondary" :disabled="saving" :aria-label="`Отменить ${field.label}`" @click="cancel">×</UiButton>
          </form>
          <UiButton v-if="canManage && !field.readonly && editing !== field.key" compact variant="secondary" :disabled="saving" :aria-label="`Редактировать ${field.label}`" @click="startEdit(field)">✎</UiButton>
        </div>
      </dl>
      <section v-else>
        <div v-if="canCreatePositions" class="department-positions-toolbar"><UiButton compact @click="emit('create')">+ Должность</UiButton></div>
        <div class="department-positions-scroll" data-testid="department-positions">
        <table v-if="ownPositions.length" class="department-positions-table">
          <thead><tr><th>Должность</th><th>Оклад</th><th>Сотрудники</th><th v-if="canManagePositions" aria-label="Действия"></th></tr></thead>
          <tbody><tr v-for="position in ownPositions" :key="position.id" :data-card-position-id="position.id">
            <td><button class="position-name" @click="emit('position', position)">{{ position.name }}</button></td>
            <td class="position-salary">{{ salary(position.base_salary) }}</td>
            <td><a :href="employeeHref(position)" @click.prevent="emit('employees', employeeFilters(position))">{{ position.employee_count ?? employees.filter(e => String(e.position_id) === String(position.id) && String(e.department_id) === String(item.id) && e.employment_status === 'Трудоустроен').length }}</a></td>
            <td v-if="canManagePositions" class="position-delete-action"><div class="position-actions"><UiButton compact variant="secondary" :disabled="Boolean(position.closed_at)" :aria-label="`Закрыть должность ${position.name}`" @click="mutatePosition(position, 'close')">Закрыть</UiButton><UiButton compact variant="danger" :aria-label="`Удалить должность ${position.name}`" @click="mutatePosition(position, 'delete')">Удалить</UiButton></div></td>
          </tr></tbody>
        </table>
        <p v-else>В подразделении пока нет должностей.</p>
        </div>
      </section>
    </div>
  </UiDrawer>
</template>

<style scoped>
.organization-card { min-width: 0; width: 100%; }
.organization-card dl { margin: 12px 0 0; }
.organization-card-field { display: grid; grid-template-columns: min(155px, 35%) minmax(0, 1fr) auto; gap: 6px; padding: 3px 0; min-height: 34px; align-items: center; }
.organization-card-field dt { color: var(--irlix-color-text-muted); font-size: 12px; }
.organization-card-field dd { margin: 0; overflow-wrap: anywhere; font-size: 13px; }
.organization-card-editor { grid-column: 2 / -1; display: flex; gap: 6px; align-items: center; min-width: 0; }
.organization-card-editor > input:not([type=checkbox]), .organization-card-editor > :deep(.ui-search-select) { flex: 1; min-width: 0; }
.department-positions-scroll { margin-top: 12px; overflow-y: auto; overflow-x: hidden; max-height: calc(100dvh - 190px); }
.department-positions-table { width: 100%; min-width: 0; table-layout: fixed; border-collapse: collapse; font-size: 13px; }
.department-positions-table th, .department-positions-table td { padding: 10px 6px; text-align: left; white-space: normal; overflow-wrap: anywhere; border-bottom: 1px solid var(--irlix-color-border); }
.department-positions-table th { position: sticky; top: 0; background: var(--irlix-color-surface, white); }
.department-positions-toolbar { display: flex; justify-content: flex-end; margin-top: 12px; }
.department-positions-table th:first-child { width: 30%; }
.department-positions-table th:nth-child(2) { width: 24%; }
.position-actions, .position-card-actions { display: flex; flex-wrap: wrap; gap: 4px; }
.position-card-actions { margin: 0 0 12px; }
.department-positions-table th:nth-child(3) { width: 20%; }
.department-positions-table .position-delete-action { padding-left: 2px; padding-right: 2px; }
.position-salary { font-variant-numeric: tabular-nums; }
.position-name { padding: 0; border: 0; background: transparent; color: inherit; text-align: left; font: inherit; cursor: pointer; }
.position-name:hover { text-decoration: underline; }
.department-positions-table a { color: var(--irlix-color-primary); }
@media (max-width: 480px) { .organization-card-field { grid-template-columns: min(115px, 35%) minmax(0, 1fr) auto; } }
</style>


