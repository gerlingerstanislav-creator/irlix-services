<script setup>
import { computed, ref } from 'vue';
import { UiButton, UiPageHeader, UiPanel } from '@irlix/ui';

const props = defineProps({
  positions: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['updated']);

const editingId = ref(null);
const form = ref({ name: '', base_salary: '' });
const saving = ref(false);
const error = ref('');

const sortedPositions = computed(() => [...props.positions].sort((a, b) => String(a.name).localeCompare(String(b.name), 'ru')));
const money = (value) => value == null || value === '' ? '—' : new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(Number(value));

const resetForm = () => {
  editingId.value = null;
  form.value = { name: '', base_salary: '' };
  error.value = '';
};

const startCreate = () => resetForm();
const startEdit = (position) => {
  editingId.value = position.id;
  form.value = {
    name: position.name ?? '',
    base_salary: position.base_salary == null ? '' : String(position.base_salary),
  };
  error.value = '';
};

const submit = async () => {
  if (!props.canManage || saving.value) return;
  saving.value = true;
  error.value = '';
  try {
    const response = await fetch(editingId.value ? `/api/employees/staff-positions/${editingId.value}` : '/api/employees/staff-positions', {
      method: editingId.value ? 'PUT' : 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: form.value.name.trim(),
        base_salary: form.value.base_salary === '' ? null : Number(form.value.base_salary),
      }),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
    resetForm();
    emit('updated');
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
};
</script>

<template>
  <div>
    <UiPageHeader eyebrow="STAFFING" title="Штатное расписание" description="Справочник должностей, доступных для назначения сотрудникам, и их базовых окладов." />
    <div v-if="error" class="alert">{{ error }}</div>
    <UiPanel>
      <form v-if="canManage" class="staff-position-form" @submit.prevent="submit">
        <label class="irlix-field"><span>Должность</span><input v-model="form.name" required maxlength="255" placeholder="Например: Backend Developer" /></label>
        <label class="irlix-field"><span>Базовый оклад, ₽</span><input v-model="form.base_salary" type="number" min="0" step="0.01" placeholder="Не указан" /></label>
        <div class="staff-position-actions">
          <UiButton type="submit" :disabled="saving">{{ saving ? 'Сохранение…' : (editingId ? 'Сохранить' : '+ Добавить должность') }}</UiButton>
          <UiButton v-if="editingId" type="button" variant="secondary" @click="resetForm">Отмена</UiButton>
        </div>
      </form>

      <div v-if="!sortedPositions.length" class="empty-state"><strong>Штатное расписание пока пусто</strong></div>
      <div v-else class="table-wrap">
        <table class="irlix-data-table">
          <thead><tr><th>Должность</th><th>Базовый оклад</th><th /></tr></thead>
          <tbody>
            <tr v-for="position in sortedPositions" :key="position.id">
              <td><strong>{{ position.name }}</strong></td>
              <td>{{ money(position.base_salary) }}</td>
              <td><UiButton v-if="canManage" variant="secondary" compact @click="startEdit(position)">✎</UiButton></td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiPanel>
  </div>
</template>

<style scoped>
.staff-position-form { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(180px, 240px) auto; gap: 12px; align-items: end; margin-bottom: 16px; }
.staff-position-actions { display: flex; gap: 8px; align-items: center; }
@media (max-width: 760px) { .staff-position-form { grid-template-columns: 1fr; } }
</style>
