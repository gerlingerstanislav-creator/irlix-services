<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { UiDrawer, UiFilterBar, UiLoader, UiPeriodPicker, UiSearchSelect } from '@irlix/ui';
import { auth } from '../auth';


const month = ref(new Date().toISOString().slice(0, 7));
const selectedDepartment = ref('');
const selectedClient = ref('');
const search = ref('');
const roster = ref([]);
const departments = ref([]);
const absences = ref([]);
const clientMembers = ref([]);
const clients = ref([]);
const loading = ref(false);
const clientLoading = ref(false);
const error = ref('');
const clientError = ref('');
const selectedEmployee = ref(null);
const profile = ref(null);
const profileError = ref('');
const profileLoading = ref(false);
let rosterRequest = 0;
let memberRequest = 0;
let profileRequest = 0;

const statuses = {
 planned: 'Запланировано', employee_review: 'Согласование сотрудниками',
 hr_review: 'Первичная проверка', account_manager_review: 'Согласование с аккаунт-менеджерами',
 manager_review: 'Согласование с руководителем', hr_final_review: 'Предоставление',
 confirmed: 'Предоставлено', rejected: 'Отклонено', cancelled: 'Отменено',
};
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
const dayAbsences = (person, day) => datesFor(person.id).filter(a =>
 norm(a.starts_on) <= dayKey(day) && norm(a.ends_on || '9999-12-31') >= dayKey(day));
const isStart = (absence, day) => day === 1 || norm(absence.starts_on) === dayKey(day);
const isEnd = (absence, day) => day === days.value.length || norm(absence.ends_on) === dayKey(day);
const dayOff = day => {
 const key = dayKey(day);
 const dow = new Date(key + 'T12:00:00Z').getUTCDay();
 return dow === 0 || dow === 6;
};
const statusText = absence => statuses[absence.status] || absence.status || 'Неизвестен';
const detail = absence => 'Отсутствие — ' + statusText(absence) + ' (' + norm(absence.starts_on) + ' — ' + norm(absence.ends_on || '…') + ')';

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
async function openProfile(person) {
 const request = ++profileRequest;
 selectedEmployee.value = person;
 profile.value = null;
 profileError.value = '';
 profileLoading.value = true;
 try {
  const details = await get('/api/employees/client-specialist-profile/' + Number(person.id));
  if (request === profileRequest) profile.value = details;
 } catch (e) {
  if (request === profileRequest) profileError.value = e.message;
 } finally { if (request === profileRequest) profileLoading.value = false; }
}
function closeProfile() {
 ++profileRequest;
 selectedEmployee.value = null;
 profile.value = null;
 profileLoading.value = false;
}
const profileFields = [
 ['full_name', 'ФИО'], ['email', 'Электронная почта'],
 ['department', 'Подразделение'], ['city', 'Город'],
 ['phone', 'Телефон'], ['telegram', 'Telegram'],
];
onMounted(loadClients);
watch(month, load, { immediate: true });
watch(selectedClient, loadMembers);
</script>

<template>
 <section class="specialists-roster" aria-label="Специалисты">
  <UiFilterBar class="specialists-roster__filters">
   <input v-model="search" type="search" class="specialists-roster__search" aria-label="Поиск по ФИО" placeholder="Поиск по ФИО">
   <div class="specialists-roster__control specialists-roster__control--department">
    <UiSearchSelect v-model="selectedDepartment" :options="departmentOptions" clearable placeholder="Все подразделения" aria-label="Подразделение" />
   </div>
   <div class="specialists-roster__control specialists-roster__control--client">
    <UiSearchSelect v-model="selectedClient" :options="clientOptions" clearable placeholder="Все клиенты" aria-label="Клиент" />
   </div>
   <div class="specialists-roster__period"><UiPeriodPicker v-model="month" mode="month" /></div>
  </UiFilterBar>
  <p v-if="error" class="specialists-roster__error" role="alert">{{ error }}. Просмотр разрешён клиентской службе и администраторам платформы.</p>
  <p v-else-if="clientError" class="specialists-roster__error" role="alert">Не удалось загрузить подключения выбранного клиента: {{ clientError }}</p>
  <UiLoader v-if="loading || clientLoading" page label="Загрузка календаря специалистов" />
  <div v-else-if="!error && !clientError" class="specialists-roster__scroll">
   <table class="specialists-roster__table">
    <thead>
     <tr><th class="specialists-roster__name">Специалист</th>
      <th v-for="day in days" :key="day" :class="{ 'is-weekend': dayOff(day) }">{{ day }}</th>
     </tr>
    </thead>
    <tbody>
     <tr v-for="person in people" :key="person.id">
      <td class="specialists-roster__name"><button type="button" class="specialists-roster__person" @click="openProfile(person)">{{ person.full_name }}</button><small>{{ person.department_name || '—' }}</small></td>
      <td v-for="day in days" :key="day" :class="{ 'is-weekend': dayOff(day) }">
       <div v-for="absence in dayAbsences(person, day)" :key="absence.id"
        class="specialists-roster__absence" :class="{
          'specialists-roster__absence--start': isStart(absence, day),
          'specialists-roster__absence--end': isEnd(absence, day)
        }" :title="detail(absence)" :aria-label="detail(absence)" role="img">
        <span></span>
       </div>
      </td>
     </tr>
     <tr v-if="!people.length"><td :colspan="days.length + 1" class="specialists-roster__empty">Специалисты по выбранным фильтрам не найдены</td></tr>
    </tbody>
   </table>
  </div>
  <UiDrawer :open="!!selectedEmployee" :title="selectedEmployee?.full_name || 'Сотрудник'" width="520px" @close="closeProfile">
   <UiLoader v-if="profileLoading" label="Загрузка карточки сотрудника" />
   <p v-else-if="profileError" class="specialists-roster__error" role="alert">{{ profileError }} <button type="button" @click="openProfile(selectedEmployee)">Повторить</button></p>
   <div v-else-if="profile" class="specialists-roster__profile">
    <article v-for="[key, label] in profileFields" :key="key"><span>{{ label }}</span><strong>{{ profile[key] || '—' }}</strong></article>
   </div>
  </UiDrawer>
 </section>
</template>

<style scoped>
.specialists-roster{min-width:0;min-height:0;display:flex;flex-direction:column;padding:0}
.specialists-roster__filters{display:flex;align-items:center;flex-wrap:nowrap;gap:8px;padding:8px 12px;overflow-x:auto;min-height:52px}
.specialists-roster__search{box-sizing:border-box;flex:0 0 220px;width:220px;height:var(--irlix-control-height,32px);padding:0 12px;font:inherit;background:var(--irlix-color-surface);color:var(--irlix-color-text);border:1px solid var(--irlix-color-border);border-radius:var(--irlix-control-radius,8px)}
.specialists-roster__control{flex:0 0 210px;width:210px;min-width:0}
.specialists-roster__control--client{flex-basis:200px;width:200px}
.specialists-roster__control :deep(.ui-search-select){width:100%;min-width:0}
.specialists-roster__period{margin-left:auto;flex:none}
.specialists-roster__scroll{flex:1;min-height:0;width:100%;overflow:auto}
.specialists-roster__table{border-collapse:collapse;table-layout:fixed;min-width:1100px;width:100%;font-size:var(--irlix-font-size-caption,12px)}
.specialists-roster__table th,.specialists-roster__table td{box-sizing:border-box;border-right:1px solid var(--irlix-color-border);border-bottom:1px solid var(--irlix-color-border);height:39px;text-align:center;padding:0}
.specialists-roster__table thead th{position:sticky;top:0;z-index:3;background:var(--irlix-color-surface-muted);color:var(--irlix-color-text)}
.specialists-roster__table .specialists-roster__name{position:sticky;left:0;z-index:2;width:235px;min-width:235px;text-align:left;padding:4px 12px;background:var(--irlix-color-surface)}
.specialists-roster__table thead .specialists-roster__name{z-index:4;background:var(--irlix-color-surface-muted)}
.specialists-roster__name strong,.specialists-roster__name small{display:block}
.specialists-roster__name strong{font-size:var(--irlix-font-size-table,12px);line-height:1.15;font-weight:600}
.specialists-roster__person{display:block;max-width:100%;padding:0;border:0;background:transparent;color:var(--irlix-color-text);font:inherit;font-weight:600;text-align:left;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.specialists-roster__person:hover{text-decoration:underline;color:var(--irlix-color-primary-text)}
.specialists-roster__person:focus-visible{outline:2px solid var(--irlix-color-primary)}
.specialists-roster__name small{margin-top:2px;color:var(--irlix-color-text-muted)}
.specialists-roster__table .is-weekend{background:var(--irlix-color-surface-muted)}
.specialists-roster__table thead .is-weekend{color:var(--irlix-status-danger-text)}
.specialists-roster__absence{width:100%;height:100%;min-height:38px;display:flex;align-items:center;justify-content:center}
.specialists-roster__absence>span{display:block;height:10px;width:100%;background:var(--irlix-status-info-text);opacity:.85}
.specialists-roster__absence--start>span{width:calc(100% - 3px);margin-left:3px;border-radius:5px 0 0 5px}
.specialists-roster__absence--end>span{width:calc(100% - 3px);margin-right:3px;border-radius:0 5px 5px 0}
.specialists-roster__absence--start.specialists-roster__absence--end>span{width:calc(100% - 6px);margin:0 3px;border-radius:5px}
.specialists-roster__empty{text-align:center!important;padding:30px!important;color:var(--irlix-color-text-muted)}
.specialists-roster__error{margin:12px;padding:12px;color:var(--irlix-status-danger-text);background:var(--irlix-color-surface-muted);border:1px solid var(--irlix-status-danger-border);border-radius:var(--irlix-radius-sm,8px)}
.specialists-roster__profile{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px}
.specialists-roster__profile article{display:grid;align-content:start;gap:5px;min-height:58px;padding:10px 0;border-bottom:1px solid var(--irlix-color-border);overflow-wrap:anywhere}
.specialists-roster__profile article span{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-table)}
.specialists-roster__profile article strong{font-size:var(--irlix-font-size-body);font-weight:500}
@media(max-width:520px){.specialists-roster__profile{grid-template-columns:1fr}}
</style>
