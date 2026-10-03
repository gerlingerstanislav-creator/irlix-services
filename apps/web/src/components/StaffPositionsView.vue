<script setup>
import { computed, ref } from 'vue';
import { UiBadge, UiButton, UiPanel } from '@irlix/ui';
import { auth } from '../auth';

const props = defineProps({
  positions: { type: Array, default: () => [] },
  directions: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['updated']);

const editingId = ref(null);
const form = ref({ name: '', base_salary: '', direction_id: '' });
const saving = ref(false);
const error = ref('');
const showForm = ref(false);

const sortedPositions = computed(() => [...props.positions].sort((a, b) => {
  const closedDiff = Number(Boolean(a.closed_at)) - Number(Boolean(b.closed_at));
  if (closedDiff) return closedDiff;
  const directionDiff = String(a.direction_name || '').localeCompare(String(b.direction_name || ''), 'ru');
  return directionDiff || String(a.name).localeCompare(String(b.name), 'ru');
}));
const money = (value) => value == null || value === '' ? '—' : new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(Number(value));

const resetForm = () => {
  editingId.value = null;
  form.value = { name: '', base_salary: '', direction_id: '' };
  error.value = '';
  showForm.value = false;
};

const openCreate = () => {
  if (!props.canManage) return;
  editingId.value = null;
  form.value = { name: '', base_salary: '', direction_id: '' };
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
    <div v-if="error" class="alert">{{ error }}</div>
    <UiPanel class="staff-positions-panel">
      <form v-if="canManage && showForm" class="staff-position-form" @submit.prevent="submit">
        <label class="irlix-field"><span>Направление</span><select v-model="form.direction_id" required><option value="" disabled>Выберите направление</option><option v-for="direction in directions" :key="direction.id" :value="direction.id">{{ direction.name }}</option></select></label>
        <label class="irlix-field"><span>Должность</span><input v-model="form.name" required maxlength="255" placeholder="Например: Backend Developer" /></label>
        <label class="irlix-field"><span>Базовый оклад, ₽</span><input v-model="form.base_salary" type="number" min="0" step="0.01" placeholder="Не указан" /></label>
        <div class="staff-position-actions">
          <UiButton type="submit" :disabled="saving || !form.direction_id">{{ saving ? 'Сохранение…' : (editingId ? 'Сохранить' : 'Добавить') }}</UiButton>
          <UiButton type="button" variant="secondary" @click="resetForm">Отмена</UiButton>
        </div>
      </form>

      <div v-if="!directions.length && canManage" class="staff-position-note">Для создания должности сначала отметьте нужное подразделение как производственное направление в разделе «Подразделения».</div>
      <div v-if="!sortedPositions.length" class="empty-state"><strong>Штатное расписание пока пусто</strong></div>
      <div v-else class="table-wrap">
        <table class="irlix-data-table">
          <thead><tr><th>Направление</th><th>Должность</th><th>Базовый оклад</th><th>Статус</th><th /></tr></thead>
          <tbody>
            <tr v-for="position in sortedPositions" :key="position.id">
              <td>{{ position.direction_name || 'Не назначено' }}</td>
              <td><strong>{{ position.name }}</strong></td>
              <td>{{ money(position.base_salary) }}</td>
              <td><UiBadge :tone="position.closed_at ? 'neutral' : 'success'">{{ position.closed_at ? 'Закрыта' : 'Открыта' }}</UiBadge></td>
              <td class="staff-position-row-actions">
                <UiButton v-if="canManage" variant="secondary" compact @click="startEdit(position)">✎</UiButton>
                <UiButton v-if="canManage && !position.closed_at" variant="secondary" compact @click="closePosition(position)">Закрыть</UiButton>
                <UiButton v-if="canManage" variant="danger" compact @click="deletePosition(position)">Удалить</UiButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiPanel>
  </div>
</template>

<style scoped>
.staff-positions-page { min-height: 0; flex: 1; display: flex; flex-direction: column; }
.staff-positions-panel { min-height: 0; flex: 1; }
.staff-position-form { display: grid; grid-template-columns: minmax(180px, .8fr) minmax(260px, 1fr) minmax(180px, 220px) auto; gap: 12px; align-items: end; padding: 12px; border-bottom: 1px solid #edf0f2; }
.staff-position-actions, .staff-position-row-actions { display: flex; gap: 8px; align-items: center; justify-content: flex-end; }
.staff-position-note { margin: 12px; padding: 9px 11px; border-radius: 7px; background: #f5f7f8; color: #657080; font-size: 12px; }
@media (max-width: 960px) { .staff-position-form { grid-template-columns: 1fr 1fr; } }
@media (max-width: 760px) { .staff-position-form { grid-template-columns: 1fr; } .staff-position-row-actions { flex-wrap: wrap; } }
</style>
