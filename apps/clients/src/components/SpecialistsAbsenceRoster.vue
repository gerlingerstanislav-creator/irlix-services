<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { UiFilterBar, UiLoader, UiPeriodPicker, UiSearchSelect } from '@irlix/ui';
import { auth } from '../auth';


const month = ref(new Date().toISOString().slice(0, 7));
const selectedDepartment = ref('');
const selectedClient = ref('');
const search = ref('');
const roster = ref([]);
const departments = ref([]);
const absences = ref([]);
const calendar = ref({});
const clientMembers = ref([]);
const clients = ref([]);
const loading = ref(false);
const clientLoading = ref(false);
const error = ref('');
const clientError = ref('');
let rosterRequest = 0;
let memberRequest = 0;

const statuses = {
 planned: 'Запланировано', employee_review: 'Согласование сотрудниками',
 hr_review: 'Первичная проверка', account_manager_review: 'Согласование с аккаунт-менеджерами',
 manager_review: 'Согласование с руководителем', hr_final_review: 'Предоставление',
 confirmed: 'Предоставлено', rejected: 'Отклонено', cancelled: 'Отменено',
};
const year = computed(() => Number(month.value.slice(0, 4)));
const from = computed(() => month.value + '-01');
const to = computed(() => {
 const parts = month.value.split('-').map(Number);
 return month.value + '-' + String(new Date(parts[0], parts[1], 0).getDate()).padStart(2, '0');
});
const days = computed(() => Array.from({ length: Number(to.value.slice(-2)) }, (_, index) => index + 1));
const departmentOptions = computed(() => departments.value
 .map(d => ({ value: String(d.id), label: d.name }))
 .sort((a, b) => a.label.localeCompare(b.label, 'ru')));
const clientOptions = computed(() => clients.value
 .map(c => ({ value: String(c.id), label: c.name }))
 .sort((a, b) => a.label.localeCompare(b.label, 'ru')));
const norm = value => String(value || '').slice(0, 10);
const overlap = (start, finish) => norm(start) <= to.value && norm(finish || '9999-12-31') >= from.value;
const people = computed(() => roster.value
 .filter(person => {
   return !selectedDepartment.value || String(person.department_id) === String(selectedDepartment.value);
 })
 .filter(person => !search.value.trim() || String(person.full_name || '').toLocaleLowerCase('ru').includes(search.value.trim().toLocaleLowerCase('ru')))
 .filter(person => {
   if (!selectedClient.value) return true;
   return clientMembers.value.some(member => Number(member.employee_id) === Number(person.id)
     && (member.terms || []).some(term => norm(term.valid_from) <= to.value && norm(term.valid_to || '9999-12-31') >= from.value));
 })
 .sort((a, b) => String(a.department_name || '').localeCompare(String(b.department_name || ''), 'ru')
   || String(a.full_name || '').localeCompare(String(b.full_name || ''), 'ru')));
const visibleIds = computed(() => new Set(people.value.map(p => Number(p.id))));
const monthAbsences = computed(() => absences.value.filter(a => visibleIds.value.has(Number(a.employee_id)) && overlap(a.starts_on, a.ends_on)));
const byPerson = computed(() => {
 const map = new Map();
 for (const absence of monthAbsences.value) {
   const id = Number(absence.employee_id);
   if (!map.has(id)) map.set(id, []);
   map.get(id).push(absence);
 }
 return map;
});
const datesFor = id => byPerson.value.get(Number(id)) || [];
const dayKey = day => month.value + '-' + String(day).padStart(2, '0');
const dayAbsences = (person, day) => datesFor(person.id).filter(a => norm(a.starts_on) <= dayKey(day)
 && norm(a.ends_on || '9999-12-31') >= dayKey(day)
);
const dayOff = day => {
 const key = dayKey(day);
 if (calendar.value[key]) return !calendar.value[key].is_working;
 const dow = new Date(key + 'T12:00:00Z').getUTCDay();
 return dow === 0 || dow === 6;
};
const statusText = absence => statuses[absence.status] || absence.status || 'Неизвестен';
const detail = absence => 'Отсутствие — ' + statusText(absence) + ' (' + norm(absence.starts_on) + ' — ' + norm(absence.ends_on || '…') + ')';
const summary = person => [...new Set(datesFor(person.id).map(a => statusText(a)))].join(', ') || '—';

async function get(path) {
 const response = await auth.fetch(path, { headers: { Accept: 'application/json' } });
 const body = await response.json().catch(() => ({}));
 if (!response.ok) throw new Error(body.message || 'HTTP ' + response.status);
 return body.data;
}
async function load() {
 const request = ++rosterRequest;
 loading.value = true;
 error.value = '';
 try {
  const payload = await get('/api/vacations/client-service-calendar?month=' + encodeURIComponent(month.value));
  if (request !== rosterRequest) return;
  roster.value = payload?.employees || [];
  departments.value = payload?.departments || [];
  absences.value = payload?.absences || [];
  calendar.value = {};
 } catch (e) {
  if (request === rosterRequest) {
   error.value = e.message;
   roster.value = [];
   departments.value = [];
   absences.value = [];
  }
 } finally { if (request === rosterRequest) loading.value = false; }
}
async function loadMembers() {
 const request = ++memberRequest;
 clientMembers.value = [];
 clientError.value = '';
 if (!selectedClient.value) { clientLoading.value = false; return; }
 clientLoading.value = true;
 try {
  const rows = await get('/api/clients/specialists/client-members?client_id=' + encodeURIComponent(selectedClient.value));
  if (request === memberRequest) clientMembers.value = rows || [];
 } catch (e) {
  if (request === memberRequest) clientError.value = e.message;
 } finally { if (request === memberRequest) clientLoading.value = false; }
}
async function loadClients() {
 try { clients.value = await get('/api/clients/specialists/filter-clients') || []; }
 catch(e) { clientError.value = e.message; }
}
onMounted(loadClients);
watch(month, load, { immediate: true });
watch(selectedClient, loadMembers);
</script>

<template>
 <section class="specialists-roster" aria-label="Специалисты">
  <UiFilterBar class="specialists-roster__filters">
   <input v-model="search" type="search" class="specialists-roster__search" aria-label="Поиск по ФИО" placeholder="Поиск по ФИО">
   <UiSearchSelect v-model="selectedDepartment" :options="departmentOptions" clearable placeholder="Все подразделения" aria-label="Подразделение" />
   <UiSearchSelect v-model="selectedClient" :options="clientOptions" clearable placeholder="Все клиенты" aria-label="Клиент" />
   <UiPeriodPicker v-model="month" mode="month" />
  </UiFilterBar>
  <p v-if="error" class="specialists-roster__error" role="alert">{{ error }}. Просмотр разрешён сотрудникам клиентской службы.</p>
  <p v-else-if="clientError" class="specialists-roster__error" role="alert">Не удалось загрузить подключения выбранного клиента: {{ clientError }}</p>
  <UiLoader v-if="loading || clientLoading" page label="Загрузка календаря специалистов" />
  <div v-else-if="!error && !clientError" class="specialists-roster__scroll">
   <table class="specialists-roster__table">
    <thead><tr><th class="specialists-roster__name">Специалист</th><th class="specialists-roster__summary">Отсутствия / статус</th><th v-for="day in days" :key="day" :class="{ 'is-weekend': dayOff(day) }">{{ day }}</th></tr></thead>
    <tbody>
     <tr v-for="person in people" :key="person.id">
      <td class="specialists-roster__name"><strong>{{ person.full_name }}</strong><small>{{ person.department_name || '—' }}</small></td>
      <td class="specialists-roster__summary">{{ datesFor(person.id).length ? 'Отсутствие — ' + summary(person) : '—' }}</td>
      <td v-for="day in days" :key="day" :class="{ 'is-weekend': dayOff(day) }">
       <div v-for="absence in dayAbsences(person, day)" :key="absence.id" class="specialists-roster__absence"
        :title="detail(absence)" :aria-label="detail(absence)"><span></span></div>
      </td>
     </tr>
     <tr v-if="!people.length"><td :colspan="days.length + 2" class="specialists-roster__empty">Сотрудники по выбранным фильтрам не найдены</td></tr>
    </tbody>
   </table>
  </div>
 </section>
</template>

<style scoped>
.specialists-roster { min-width:0; padding:0; }
.specialists-roster__filters { display:flex; align-items:center; flex-wrap:wrap; gap:8px; padding:8px 12px; }
.specialists-roster__search { height:var(--irlix-control-height,32px); min-width:190px; flex:1; padding:0 12px; font:inherit; background:var(--irlix-color-surface); color:var(--irlix-color-text); border:1px solid var(--irlix-color-border); border-radius:var(--irlix-control-radius,8px); }
.specialists-roster__scroll { width:100%; overflow:auto; max-height:calc(100vh - 145px); }
.specialists-roster__table { border-collapse:separate; border-spacing:0; min-width:1050px; width:100%; font-size:var(--irlix-font-size-table,12px); }
.specialists-roster__table th,.specialists-roster__table td { border-right:1px solid var(--irlix-color-border); border-bottom:1px solid var(--irlix-color-border); padding:4px 3px; text-align:center; min-width:28px; height:39px; }
.specialists-roster__table thead th { position:sticky; top:0; z-index:3; background:var(--irlix-color-surface-muted); }
.specialists-roster__name { position:sticky; left:0; z-index:2; min-width:235px !important; width:235px; text-align:left !important; padding:6px 12px !important; background:var(--irlix-color-surface); }
.specialists-roster__summary { position:sticky; left:235px; z-index:2; min-width:195px !important; width:195px; text-align:left !important; padding:6px 10px !important; background:var(--irlix-color-surface); font-size:var(--irlix-font-size-caption,12px); }
thead .specialists-roster__name,thead .specialists-roster__summary { z-index:4; background:var(--irlix-color-surface-muted); }
.specialists-roster__name strong { display:block; font-weight:600; }
.specialists-roster__name small { display:block; color:var(--irlix-color-text-muted); margin-top:2px; }
.specialists-roster__table .is-weekend { background:var(--irlix-color-surface-muted); }
.specialists-roster__absence { width:100%; min-width:18px; height:25px; border-radius:3px; background:var(--irlix-color-primary-soft); display:flex; align-items:center; justify-content:center; border:1px solid var(--irlix-color-primary); }
.specialists-roster__absence span { width:5px; height:5px; border-radius:50%; background:var(--irlix-color-primary); }
.specialists-roster__empty { text-align:center !important; padding:30px !important; color:var(--irlix-color-text-muted); }
.specialists-roster__error { margin:12px; padding:12px; color:var(--irlix-status-danger-text); background:var(--irlix-color-surface-muted); border:1px solid var(--irlix-status-danger-border); border-radius:var(--irlix-radius-sm,8px); }
</style>
