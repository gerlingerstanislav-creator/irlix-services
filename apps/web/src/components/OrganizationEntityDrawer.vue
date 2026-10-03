<script setup>
import { computed, ref, watch } from 'vue';
import { UiButton, UiDrawer, UiSearchSelect } from '@irlix/ui';
import { auth } from '../auth';
import { departmentOptions } from '../staffTree';

const props = defineProps({
  item: { type: Object, default: null },
  kind: { type: String, required: true },
  departments: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'updated']);
const editing = ref(null);
const value = ref('');
const saving = ref(false);
const error = ref('');
watch(() => [props.kind, props.item?.id], () => { editing.value = null; error.value = ''; });
const isDepartment = computed(() => props.kind === 'department');
const parentOptions = computed(() => {
  const excluded = new Set([String(props.item?.id)]);
  let changed = true;
  while (changed) {
    changed = false;
    for (const d of props.departments) if (excluded.has(String(d.parent_id)) && !excluded.has(String(d.id))) { excluded.add(String(d.id)); changed = true; }
  }
  return departmentOptions(props.departments).filter(o => !excluded.has(String(o.value)));
});
const peopleOptions = computed(() => props.employees.filter(e => e.employment_status === 'Трудоустроен').map(e => ({ value: String(e.id), label: e.full_name })));
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
]);
const display = (field) => {
  const current = props.item?.[field.key];
  if (field.type === 'boolean') return current ? 'Да' : 'Нет';
  if (field.key === 'parent_id') return props.departments.find(d => String(d.id) === String(current))?.name || '—';
  if (field.key === 'direction_id') return props.departments.find(d => String(d.id) === String(current))?.name || '—';
  if (field.key === 'manager_id') return props.item?.manager_name || props.employees.find(e => String(e.id) === String(current))?.full_name || '—';
  if (field.key === 'hr_id') return props.item?.hr_name || props.employees.find(e => String(e.id) === String(current))?.full_name || '—';
  return current == null || current === '' ? '—' : current;
};
const startEdit = (field) => {
  if (!props.canManage || saving.value) return;
  editing.value = field.key;
  value.value = field.type === 'boolean' ? Boolean(props.item[field.key]) : String(props.item[field.key] ?? '');
  error.value = '';
};
const cancel = () => { editing.value = null; error.value = ''; };
const save = async (field) => {
  if (!props.canManage || saving.value) return;
  if (field.required && !String(value.value).trim()) { error.value = 'Заполните обязательное поле.'; return; }
  const draft = { ...props.item, [field.key]: value.value };
  let payload;
  if (isDepartment.value) {
    payload = { name: draft.name.trim(), alias: draft.alias || null, ldap_group: draft.ldap_group || null, is_production: Boolean(draft.is_production) };
    for (const key of ['parent_id', 'manager_id', 'hr_id', 'yandex_id']) payload[key] = draft[key] == null || draft[key] === '' ? null : Number(draft[key]);
  } else payload = { name: draft.name.trim(), direction_id: Number(draft.direction_id), base_salary: draft.base_salary ?? null };
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
  <UiDrawer :open="Boolean(item)" :title="isDepartment ? 'Подразделение' : 'Должность'" width="540px" :inactive="saving" @close="emit('close')">
    <div v-if="item" class="organization-card irlix-ui" :data-testid="isDepartment ? 'department-card' : 'position-card'">
      <h2>{{ item.name }}</h2>
      <div v-if="error" class="alert" role="alert">{{ error }}</div>
      <dl>
        <div v-for="field in fields" :key="field.key" class="organization-card-field">
          <dt>{{ field.label }}</dt>
          <dd v-if="editing !== field.key">{{ display(field) }}</dd>
          <form v-else class="organization-card-editor" @submit.prevent="save(field)">
            <UiSearchSelect v-if="field.options" v-model="value" :options="field.options" :disabled="saving" :placeholder="field.required ? 'Выберите подразделение' : 'Не назначен'" />
            <input v-else-if="field.type === 'boolean'" v-model="value" type="checkbox" :aria-label="field.label" :disabled="saving" />
            <input v-else v-model="value" :type="field.type || 'text'" :required="field.required" :aria-label="field.label" :disabled="saving" maxlength="255" />
            <UiButton compact type="submit" :disabled="saving" :aria-label="`Сохранить ${field.label}`">✓</UiButton>
            <UiButton compact type="button" variant="secondary" :disabled="saving" :aria-label="`Отменить ${field.label}`" @click="cancel">×</UiButton>
          </form>
          <UiButton v-if="canManage && editing !== field.key" compact variant="secondary" :disabled="saving" :aria-label="`Редактировать ${field.label}`" @click="startEdit(field)">✎</UiButton>
        </div>
      </dl>
    </div>
  </UiDrawer>
</template>

<style scoped>
.organization-card h2 { margin: 0 0 20px; font-size: 18px; }
.organization-card dl { margin: 0; }
.organization-card-field { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 5px 10px; padding: 12px 0; border-bottom: 1px solid var(--irlix-color-border); }
.organization-card-field dt { grid-column: 1 / -1; color: var(--irlix-color-text-muted); font-size: 12px; }
.organization-card-field dd { margin: 0; overflow-wrap: anywhere; align-self: center; }
.organization-card-editor { grid-column: 1 / -1; display: flex; gap: 6px; align-items: center; min-width: 0; }
.organization-card-editor > input:not([type=checkbox]), .organization-card-editor > :deep(.ui-search-select) { flex: 1; min-width: 0; }
</style>
