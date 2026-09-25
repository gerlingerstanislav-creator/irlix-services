<script setup>
import { onMounted, ref, watch } from 'vue';
import { UiButton, UiPageHeader, UiPanel } from '@irlix/ui';
import { api } from '../api';
import { auditLabels, formatDate, formatDateTime, typeLabels } from '../constants';
import AbsenceActions from '../components/AbsenceActions.vue';

const props = defineProps({ employees: { type: Array, default: () => [] }, refreshToken: { type: Number, default: 0 } });
const emit = defineEmits(['action', 'error']);
const items = ref([]);
const loading = ref(false);
const filters = ref({ employee_id: '', event: '', from: '', to: '' });

const load = async () => {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters.value)) if (value) params.set(key, value);
    const payload = await api(`/api/vacations/history${params.size ? `?${params}` : ''}`);
    items.value = payload.data || [];
  } catch (error) {
    emit('error', error.message);
  } finally {
    loading.value = false;
  }
};
const reset = () => { filters.value = { employee_id: '', event: '', from: '', to: '' }; load(); };

onMounted(load);
watch(() => props.refreshToken, load);
</script>

<template>
  <UiPageHeader eyebrow="VACATIONS / ABSENCES" title="История действий" description="Хронология изменений отпусков и действий участников процесса." />
  <UiPanel>
    <div class="filter-grid history-filters">
      <label v-if="employees.length">Сотрудник<select v-model="filters.employee_id"><option value="">Все доступные</option><option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.full_name }}</option></select></label>
      <label>Действие<select v-model="filters.event"><option value="">Все</option><option v-for="(label, key) in auditLabels" :key="key" :value="key">{{ label }}</option></select></label>
      <label>С<input v-model="filters.from" type="date" /></label>
      <label>По<input v-model="filters.to" type="date" /></label>
      <div class="filter-actions"><UiButton @click="load">Применить</UiButton><UiButton variant="secondary" @click="reset">Сбросить</UiButton></div>
    </div>
    <div v-if="loading" class="empty">Загрузка истории…</div>
    <div v-else-if="!items.length" class="empty"><strong>История пуста</strong><span>Для выбранных условий действий не найдено.</span></div>
    <div v-else class="table-wrap">
      <table class="irlix-data-table history-table">
        <thead><tr><th>Дата</th><th>Сотрудник</th><th>Отпуск</th><th>Действие</th><th>Автор</th><th></th></tr></thead>
        <tbody>
          <tr v-for="item in items" :key="item.id" class="clickable-row" @click="emit('action', { action: 'view', item: { id: item.absence_id } })">
            <td>{{ formatDateTime(item.created_at) }}</td>
            <td><strong>{{ item.employee_name || `#${item.employee_id}` }}</strong><small>{{ item.department_name || '—' }}</small></td>
            <td><strong>{{ typeLabels[item.type] || item.type }}</strong><small>{{ formatDate(item.starts_on) }} — {{ formatDate(item.ends_on) }}</small></td>
            <td>{{ auditLabels[item.event] || item.event }}</td>
            <td>{{ item.actor_name || item.actor_subject || 'Система' }}</td>
            <td class="actions-cell" @click.stop><AbsenceActions :actions="['view', 'history']" @action="action => emit('action', { action, item: { id: item.absence_id } })" /></td>
          </tr>
        </tbody>
      </table>
    </div>
  </UiPanel>
</template>
