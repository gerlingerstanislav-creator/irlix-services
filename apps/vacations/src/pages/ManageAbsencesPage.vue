<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiButton, UiPageHeader, UiPanel, UiSearchSelect } from '@irlix/ui';
import { api } from '../api';
import { statusLabels, typeLabels } from '../constants';
import AbsenceCreateModal from '../components/AbsenceCreateModal.vue';
import AbsenceTable from '../components/AbsenceTable.vue';

const props = defineProps({
  departments: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  canCreateForEmployee: { type: Boolean, default: false },
  refreshToken: { type: Number, default: 0 },
});
const emit = defineEmits(['action', 'error', 'changed']);

const year = ref(new Date().getFullYear());
const items = ref([]);
const loading = ref(false);
const search = ref('');
const statuses = ref([]);
const departmentId = ref('');
const type = ref('');
const activeMonth = ref(null);
const onlyMyActions = ref(false);
const showCreate = ref(false);

const yearRange = computed(() => ({ from: `${year.value}-01-01`, to: `${year.value}-12-31` }));
const yearOptions = computed(() => [year.value - 1, year.value, year.value + 1].map((value) => ({ value, label: `${value} год` })));
const statusOptions = computed(() => Object.entries(statusLabels).map(([value, label]) => ({ value, label })));
const departmentOptions = computed(() => props.departments.map((department) => ({ value: department.id, label: department.name })));
const typeOptions = computed(() => Object.entries(typeLabels).map(([value, label]) => ({ value, label })));
const departmentEmployeeIds = computed(() => {
  const needle = search.value.trim().toLowerCase();
  return new Set(props.employees
    .filter((employee) => !departmentId.value || String(employee.department_id ?? '') === String(departmentId.value))
    .filter((employee) => !needle || [employee.full_name, employee.department_name, employee.position]
      .filter(Boolean).some((value) => String(value).toLowerCase().includes(needle)))
    .map((employee) => Number(employee.id)));
});

const baseFiltered = computed(() => items.value.filter((item) => {
  if (!departmentEmployeeIds.value.has(Number(item.employee_id))) return false;
  if (statuses.value.length && !statuses.value.includes(item.status)) return false;
  if (type.value && item.type !== type.value) return false;
  return true;
}));

const monthBounds = (monthIndex) => {
  const mm = String(monthIndex).padStart(2, '0');
  const last = new Date(year.value, monthIndex, 0).getDate();
  return { from: `${year.value}-${mm}-01`, to: `${year.value}-${mm}-${String(last).padStart(2, '0')}` };
};
const overlaps = (item, from, to) => item.starts_on <= to && (!item.ends_on || item.ends_on >= from);
const parseDate = (value) => {
  const [y, m, d] = String(value).slice(0, 10).split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d));
};
const workingDaysBetween = (from, to) => {
  let cursor = parseDate(from);
  const end = parseDate(to);
  let count = 0;
  while (cursor <= end) {
    const day = cursor.getUTCDay();
    if (day !== 0 && day !== 6) count += 1;
    cursor = new Date(cursor.getTime() + 86400000);
  }
  return count;
};
const absenceHoursInMonth = (item, monthIndex) => {
  if (['rejected', 'cancelled'].includes(item.status)) return 0;
  const bounds = monthBounds(monthIndex);
  if (!overlaps(item, bounds.from, bounds.to)) return 0;
  const from = item.starts_on > bounds.from ? item.starts_on : bounds.from;
  const endValue = item.ends_on || bounds.to;
  const to = endValue < bounds.to ? endValue : bounds.to;
  return workingDaysBetween(from, to) * 8;
};
const workingDaysInMonth = (monthIndex) => {
  const bounds = monthBounds(monthIndex);
  return workingDaysBetween(bounds.from, bounds.to);
};

const monthCards = computed(() => Array.from({ length: 12 }, (_, index) => {
  const monthIndex = index + 1;
  const totalHours = departmentEmployeeIds.value.size * workingDaysInMonth(monthIndex) * 8;
  const absenceHours = baseFiltered.value.reduce((sum, item) => sum + absenceHoursInMonth(item, monthIndex), 0);
  return {
    month: monthIndex,
    label: `${String(monthIndex).padStart(2, '0')}.${year.value}`,
    absenceHours,
    totalHours,
    percent: totalHours > 0 ? Math.round((absenceHours / totalHours) * 100) : 0,
  };
}));

const filteredItems = computed(() => {
  let result = baseFiltered.value;
  if (activeMonth.value) {
    const bounds = monthBounds(activeMonth.value);
    result = result.filter((item) => overlaps(item, bounds.from, bounds.to));
  }
  if (onlyMyActions.value) result = result.filter((item) => Boolean(item.requires_my_action));
  return result;
});
const myActionCount = computed(() => baseFiltered.value.filter((item) => item.requires_my_action).length);

const load = async () => {
  loading.value = true;
  try {
    const params = new URLSearchParams({ from: yearRange.value.from, to: yearRange.value.to });
    const payload = await api(`/api/vacations/registry?${params}`);
    items.value = payload.data || [];
  } catch (error) {
    emit('error', error.message);
  } finally {
    loading.value = false;
  }
};

const selectMonth = (month) => { activeMonth.value = activeMonth.value === month ? null : month; };
const clearFilters = () => {
  search.value = '';
  statuses.value = [];
  departmentId.value = '';
  type.value = '';
  activeMonth.value = null;
  onlyMyActions.value = false;
};
const created = async () => {
  showCreate.value = false;
  await load();
  emit('changed');
};

onMounted(load);
watch(() => props.refreshToken, load);
watch(year, () => { activeMonth.value = null; load(); });
</script>

<template>
  <UiPageHeader eyebrow="VACATIONS / ABSENCES" title="Управление отпусками" description="Реестр, контроль загрузки и согласование отпусков в одном рабочем окне.">
    <template #actions>
      <div class="manage-header-actions">
        <UiSearchSelect v-model="year" class="year-select" :options="yearOptions" :clearable="false" aria-label="Год" search-placeholder="Поиск года" />
        <UiButton v-if="canCreateForEmployee" @click="showCreate = true">+ Создать отпуск</UiButton>
      </div>
    </template>
  </UiPageHeader>

  <UiPanel class="manage-panel">
    <div class="manage-controls">
      <input v-model="search" class="manage-search" type="search" placeholder="Поиск" aria-label="Поиск" />
      <UiSearchSelect v-model="statuses" class="manage-filter manage-filter-status" :options="statusOptions" placeholder="Статусы" search-placeholder="Поиск статуса" aria-label="Статусы" multiple />
      <UiSearchSelect v-model="departmentId" class="manage-filter manage-filter-department" :options="departmentOptions" placeholder="Подразделения" search-placeholder="Поиск подразделения" aria-label="Подразделения" />
      <UiSearchSelect v-model="type" class="manage-filter manage-filter-type" :options="typeOptions" placeholder="Тип отпуска" search-placeholder="Поиск типа" aria-label="Тип отпуска" />
      <button type="button" class="my-actions-filter" :class="{ active: onlyMyActions }" @click="onlyMyActions = !onlyMyActions">
        Требуют моего действия <span>{{ myActionCount }}</span>
      </button>
      <button type="button" class="reset-filter" @click="clearFilters">Сбросить</button>
    </div>

    <div class="month-cards" aria-label="Быстрый фильтр по месяцам">
      <button v-for="card in monthCards" :key="card.month" type="button" class="month-card" :class="{ active: activeMonth === card.month }" @click="selectMonth(card.month)">
        <span>{{ card.label }}</span>
        <strong>{{ card.percent }}%</strong>
        <small><b>Отпуска:</b><em>{{ card.absenceHours }}ч</em></small>
        <small><b>Всего:</b><em>{{ card.totalHours }}ч</em></small>
      </button>
    </div>

    <div class="registry-meta">
      <span>Найдено: <strong>{{ filteredItems.length }}</strong></span>
      <span v-if="activeMonth">Быстрый фильтр: <strong>{{ String(activeMonth).padStart(2, '0') }}.{{ year }}</strong></span>
    </div>

    <div v-if="loading" class="empty">Загрузка реестра…</div>
    <div v-else-if="!filteredItems.length" class="empty"><strong>Ничего не найдено</strong><span>Измените фильтры или выберите другой месяц.</span></div>
    <AbsenceTable v-else :items="filteredItems" show-employee show-progress @action="emit('action', $event)" />
  </UiPanel>

  <AbsenceCreateModal
    :open="showCreate"
    :employees="employees"
    for-employee
    title="Создать отсутствие сотруднику"
    eyebrow="НОВОЕ ОТСУТСТВИЕ"
    @close="showCreate = false"
    @changed="created"
  />
</template>
