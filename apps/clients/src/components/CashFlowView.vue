<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiFilterRail, UiSearchSelect } from '@irlix/ui';

const props = defineProps({ month: { type: String, required: true } });
const emit = defineEmits(['update:month']);
const month = computed({
  get: () => props.month,
  set: value => emit('update:month', value),
});
const loading = ref(false);
const error = ref('');
const clients = ref([]);
const employees = ref([]);
const departments = ref([]);
const absences = ref([]);
const timesheetRows = ref([]);
const search = ref('');
const grouping = ref('client');
const filters = ref({ client: '', sales: '', account: '', department: '', technology: '' });
const groupingOptions = [
  { value: 'client', label: 'По клиенту' },
  { value: 'department', label: 'По направлению' },
  { value: 'none', label: 'Без группировки' },
];

const filterRailItems = computed(() => [
  { id: 'search', label: 'Поиск сотрудника', icon: 'search', active: !!search.value.trim(), valueLabel: search.value.trim() },
  { id: 'client', label: 'Клиент', icon: 'building', active: !!filters.value.client, valueLabel: clientOptions.value.find(option => option.value === String(filters.value.client))?.label || '' },
  { id: 'sales', label: 'Sales', icon: 'contact', active: !!filters.value.sales, valueLabel: salesOptions.value.find(option => option.value === String(filters.value.sales))?.label || '' },
  { id: 'account', label: 'Account', icon: 'contact', active: !!filters.value.account, valueLabel: accountOptions.value.find(option => option.value === String(filters.value.account))?.label || '' },
  { id: 'department', label: 'Направление', icon: 'org', active: !!filters.value.department, valueLabel: departmentOptions.value.find(option => option.value === String(filters.value.department))?.label || '' },
  { id: 'technology', label: 'Технология', icon: 'code', active: !!filters.value.technology, valueLabel: filters.value.technology || '' },
]);
const groupingRailItems = computed(() => [{
  id: 'employees',
  label: 'Группировка сотрудников',
  icon: 'list',
  active: grouping.value !== 'none',
  valueLabel: groupingOptions.find(option => option.value === grouping.value)?.label || '',
}]);

function resetRail() {
  search.value = '';
  filters.value = { client: '', sales: '', account: '', department: '', technology: '' };
  grouping.value = 'client';
}

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
  const byId = new Map(departments.value.map(department => [String(department.id), department]));
  const children = new Map();
  departments.value.forEach(department => {
    const parent = department.parent_id != null && byId.has(String(department.parent_id)) ? String(department.parent_id) : '';
    if (!children.has(parent)) children.set(parent, []);
    children.get(parent).push(department);
  });
  for (const list of children.values()) list.sort((a,b) => String(a.name || '').localeCompare(String(b.name || ''), 'ru'));
  const options = [];
  const visited = new Set();
  const visit = (department, depth) => {
    const key = String(department.id);
    if (visited.has(key)) return;
    visited.add(key);
    options.push({ value:key, label:department.name, depth });
    (children.get(key) || []).forEach(child => visit(child, depth + 1));
  };
  (children.get('') || []).forEach(department => visit(department, 0));
  [...departments.value].sort((a,b)=>String(a.name || '').localeCompare(String(b.name || ''),'ru')).forEach(department => {
    if (!visited.has(String(department.id))) visit(department, 0);
  });
  return options;
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
    const [peoplePayload, departmentsPayload, overviewPayload, timesheetPayload] = await Promise.all([
      api('/api/employees/clients-directory'),
      api('/api/employees/departments'),
      api('/api/clients/overview'),
      api(`/api/clients/cash-flow?month=${encodeURIComponent(month.value)}`),
    ]);
    employees.value = peoplePayload.data?.employees || [];
    departments.value = departmentsPayload.data || [];
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

function summarize(source) {
  return source.reduce((total, row) => {
    total.calendarHours += Number(row.calendarHours || 0);
    total.timesheetHours += Number(row.timesheetHours || 0);
    total.confirmedHours += Number(row.confirmedHours || 0);
    total.calendarAmount += Number(row.calendarAmount || 0);
    total.timesheetAmount += Number(row.timesheetAmount || 0);
    total.confirmedAmount += Number(row.confirmedAmount || 0);
    return total;
  }, {
    calendarHours: 0,
    timesheetHours: 0,
    confirmedHours: 0,
    calendarAmount: 0,
    timesheetAmount: 0,
    confirmedAmount: 0,
  });
}

const filteredTotals = computed(() => summarize(filteredRows.value));

const groupedRows = computed(() => {
  if (grouping.value === 'none') return [];

  const groups = new Map();
  for (const row of filteredRows.value) {
    const employee = employeeMap.value.get(row.specialistId);
    const key = grouping.value === 'department'
      ? `department:${employee?.department_id || 'none'}`
      : `client:${row.clientId}`;
    const label = grouping.value === 'department'
      ? (employee?.department_name || 'Без направления')
      : row.client;

    if (!groups.has(key)) groups.set(key, { key, label, rows: [] });
    groups.get(key).rows.push(row);
  }

  return [...groups.values()]
    .map(group => ({
      ...group,
      totals: summarize(group.rows),
      employeeCount: new Set(group.rows.map(row => row.specialistId)).size,
    }))
    .sort((a,b) => a.label.localeCompare(b.label, 'ru'));
});
</script>

<template>
  <section class="cashflow-view">
    <UiFilterRail
      :items="filterRailItems"
      :grouping-items="groupingRailItems"
      grouping-title="Группировки"
      @reset="resetRail"
    >
      <template #filter-search><label class="irlix-field"><span>Поиск сотрудника</span><input v-model="search" class="registry-search" type="search" placeholder="Поиск по сотрудникам"></label></template>
      <template #filter-client><label class="irlix-field"><span>Клиент</span><UiSearchSelect v-model="filters.client" :options="clientOptions" placeholder="Все клиенты" search-placeholder="Поиск клиента" /></label></template>
      <template #filter-sales><label class="irlix-field"><span>Sales</span><UiSearchSelect v-model="filters.sales" :options="salesOptions" placeholder="Все сейлзы" search-placeholder="Поиск сейлза" /></label></template>
      <template #filter-account><label class="irlix-field"><span>Account</span><UiSearchSelect v-model="filters.account" :options="accountOptions" placeholder="Все аккаунты" search-placeholder="Поиск аккаунта" /></label></template>
      <template #filter-department><label class="irlix-field"><span>Подразделение</span><UiSearchSelect v-model="filters.department" :options="departmentOptions" placeholder="Все подразделения" search-placeholder="Поиск подразделения" /></label></template>
      <template #filter-technology><label class="irlix-field"><span>Технология</span><UiSearchSelect v-model="filters.technology" :options="technologyOptions" placeholder="Все технологии" search-placeholder="Поиск технологии" /></label></template>
      <template #grouping-employees><label class="irlix-field"><span>Сотрудники</span><UiSearchSelect v-model="grouping" :options="groupingOptions" placeholder="Группировка" :clearable="false" aria-label="Тип группировки" /></label></template>
    </UiFilterRail>

    <div v-if="error" class="cashflow-error">{{ error }} <button type="button" @click="load">Повторить</button></div>
    <div v-if="loading" class="cashflow-state">Загрузка данных ДДС…</div>
    <div v-else-if="!error" class="cashflow-table-scroll">
    <table class="irlix-data-table cash">
      <thead>
        <tr><th>Сотрудник</th><th>Клиент / проект</th><th>Технология / уровень</th><th>Загрузка</th><th>Период условий</th><th>Ставка</th><th>Часы: Календарь / ТШ / Подтверждено</th><th>ДС: Календарь / ТШ / Подтверждено</th></tr>
        <tr class="cash-total-row">
          <th colspan="6">Итого по выбранным фильтрам</th>
          <th><strong>{{ number(filteredTotals.calendarHours) }}</strong> / {{ number(filteredTotals.timesheetHours) }} / {{ number(filteredTotals.confirmedHours) }}</th>
          <th><strong>{{ money(filteredTotals.calendarAmount) }}</strong> / {{ money(filteredTotals.timesheetAmount) }} / {{ money(filteredTotals.confirmedAmount) }}</th>
        </tr>
      </thead>
      <tbody>
        <template v-if="grouping !== 'none'">
          <template v-for="group in groupedRows" :key="group.key">
            <tr class="cash-group-row">
              <td colspan="6"><strong>{{ group.label }}</strong><small>{{ group.employeeCount }} сотрудников</small></td>
              <td><strong>{{ number(group.totals.calendarHours) }}</strong> / {{ number(group.totals.timesheetHours) }} / {{ number(group.totals.confirmedHours) }}</td>
              <td><strong>{{ money(group.totals.calendarAmount) }}</strong> / {{ money(group.totals.timesheetAmount) }} / {{ money(group.totals.confirmedAmount) }}</td>
            </tr>
            <tr v-for="row in group.rows" :key="`${group.key}-${row.id}-${row.term.id}`">
              <td>{{ row.specialist }}</td>
              <td>{{ row.client }}<small>{{ row.project }}</small></td>
              <td>{{ row.term.technology }} / {{ row.term.level }}</td>
              <td>{{ number(row.term.hours_per_day) }}</td>
              <td>{{ dateRu(row.term.valid_from) }} — {{ row.term.valid_to ? dateRu(row.term.valid_to) : 'по н.в.' }}</td>
              <td>{{ number(row.term.hourly_rate) }}</td>
              <td><strong>{{ number(row.calendarHours) }}</strong> / {{ number(row.timesheetHours) }} / {{ number(row.confirmedHours) }}</td>
              <td><strong>{{ money(row.calendarAmount) }}</strong> / {{ money(row.timesheetAmount) }} / {{ money(row.confirmedAmount) }}</td>
            </tr>
          </template>
        </template>
        <template v-else>
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
        </template>
        <tr v-if="!filteredRows.length"><td colspan="8" class="cashflow-state">Нет данных за выбранный период и фильтры</td></tr>
      </tbody>
    </table>
    </div>
    <p class="cashflow-note">Календарь учитывает рабочие дни и созданные отсутствия Vacations. ТШ и подтверждённые значения получены из сервиса Timesheets; деньги рассчитываются по MemberTerms, действующим на дату ТШ.</p>
  </section>
</template>

<style scoped>
.cashflow-view{position:relative;height:calc(100vh - var(--irlix-topbar-height,45px));min-height:0;padding:0 var(--irlix-filter-rail-width) 0 0;display:flex;flex-direction:column;overflow:hidden}.cashflow-error{flex:none;margin:8px 16px;padding:10px 12px;border:1px solid #efc4c4;border-radius:8px;background:#fff5f5;color:#b42318;font-size:12px}.cashflow-error button{border:0;background:transparent;color:#078d6c;cursor:pointer}.cashflow-state{padding:28px;text-align:center;color:#737b85}.cashflow-table-scroll{flex:1;min-height:0;overflow:auto}.cashflow-note{flex:none;margin:8px 16px 10px;color:#747d87;font-size:11px}.cash{margin:0}.cash thead{position:sticky;top:0;z-index:5}.cash thead th{background:#f2f2f2}.cash td small{display:block;margin-top:2px;color:#7a838d;font-size:11px}.cash-total-row th{background:#eef8f5!important;color:#34413d;font-weight:600;border-bottom:1px solid #d9e8e3}.cash-total-row th:first-child{text-align:left}.cash-group-row td{background:#f7f8f9;font-weight:500;border-top:1px solid #e1e4e7;border-bottom:1px solid #e1e4e7}.cash-group-row td:first-child{padding-left:16px}.cash-group-row td:first-child small{display:inline;margin-left:8px;color:#8a929c;font-weight:400}@media(max-width:720px){.cashflow-view{height:calc(100vh - 53px);padding-right:var(--irlix-filter-rail-width)}}
</style>
