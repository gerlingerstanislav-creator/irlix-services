<script setup>
import { computed, ref } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiPanel, UiSearchSelect, UiTreeToggle } from '@irlix/ui';
import { auth } from '../auth';
import { buildStaffTree, departmentOptions } from '../staffTree';

const props = defineProps({
  positions: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['updated']);

const editingId = ref(null);
const form = ref({ name: '', base_salary: '', direction_id: '' });
const saving = ref(false);
const error = ref('');
const showForm = ref(false);

const collapsed = ref(new Set());
const rows = computed(() => buildStaffTree(props.departments, props.positions, collapsed.value));
const options = computed(() => departmentOptions(props.departments));
const toggle = (id) => {
  const next = new Set(collapsed.value);
  if (next.has(String(id))) next.delete(String(id)); else next.add(String(id));
  collapsed.value = next;
};
const money = (value) => value == null || value === '' ? '—' : new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(Number(value));

const resetForm = () => {
  editingId.value = null;
  form.value = { name: '', base_salary: '', direction_id: '' };
  error.value = '';
  showForm.value = false;
};

const openCreate = (departmentId = '') => {
  if (!props.canManage) return;
  editingId.value = null;
  form.value = { name: '', base_salary: '', direction_id: departmentId ? String(departmentId) : '' };
  error.value = '';
  showForm.value = true;
};

const startEdit = (position) => {
  if (!props.canManage) return;
  editingId.value = position.id;
  form.value = {
    name: position.name ?? '',
    base_salary: position.base_salary == null ? '' : String(position.base_salary),
    direction_id: position.direction_id == null ? '' : String(position.direction_id),
  };
  error.value = '';
  showForm.value = true;
};

defineExpose({ openCreate });

const request = async (url, options = {}) => {
  const response = await auth.fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers ?? {}) } });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
  return payload;
};

const submit = async () => {
  if (!props.canManage || saving.value) return;
  if (!form.value.direction_id || !props.departments.some(d => String(d.id) === String(form.value.direction_id))) {
    error.value = 'Выберите подразделение для должности.';
    return;
  }
  saving.value = true;
  error.value = '';
  try {
    await request(editingId.value ? `/api/employees/staff-positions/${editingId.value}` : '/api/employees/staff-positions', {
      method: editingId.value ? 'PUT' : 'POST',
      body: JSON.stringify({
        name: form.value.name.trim(),
        base_salary: form.value.base_salary === '' ? null : Number(form.value.base_salary),
        direction_id: Number(form.value.direction_id),
      }),
    });
    resetForm();
    emit('updated');
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
};

const closePosition = async (position) => {
  if (!props.canManage || position.closed_at) return;
  if (!window.confirm(`Закрыть должность «${position.name}»? Текущая история сотрудников сохранится, но назначать эту должность новым сотрудникам будет нельзя.`)) return;
  error.value = '';
  try {
    await request(`/api/employees/staff-positions/${position.id}/close`, { method: 'POST' });
    if (editingId.value === position.id) resetForm();
    emit('updated');
  } catch (e) { error.value = e.message; }
};

const deletePosition = async (position) => {
  if (!props.canManage) return;
  if (!window.confirm(`Полностью удалить должность «${position.name}»? Это необратимое действие. Удаление возможно только если должность не использовалась сотрудниками.`)) return;
  error.value = '';
  try {
    await request(`/api/employees/staff-positions/${position.id}`, { method: 'DELETE' });
    if (editingId.value === position.id) resetForm();
    emit('updated');
  } catch (e) { error.value = e.message; }
};
</script>

<template>
  <div class="staff-positions-page">
    <div v-if="error && !showForm" class="alert">{{ error }}</div>
    <UiPanel class="staff-positions-panel">
      <div v-if="!departments.length" class="empty-state"><strong>Оргструктура пока пуста</strong><span>Сначала добавьте подразделение.</span></div>
      <div v-else class="staff-tree-scroll" data-testid="staff-tree-scroll">
        <table class="irlix-data-table staff-tree-table">
          <thead><tr><th>Подразделение / должность</th><th>Базовый оклад</th><th>Статус</th><th /></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.key" :class="{ 'staff-department-row': row.kind === 'department' }" :data-department-id="row.kind === 'department' ? row.item.id : undefined" :data-position-id="row.kind === 'position' ? row.item.id : undefined">
              <td>
                <div class="staff-tree-label" :style="{ paddingLeft: `${row.depth * 20}px` }">
                  <template v-if="row.kind === 'department'">
                    <UiTreeToggle v-if="row.hasChildren" :expanded="!collapsed.has(String(row.item.id))" :label="`${collapsed.has(String(row.item.id)) ? 'Развернуть' : 'Свернуть'} ${row.item.name}`" @click="toggle(row.item.id)" />
                    <span v-else class="staff-tree-spacer" />
                    <strong>{{ row.item.name }}</strong>
                  </template>
                  <template v-else><span class="staff-tree-spacer" /><span>{{ row.item.name }}</span></template>
                </div>
              </td>
              <td>{{ row.kind === 'position' ? money(row.item.base_salary) : '' }}</td>
              <td><UiBadge v-if="row.kind === 'position'" :tone="row.item.closed_at ? 'neutral' : 'success'">{{ row.item.closed_at ? 'Закрыта' : 'Открыта' }}</UiBadge></td>
              <td class="staff-position-row-actions">
                <UiButton v-if="canManage && row.kind === 'department' && row.item.id !== 'unlinked'" variant="secondary" compact @click="openCreate(row.item.id)">+ Должность</UiButton>
                <template v-if="canManage && row.kind === 'position'">
                  <UiButton variant="secondary" compact :aria-label="`Редактировать ${row.item.name}`" @click="startEdit(row.item)">✎</UiButton>
                  <UiButton v-if="!row.item.closed_at" variant="secondary" compact @click="closePosition(row.item)">Закрыть</UiButton>
                  <UiButton variant="danger" compact @click="deletePosition(row.item)">Удалить</UiButton>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiPanel>
    <UiDrawer :open="canManage && showForm" :title="editingId ? 'Редактирование должности' : 'Новая должность'" width="480px" :inactive="saving" @close="resetForm">
      <form class="staff-position-form irlix-ui" @submit.prevent="submit">
        <div v-if="error" class="alert" role="alert">{{ error }}</div>
        <label class="irlix-field"><span>Подразделение *</span><UiSearchSelect v-model="form.direction_id" :options="options" placeholder="Выберите подразделение" search-placeholder="Поиск подразделения" /></label>
        <label class="irlix-field"><span>Должность *</span><input v-model="form.name" required maxlength="255" placeholder="Название должности" /></label>
        <label class="irlix-field"><span>Базовый оклад, ₽</span><input v-model="form.base_salary" type="number" min="0" step="0.01" placeholder="Не указан" /></label>
        <div class="staff-position-actions">
          <UiButton type="button" variant="secondary" @click="resetForm">Отмена</UiButton>
          <UiButton type="submit" :disabled="saving || !form.direction_id">{{ saving ? 'Сохранение…' : (editingId ? 'Сохранить' : 'Добавить') }}</UiButton>
        </div>
      </form>
    </UiDrawer>
  </div>
</template>

<style scoped>
.staff-positions-page { min-height: 0; flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.staff-positions-panel { min-height: 0; flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.staff-tree-scroll { min-height: 0; flex: 1; overflow: auto; }
.staff-tree-table { min-width: 650px; }
.staff-tree-label { display: flex; align-items: center; gap: 6px; }
.staff-tree-spacer { width: 22px; flex: none; }
.staff-department-row { background: var(--irlix-color-surface-muted); }
.staff-position-form { display: flex; flex-direction: column; gap: 16px; }
.staff-position-actions, .staff-position-row-actions { display: flex; gap: 8px; align-items: center; justify-content: flex-end; }
.staff-position-row-actions { min-height: 46px; }
@media (max-width: 760px) { .staff-position-row-actions { flex-wrap: wrap; } .staff-tree-scroll { max-height: calc(100dvh - 150px); } }
</style>
