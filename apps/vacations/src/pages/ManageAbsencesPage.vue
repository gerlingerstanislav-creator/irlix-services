<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiButton, UiPageHeader, UiPanel } from '@irlix/ui';
import { api } from '../api';
import { statusLabels, typeLabels } from '../constants';
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
const status = ref('');
const departmentId = ref('');
const type = ref('');
const activeMonth = ref(null);
const onlyMyActions = ref(false);
const showCreate = ref(false);
const saving = ref(false);
const form = ref(emptyForm());

function emptyForm() {
  return { employee_id: '', type: 'paid_vacation', starts_on: '', ends_on: '', comment: '' };
}

const yearRange = computed(() => ({ from: `${year.value}-01-01`, to: `${year.value}-12-31` }));
const employeeMap = computed(() => new Map(props.employees.map((employee) => [Number(employee.id), employee])));
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
  if (status.value && item.status !== status.value) return false;
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
const formatIso = (date) => `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
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
const employeesForCreate = computed(() => [...props.employees].sort((a, b) => String(a.full_name || '').localeCompare(String(b.full_name || ''), 'ru')));

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
  status.value = '';
  departmentId.value = '';
  type.value = '';
  activeMonth.value = null;
  onlyMyActions.value = false;
};
const openCreate = () => { form.value = emptyForm(); showCreate.value = true; };
const save = async () => {
  saving.value = true;
  try {
    await api('/api/vacations/absences/for-employee', {
      method: 'POST',
      body: {
        employee_id: Number(form.value.employee_id),
        type: form.value.type,
        starts_on: form.value.starts_on,
        ends_on: form.value.ends_on || null,
        comment: form.value.comment || null,
      },
    });
    showCreate.value = false;
    form.value = emptyForm();
    await load();
    emit('changed');
  } catch (error) {
    emit('error', error.message);
  } finally {
    saving.value = false;
  }
};

onMounted(load);
watch(() => props.refreshToken, load);
watch(year, () => { activeMonth.value = null; load(); });
</script>

<template>
  <UiPageHeader eyebrow="VACATIONS / ABSENCES" title="Управление отпусками" description="Реестр, контроль загрузки и согласование отпусков в одном рабочем окне.">
    <template #actions>
      <div class="manage-header-actions">
        <select v-model="year" class="year-select" aria-label="Год"><option v-for="value in [year - 1, year, year + 1]" :key="value" :value="value">{{ value }} год</option></select>
        <UiButton v-if="canCreateForEmployee" @click="openCreate">+ Создать отпуск</UiButton>
      </div>
    </template>
  </UiPageHeader>

  <UiPanel class="manage-panel">
    <div class="manage-controls">
      <input v-model="search" type="search" placeholder="Поиск" aria-label="Поиск" />
      <select v-model="status" aria-label="Статусы"><option value="">Статусы</option><option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option></select>
      <select v-model="departmentId" aria-label="Подразделения"><option value="">Подразделения</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select>
      <select v-model="type" aria-label="Тип отпуска"><option value="">Тип отпуска</option><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select>
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

  <div v-if="showCreate" class="overlay" @click.self="showCreate = false">
    <form class="modal" @submit.prevent="save">
      <div class="modal-head"><div><div class="eyebrow">НОВЫЙ ОТПУСК</div><h2>Создать отпуск сотруднику</h2></div><button class="close" type="button" @click="showCreate = false">×</button></div>
      <label class="irlix-field">Сотрудник
        <select v-model="form.employee_id" required><option value="" disabled>Выберите сотрудника</option><option v-for="employee in employeesForCreate" :key="employee.id" :value="employee.id">{{ employee.full_name }} · {{ employee.department_name || 'Без подразделения' }}</option></select>
      </label>
      <label class="irlix-field">Тип<select v-model="form.type"><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select></label>
      <div class="date-grid">
        <label class="irlix-field">С<input v-model="form.starts_on" type="date" required /></label>
        <label class="irlix-field">По <small v-if="form.type === 'maternity_leave'">(можно оставить пустым)</small><input v-model="form.ends_on" type="date" :required="form.type !== 'maternity_leave'" /></label>
      </div>
      <label class="irlix-field">Комментарий<textarea v-model="form.comment" rows="4" maxlength="2000" placeholder="Необязательно" /></label>
      <p class="hint">Отсутствие создаётся в статусе «Запланировано». Дальнейшие действия выполняются через меню ⋮ в реестре.</p>
      <div class="actions"><UiButton type="button" variant="secondary" @click="showCreate = false">Отмена</UiButton><UiButton type="submit" :disabled="saving">{{ saving ? 'Сохраняем…' : 'Создать' }}</UiButton></div>
    </form>
  </div>
</template>
