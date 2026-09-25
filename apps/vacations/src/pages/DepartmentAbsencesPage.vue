<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiPageHeader, UiPanel } from '@irlix/ui';
import { api } from '../api';
import { formatDate, typeLabels } from '../constants';
import AbsenceActions from '../components/AbsenceActions.vue';
import AbsenceTable from '../components/AbsenceTable.vue';

const props = defineProps({
  departments: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  refreshToken: { type: Number, default: 0 },
});
const emit = defineEmits(['action', 'error']);
const now = new Date();
const month = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);
const departmentId = ref('');
const view = ref('calendar');
const items = ref([]);
const loading = ref(false);

const days = computed(() => {
  const [year, monthNumber] = month.value.split('-').map(Number);
  const count = new Date(year, monthNumber, 0).getDate();
  return Array.from({ length: count }, (_, index) => index + 1);
});
const monthRange = computed(() => {
  const [year, monthNumber] = month.value.split('-').map(Number);
  const end = new Date(year, monthNumber, 0).getDate();
  return { from: `${month.value}-01`, to: `${month.value}-${String(end).padStart(2, '0')}` };
});
const grouped = computed(() => {
  const map = new Map();
  for (const absence of items.value) {
    const key = Number(absence.employee_id);
    if (!map.has(key)) map.set(key, { employee_id: key, employee_name: absence.employee_name, department_name: absence.department_name, absences: [] });
    map.get(key).absences.push(absence);
  }
  return [...map.values()].sort((a, b) => String(a.employee_name || '').localeCompare(String(b.employee_name || ''), 'ru'));
});

const load = async () => {
  loading.value = true;
  try {
    const params = new URLSearchParams({ from: monthRange.value.from, to: monthRange.value.to });
    if (departmentId.value) params.set('department_id', departmentId.value);
    const payload = await api(`/api/vacations/registry?${params}`);
    items.value = payload.data || [];
  } catch (error) {
    emit('error', error.message);
  } finally {
    loading.value = false;
  }
};

const activeOnDay = (absence, day) => {
  const date = `${month.value}-${String(day).padStart(2, '0')}`;
  return absence.starts_on <= date && (!absence.ends_on || absence.ends_on >= date);
};
const dayItems = (group, day) => group.absences.filter((absence) => activeOnDay(absence, day));

onMounted(load);
watch(() => props.refreshToken, load);
watch([month, departmentId], load);
</script>

<template>
  <UiPageHeader eyebrow="VACATIONS / ABSENCES" title="Отпуска подразделения" description="Календарь пересечений и контроль отсутствий в вашей организационной зоне." />
  <UiPanel>
    <div class="toolbar department-toolbar">
      <div class="filters-inline">
        <label>Месяц<input v-model="month" type="month" /></label>
        <label>Подразделение
          <select v-model="departmentId"><option value="">Все доступные</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select>
        </label>
      </div>
      <div class="segmented"><button :class="{ active: view === 'calendar' }" @click="view = 'calendar'">Календарь</button><button :class="{ active: view === 'list' }" @click="view = 'list'">Список</button></div>
    </div>
    <div v-if="loading" class="empty">Загрузка отпусков подразделения…</div>
    <div v-else-if="!items.length" class="empty"><strong>Нет отсутствий за выбранный период</strong><span>Измените месяц или подразделение.</span></div>
    <AbsenceTable v-else-if="view === 'list'" :items="items" show-employee @action="emit('action', $event)" />
    <div v-else class="calendar-wrap">
      <table class="absence-calendar">
        <thead><tr><th class="calendar-person">Сотрудник</th><th v-for="day in days" :key="day">{{ day }}</th></tr></thead>
        <tbody>
          <tr v-for="group in grouped" :key="group.employee_id">
            <td class="calendar-person"><strong>{{ group.employee_name || `#${group.employee_id}` }}</strong><small>{{ group.department_name || '—' }}</small></td>
            <td v-for="day in days" :key="day" :class="{ occupied: dayItems(group, day).length }">
              <div v-for="absence in dayItems(group, day)" :key="absence.id" class="calendar-absence" :title="`${typeLabels[absence.type] || absence.type}: ${formatDate(absence.starts_on)} — ${formatDate(absence.ends_on)}`" @click="emit('action', { action: 'view', item: absence })">
                <span></span>
                <AbsenceActions v-if="day === Number(String(absence.starts_on).slice(-2)) || day === 1" :actions="absence.available_actions || []" @action="action => emit('action', { action, item: absence })" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </UiPanel>
</template>
