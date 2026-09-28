<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiFilterBar, UiSearchSelect } from '@irlix/ui';

const month = ref(new Date().toISOString().slice(0, 7));
const loading = ref(false);
const error = ref('');
const clients = ref([]);
const employees = ref([]);
const absences = ref([]);
const timesheetRows = ref([]);
const search = ref('');
const filters = ref({ client: '', sales: '', account: '', department: '', technology: '' });

async function api(url) {
  const response = await fetch(url, { headers: { Accept: 'application/json' } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || `HTTP ${response.status}`);
  return body;
}

const iso = value => String(value || '').slice(0, 10);
const employeeMap = computed(() => new Map(employees.value.map(employee => [Number(employee.id), employee])));
const employeeName = id => employeeMap.value.get(Number(id))?.full_name || (id ? `#${id}` : '—');
const clientOptions = computed(() => clients.value.map(client => ({ value: String(client.id), label: client.name })).sort((a,b) => a.label.localeCompare(b.label, 'ru')));
const salesOptions = computed(() => [...new Set(clients.value.map(client => Number(client.sales_employee_id)).filter(Boolean))].map(id => ({ value: String(id), label: employeeName(id) })).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const accountOptions = computed(() => [...new Set(clients.value.map(client => Number(client.account_employee_id)).filter(Boolean))].map(id => ({ value: String(id), label: employeeName(id) })).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const departmentOptions = computed(() => {
  const result = new Map();
  employees.value.forEach(employee => {
    if (employee.department_id) result.set(String(employee.department_id), employee.department_name || `#${employee.department_id}`);
  });
  return [...result].map(([value,label]) => ({ value,label })).sort((a,b)=>a.label.localeCompare(b.label,'ru'));
});
const technologyOptions = computed(() => [...new Set(
  clients.value.flatMap(client => (client.projects || []).flatMap(project =>
    (project.members || []).flatMap(member => (member.terms || []).map(term => term.technology).filter(Boolean))
  ))
)].sort((a,b)=>String(a).localeCompare(String(b),'ru')));

function monthRange() {
  const [year, monthNumber] = month.value.split('-').map(Number);
  const days = new Date(year, monthNumber, 0).getDate();
  return {
    from: `${year}-${String(monthNumber).padStart(2,'0')}-01`,
    to: `${year}-${String(monthNumber).padStart(2,'0')}-${String(days).padStart(2,'0')}`,
  };
}

const holidays2026 = new Set(['2026-01-01','2026-01-02','2026-01-05','2026-01-06','2026-01-07','2026-01-08','2026-01-09','2026-02-23','2026-03-09','2026-05-01','2026-05-11','2026-06-12','2026-11-04']);
function dates(from, to) {
  const out = [];
  const cursor = new Date(`${from}T00:00:00`);
  const finish = new Date(`${to}T00:00:00`);
  while (cursor <= finish) {
    out.push(`${cursor.getFullYear()}-${String(cursor.getMonth()+1).padStart(2,'0')}-${String(cursor.getDate()).padStart(2,'0')}`);
    cursor.setDate(cursor.getDate()+1);
  }
  return out;
}
function workday(value) {
  const date = new Date(`${value}T00:00:00`);
  return date.getDay() !== 0 && date.getDay() !== 6 && !holidays2026.has(value);
}
function absent(employeeId, date) {
  return absences.value.some(absence => Number(absence.employee_id) === Number(employeeId) && iso(absence.starts_on) <= date && iso(absence.ends_on) >= date);
}
function money(value) {
  return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0))} ₽`;
}
function number(value) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0));
}
function dateRu(value) {
  return value ? new Date(`${iso(value)}T00:00:00`).toLocaleDateString('ru-RU') : '—';
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const [peoplePayload, overviewPayload, timesheetPayload] = await Promise.all([
      api('/api/employees/employees'),
      api('/api/clients/overview'),
      api(`/api/clients/cash-flow?month=${encodeURIComponent(month.value)}`),
    ]);
    employees.value = peoplePayload.data || [];
    clients.value = overviewPayload.data?.clients || [];
    timesheetRows.value = timesheetPayload.data?.rows || [];

    const { from, to } = monthRange();
    const employeeIds = [...new Set(clients.value.flatMap(client => (client.projects || []).flatMap(project => (project.members || []).map(member => Number(member.specialist_id)).filter(Boolean))))];
    if (employeeIds.length) {
      const params = new URLSearchParams({ from, to });
      employeeIds.forEach(id => params.append('employee_ids[]', String(id)));
      absences.value = (await api(`/api/vacations/calendar-absences?${params}`)).data || [];
    } else {
      absences.value = [];
    }
  } catch (exception) {
    error.value = exception.message || String(exception);
  } finally {
    loading.value = false;
  }
}

watch(month, load);
onMounted(load);

const timesheetMap = computed(() => new Map(timesheetRows.value.map(row => [Number(row.term_id), row])));
const rows = computed(() => {
  const { from, to } = monthRange();
  const result = [];
  for (const client of clients.value) {
    for (const project of client.projects || []) {
      for (const member of project.members || []) {
        for (const term of member.terms || []) {
          const start = iso(term.valid_from) > from ? iso(term.valid_from) : from;
          const finish = !term.valid_to || iso(term.valid_to) > to ? to : iso(term.valid_to);
          if (!start || start > finish) continue;
          const calendarHours = dates(start, finish).filter(date => workday(date) && !absent(member.specialist_id, date)).length * Number(term.hours_per_day || 0);
          const ts = timesheetMap.value.get(Number(term.id)) || {};
          result.push({
            id: Number(member.id),
            term,
            clientId: Number(client.id),
            client: client.name,
            projectId: Number(project.id),
            project: project.name || 'Основной проект',
            salesId: Number(client.sales_employee_id) || null,
            accountId: Number(client.account_employee_id) || null,
            specialistId: Number(member.specialist_id),
            specialist: member.specialist_name,
            start,
            finish,
            calendarHours,
            calendarAmount: calendarHours * Number(term.hourly_rate || 0),
            timesheetHours: Number(ts.timesheet_hours || 0),
            timesheetAmount: Number(ts.timesheet_amount || 0),
            confirmedHours: Number(ts.confirmed_hours || 0),
            confirmedAmount: Number(ts.confirmed_amount || 0),
          });
        }
      }
    }
  }
  return result.sort((a,b) => a.client.localeCompare(b.client,'ru') || a.project.localeCompare(b.project,'ru') || String(a.specialist).localeCompare(String(b.specialist),'ru') || a.start.localeCompare(b.start));
});

const filteredRows = computed(() => {
  const needle = search.value.trim().toLowerCase();
  return rows.value.filter(row => {
    const employee = employeeMap.value.get(row.specialistId);
    if (needle && !String(row.specialist || '').toLowerCase().includes(needle)) return false;
    if (filters.value.client && String(row.clientId) !== String(filters.value.client)) return false;
    if (filters.value.sales && String(row.salesId || '') !== String(filters.value.sales)) return false;
    if (filters.value.account && String(row.accountId || '') !== String(filters.value.account)) return false;
    if (filters.value.department && String(employee?.department_id || '') !== String(filters.value.department)) return false;
    if (filters.value.technology && String(row.term.technology || '') !== String(filters.value.technology)) return false;
    return true;
  });
});
</script>

<template>
  <section class="cashflow-view">
    <UiFilterBar>
      <input v-model="search" class="registry-search" type="search" placeholder="Поиск по сотрудникам">
      <UiSearchSelect v-model="filters.client" :options="clientOptions" placeholder="Клиенты" search-placeholder="Поиск клиента" />
      <UiSearchSelect v-model="filters.sales" :options="salesOptions" placeholder="Сейлзы" search-placeholder="Поиск сейлза" />
      <UiSearchSelect v-model="filters.account" :options="accountOptions" placeholder="Аккаунты" search-placeholder="Поиск аккаунта" />
      <UiSearchSelect v-model="filters.department" :options="departmentOptions" placeholder="Подразделения" search-placeholder="Поиск подразделения" />
      <UiSearchSelect v-model="filters.technology" :options="technologyOptions" placeholder="Технологии" search-placeholder="Поиск технологии" />
      <input v-model="month" class="registry-filter-input" type="month">
    </UiFilterBar>

    <div v-if="error" class="cashflow-error">{{ error }} <button type="button" @click="load">Повторить</button></div>
    <div v-if="loading" class="cashflow-state">Загрузка данных ДДС…</div>
    <table v-else class="irlix-data-table cash">
      <thead><tr><th>Сотрудник</th><th>Клиент / проект</th><th>Технология / уровень</th><th>Загрузка</th><th>Период условий</th><th>Ставка</th><th>Часы: Календарь / ТШ / Подтверждено</th><th>ДС: Календарь / ТШ / Подтверждено</th></tr></thead>
      <tbody>
        <tr v-for="row in filteredRows" :key="`${row.id}-${row.term.id}`">
          <td>{{ row.specialist }}</td>
          <td>{{ row.client }}<small>{{ row.project }}</small></td>
          <td>{{ row.term.technology }} / {{ row.term.level }}</td>
          <td>{{ number(row.term.hours_per_day) }}</td>
          <td>{{ dateRu(row.term.valid_from) }} — {{ row.term.valid_to ? dateRu(row.term.valid_to) : 'по н.в.' }}</td>
          <td>{{ number(row.term.hourly_rate) }}</td>
          <td><strong>{{ number(row.calendarHours) }}</strong> / {{ number(row.timesheetHours) }} / {{ number(row.confirmedHours) }}</td>
          <td><strong>{{ money(row.calendarAmount) }}</strong> / {{ money(row.timesheetAmount) }} / {{ money(row.confirmedAmount) }}</td>
        </tr>
        <tr v-if="!filteredRows.length"><td colspan="8" class="cashflow-state">Нет данных за выбранный период и фильтры</td></tr>
      </tbody>
    </table>
    <p class="cashflow-note">Календарь учитывает рабочие дни и созданные отсутствия Vacations. ТШ и подтверждённые значения получены из сервиса Timesheets; деньги рассчитываются по MemberTerms, действующим на дату ТШ.</p>
  </section>
</template>

<style scoped>
.cashflow-view{padding:14px 16px 30px}.cashflow-error{margin:8px 0;padding:10px 12px;border:1px solid #efc4c4;border-radius:8px;background:#fff5f5;color:#b42318;font-size:12px}.cashflow-error button{border:0;background:transparent;color:#078d6c;cursor:pointer}.cashflow-state{padding:28px;text-align:center;color:#737b85}.cashflow-note{margin:12px 4px;color:#747d87;font-size:11px}.cash td small{display:block;margin-top:2px;color:#7a838d;font-size:11px}
</style>
