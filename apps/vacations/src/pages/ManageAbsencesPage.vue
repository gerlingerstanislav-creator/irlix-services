<script setup>
import { onMounted, ref, watch } from 'vue';
import { UiButton, UiPageHeader, UiPanel } from '@irlix/ui';
import { api } from '../api';
import { statusLabels, typeLabels } from '../constants';
import AbsenceTable from '../components/AbsenceTable.vue';

const props = defineProps({
  departments: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  refreshToken: { type: Number, default: 0 },
});
const emit = defineEmits(['action', 'error']);
const items = ref([]);
const loading = ref(false);
const filters = ref({ department_id: '', employee_id: '', type: '', status: '', from: '', to: '' });

const load = async () => {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters.value)) if (value) params.set(key, value);
    const payload = await api(`/api/vacations/registry${params.size ? `?${params}` : ''}`);
    items.value = payload.data || [];
  } catch (error) {
    emit('error', error.message);
  } finally {
    loading.value = false;
  }
};
const reset = () => { filters.value = { department_id: '', employee_id: '', type: '', status: '', from: '', to: '' }; load(); };

onMounted(load);
watch(() => props.refreshToken, load);
</script>

<template>
  <UiPageHeader eyebrow="VACATIONS / ABSENCES" title="Управление отпусками" description="Кадровый реестр отсутствий, документов и статусов согласования." />
  <UiPanel>
    <div class="filter-grid">
      <label>Сотрудник<select v-model="filters.employee_id"><option value="">Все</option><option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.full_name }}</option></select></label>
      <label>Подразделение<select v-model="filters.department_id"><option value="">Все</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></label>
      <label>Тип<select v-model="filters.type"><option value="">Все</option><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select></label>
      <label>Статус<select v-model="filters.status"><option value="">Все</option><option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option></select></label>
      <label>С<input v-model="filters.from" type="date" /></label>
      <label>По<input v-model="filters.to" type="date" /></label>
      <div class="filter-actions"><UiButton @click="load">Применить</UiButton><UiButton variant="secondary" @click="reset">Сбросить</UiButton></div>
    </div>
    <div class="registry-meta">Найдено: <strong>{{ items.length }}</strong></div>
    <div v-if="loading" class="empty">Загрузка реестра…</div>
    <div v-else-if="!items.length" class="empty"><strong>Ничего не найдено</strong><span>Измените параметры фильтра.</span></div>
    <AbsenceTable v-else :items="items" show-employee @action="emit('action', $event)" />
  </UiPanel>
</template>
