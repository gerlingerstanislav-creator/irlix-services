<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiSearchSelect, UiKanbanBoard } from '@irlix/ui';

const props = defineProps({
  periods: { type: Array, default: () => [] },
  clients: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  mode: { type: String, default: 'kanban' },
  createOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['update:createOpen', 'changed']);

const stages = ['Новый','ТШ на согласовании','ТШ согласованы','Акт на согласовании','Акт согласован','Счет оплачен'];
const stageActions = ['ТШ отправлены на согласование', 'ТШ согласованы', 'Акт отправлен на согласование', 'Акт согласован', 'Счёт оплачен'];
const selectedId = ref(null);
const loadingCard = ref(false);
const saving = ref(false);
const error = ref('');
const activeTab = ref('timesheets');
const card = reactive({ period: null, client: null, timesheets: [], summary: {}, can_rollback: false });
const stageDialog = ref(false);
const draggingPeriodId = ref(null);
const dropStage = ref('');
const stageDate = ref('');
const menuOpen = ref(false);
const nextStage = computed(() => { const index = stages.indexOf(card.period?.status); return index >= 0 && index < stages.length - 1 ? { status: stages[index + 1], label: stageActions[index] } : null; });
const previousStage = computed(() => { const index = stages.indexOf(card.period?.status); return index > 0 ? stages[index - 1] : null; });
const stageDateFields = { 'ТШ на согласовании':'timesheets_sent_at', 'ТШ согласованы':'timesheets_approved_at', 'Акт на согласовании':'act_sent_at', 'Акт согласован':'act_approved_at', 'Счет оплачен':'paid_at' };
const canAdvance = computed(() => card.period && nextStage.value && (card.period.status !== 'Новый' || (Number(card.summary.worked_hours) > 0 && Number(card.summary.confirmed_hours) >= Number(card.summary.worked_hours))) && (card.period.status === 'Новый' || !!card.period[stageDateFields[card.period.status]]));
function todayLocal() { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`; }
const create = reactive({ client_id: '', start: '', end: '' });
const createError = ref('');
const calendarCursor = ref(new Date().toISOString().slice(0, 7));
const ganttYear = ref(new Date().getFullYear());

const clientOptions = computed(() => props.clients.map(c => ({ value: String(c.id), label: c.name })).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const employeeName = id => props.employees.find(e => Number(e.id) === Number(id))?.full_name || (id ? `#${id}` : '—');
const clientName = id => props.clients.find(c => Number(c.id) === Number(id))?.name || `#${id}`;
const dateRu = value => value ? new Date(`${String(value).slice(0,10)}T00:00:00`).toLocaleDateString('ru-RU') : '—';
const money = value => `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0))} ₽`;
const num = value => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0));
const timesheetLink = row => `/timesheets/?${new URLSearchParams({ section:'management', month:String(card.period.period_start).slice(0,7), client_id:String(card.period.client_id), employee_id:String(row.employee_id) })}`;
const allTimesheetsLink = computed(() => card.period ? `/timesheets/?${new URLSearchParams({ section:'management', month:String(card.period.period_start).slice(0,7), client_id:String(card.period.client_id) })}` : '/timesheets/?section=management');
const specialistConfirmed = employeeId => { const rows = card.timesheets.filter(row => Number(row.employee_id) === Number(employeeId) && Number(row.worked_hours || 0) > 0); return rows.length > 0 && rows.every(row => row.account_confirmed); };
const allTimesheetsApproved = computed(() => Number(card.summary.worked_hours || 0) > 0 && Number(card.summary.confirmed_hours || 0) >= Number(card.summary.worked_hours || 0));

async function api(url, options={}) {
  const response = await fetch(url, { ...options, headers: { Accept:'application/json','Content-Type':'application/json',...(options.headers||{}) } });
  const body = await response.json().catch(()=>({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors||{}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

async function openPeriod(period) {
  selectedId.value = Number(period.id);
  activeTab.value = 'timesheets';
  menuOpen.value = false;
  error.value = '';
  await loadCard();
}
async function loadCard() {
  if (!selectedId.value) return;
  loadingCard.value = true;
  try {
    const result = await api(`/api/clients/reporting-periods/${selectedId.value}`);
    Object.assign(card, result.data || { period:null,client:null,timesheets:[],summary:{} });
  } catch (e) { error.value = e.message || String(e); }
  finally { loadingCard.value = false; }
}
function startPeriodDrag(event, period) {
  if (saving.value || loadingCard.value) { event.preventDefault(); return; }
  draggingPeriodId.value = Number(period.id);
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', String(period.id));
}
function endPeriodDrag() { draggingPeriodId.value = null; dropStage.value = ''; }
function validPeriodDrop(period, stage) {
  if (!period || saving.value || loadingCard.value) return false;
  const from = stages.indexOf(period.status), to = stages.indexOf(stage);
  return from >= 0 && (to === from + 1 || to === from - 1);
}
function overPeriodColumn(event, stage) {
  const period = props.periods.find(item => Number(item.id) === draggingPeriodId.value);
  if (!validPeriodDrop(period, stage)) { dropStage.value = ''; return; }
  event.preventDefault();
  event.dataTransfer.dropEffect = 'move';
  dropStage.value = stage;
}
async function dropPeriod(event, stage) {
  event.preventDefault();
  const period = props.periods.find(item => Number(item.id) === draggingPeriodId.value);
  endPeriodDrag();
  if (!validPeriodDrop(period, stage)) return;
  await openPeriod(period);
  if (!card.period || Number(card.period.id) !== Number(period.id)) return;
  if (stages.indexOf(stage) < stages.indexOf(period.status)) {
    if (!card.can_rollback) { error.value = 'Недостаточно прав для возврата периода на предыдущий этап.'; return; }
    await rollbackPeriod();
    return;
  }
  if (!canAdvance.value) {
    error.value = card.period.status === 'Новый'
      ? 'Перед отправкой на согласование необходимо заполнить и подтвердить таймшиты этого периода.'
      : 'Для перехода необходимо заполнить дату предыдущего этапа.';
    return;
  }
  openStageDialog();
}
function closeCard() { selectedId.value = null; menuOpen.value = false; stageDialog.value = false; error.value = ''; }
function openStageDialog() { menuOpen.value = false; stageDate.value = todayLocal(); stageDialog.value = true; }
async function advancePeriod() {
  if (!canAdvance.value || !stageDate.value || saving.value) return;
  saving.value = true; error.value = ''; menuOpen.value = false;
  try {
    const result = await api(`/api/clients/reporting-periods/${card.period.id}`, { method:'PATCH', body:JSON.stringify({ status: nextStage.value.status, date: stageDate.value }) });
    card.period = result.data; stageDialog.value = false; await loadCard();
    emit('changed');
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}
async function rollbackPeriod() {
  if (!card.can_rollback || !previousStage.value || saving.value) return;
  menuOpen.value = false; saving.value = true; error.value = '';
  try {
    const result = await api(`/api/clients/reporting-periods/${card.period.id}`, { method:'PATCH', body:JSON.stringify({ status: previousStage.value }) });
    card.period = result.data; await loadCard(); emit('changed');
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}
async function deletePeriod() {
  if (!card.period || saving.value) return;
  menuOpen.value = false;
  if (!window.confirm('Удалить отчётный период? Это действие нельзя отменить.')) return;
  saving.value = true; error.value = '';
  try {
    await api(`/api/clients/reporting-periods/${card.period.id}`, { method:'DELETE' });
    closeCard(); emit('changed');
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

const periodsForCreateClient = computed(() => props.periods.filter(p => String(p.client_id) === String(create.client_id)));
function disabledDate(date) {
  return periodsForCreateClient.value.some(p => String(p.period_start).slice(0,10) <= date && String(p.period_end).slice(0,10) >= date);
}
function dateBetween(date, from, to) { return !!from && !!to && date >= from && date <= to; }
function rangeHasDisabled(from, to) {
  const d = new Date(`${from}T00:00:00`); const end = new Date(`${to}T00:00:00`);
  while (d <= end) {
    const iso = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    if (disabledDate(iso)) return true;
    d.setDate(d.getDate()+1);
  }
  return false;
}
const calendarDays = computed(() => {
  const [year, month] = calendarCursor.value.split('-').map(Number);
  const first = new Date(year, month-1, 1);
  const count = new Date(year, month, 0).getDate();
  const startGap = (first.getDay()+6)%7;
  const rows = [];
  for (let i=0;i<startGap;i++) rows.push(null);
  for (let day=1;day<=count;day++) {
    const date = `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
    rows.push({ day, date, disabled: disabledDate(date) });
  }
  while (rows.length%7) rows.push(null);
  return rows;
});
const calendarTitle = computed(() => {
  const [year,month] = calendarCursor.value.split('-').map(Number);
  return new Date(year,month-1,1).toLocaleDateString('ru-RU',{month:'long',year:'numeric'});
});
function shiftMonth(delta) {
  const [year,month] = calendarCursor.value.split('-').map(Number);
  const d = new Date(year,month-1+delta,1);
  calendarCursor.value = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
}
function chooseDate(day) {
  if (!create.client_id || !day || day.disabled) return;
  createError.value = '';
  if (!create.start || create.end) { create.start = day.date; create.end = ''; return; }
  if (day.date < create.start) { create.start = day.date; create.end = ''; return; }
  if (rangeHasDisabled(create.start, day.date)) { createError.value = 'Диапазон пересекается с существующим отчётным периодом.'; return; }
  create.end = day.date;
}
function resetCreate() {
  create.client_id = ''; create.start = ''; create.end = ''; createError.value = '';
  calendarCursor.value = new Date().toISOString().slice(0,7);
}
async function createPeriod() {
  if (!create.client_id || !create.start || !create.end) return;
  saving.value = true; createError.value = '';
  try {
    await api('/api/clients/reporting-periods', { method:'POST', body:JSON.stringify({ client_id:Number(create.client_id), period_start:create.start, period_end:create.end }) });
    emit('update:createOpen', false); resetCreate(); emit('changed');
  } catch (e) { createError.value = e.message || String(e); }
  finally { saving.value = false; }
}
watch(() => props.createOpen, open => { if (open) resetCreate(); });
watch(() => create.client_id, () => { create.start=''; create.end=''; createError.value=''; });

const years = computed(() => {
  const values = new Set([new Date().getFullYear()]);
  props.periods.forEach(p => { if (p.period_start) values.add(Number(String(p.period_start).slice(0,4))); if (p.period_end) values.add(Number(String(p.period_end).slice(0,4))); });
  return [...values].filter(Boolean).sort((a,b)=>b-a);
});
const months = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
const ganttClients = computed(() => props.clients.filter(client => props.periods.some(p => Number(p.client_id)===Number(client.id) && Number(String(p.period_start).slice(0,4))<=ganttYear.value && Number(String(p.period_end).slice(0,4))>=ganttYear.value)));
function periodsInMonth(clientId, monthIndex) {
  const from = `${ganttYear.value}-${String(monthIndex+1).padStart(2,'0')}-01`;
  const endDate = new Date(ganttYear.value, monthIndex+1, 0);
  const to = `${ganttYear.value}-${String(monthIndex+1).padStart(2,'0')}-${String(endDate.getDate()).padStart(2,'0')}`;
  return props.periods.filter(p => Number(p.client_id)===Number(clientId) && String(p.period_start).slice(0,10)<=to && String(p.period_end).slice(0,10)>=from);
}
function stageDateLabel(period) {
  if (period.paid_at) return `Оплачено: ${dateRu(period.paid_at)}`;
  if (period.act_approved_at) return `Акт согласован: ${dateRu(period.act_approved_at)}`;
  if (period.timesheets_approved_at) return `ТШ согласованы: ${dateRu(period.timesheets_approved_at)}`;
  return '';
}
</script>

<template>
  <div class="reports-view">
    <UiKanbanBoard v-if="mode==='kanban'" class="reports-kanban" :columns="stages" :items="periods" :active-target="dropStage" label="Канбан отчетных периодов" @dragover="overPeriodColumn" @drop="dropPeriod" @dragleave="dropStage=''">
      <template #cards="{items}">
        <button v-for="period in items" :key="period.id" type="button" class="irlix-kanban-card period-card" :class="{ 'irlix-kanban-card--dragging':draggingPeriodId===Number(period.id) }" :draggable="!saving && !loadingCard" @dragstart="startPeriodDrag($event,period)" @dragend="endPeriodDrag" @click="openPeriod(period)">
          <strong>{{clientName(period.client_id)}}</strong>
          <span>{{dateRu(period.period_start)}} - {{dateRu(period.period_end)}}</span>
          <small v-if="stageDateLabel(period)">{{stageDateLabel(period)}}</small>
          <small class="period-status">{{period.status}}</small>
        </button>
      </template>
    </UiKanbanBoard>

    <div v-else class="reports-gantt-wrap">
      <div class="gantt-toolbar"><label>Год <select v-model.number="ganttYear"><option v-for="year in years" :key="year" :value="year">{{year}}</option></select></label></div>
      <div class="reports-gantt">
        <div class="gantt-head client-col">Клиент</div><div v-for="month in months" :key="month" class="gantt-head">{{month}}</div>
        <template v-for="client in ganttClients" :key="client.id">
          <div class="gantt-client">{{client.name}}</div>
          <div v-for="(_,monthIndex) in months" :key="monthIndex" class="gantt-cell">
            <button v-for="period in periodsInMonth(client.id,monthIndex)" :key="period.id" type="button" class="gantt-period" :title="`${dateRu(period.period_start)} – ${dateRu(period.period_end)} · ${period.status}`" @click="openPeriod(period)">{{period.status}}</button>
          </div>
        </template>
      </div>
      <div v-if="!ganttClients.length" class="empty">За {{ganttYear}} год отчётных периодов нет</div>
    </div>

    <UiDrawer :open="!!selectedId" :title="card.period ? `ОП ${card.client?.name||''} ${dateRu(card.period.period_start)} - ${dateRu(card.period.period_end)}` : 'Отчётный период'" width="660px" :min-width="520" @close="closeCard">
      <template #actions><div v-if="card.period" class="period-menu-wrap">
        <button type="button" class="period-menu-trigger" aria-label="Действия с отчётным периодом" :aria-expanded="menuOpen" @click="menuOpen=!menuOpen">···</button>
        <div v-if="menuOpen" class="period-menu">
          <button v-if="nextStage" type="button" :disabled="saving || !canAdvance" :title="!canAdvance ? (card.period.status==='Новый' ? 'Все заполненные ТШ должны быть подтверждены аккаунт-менеджером' : 'Не заполнена дата предыдущего этапа') : ''" @click="openStageDialog"><span aria-hidden="true">✓</span>{{nextStage.label}}</button>
          <button v-if="previousStage && card.can_rollback" type="button" :disabled="saving" @click="rollbackPeriod"><span aria-hidden="true">↶</span>Вернуть: {{previousStage}}</button>
          <button type="button" class="period-menu-delete" :disabled="saving" @click="deletePeriod"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v6m4-6v6"/></svg>Удалить</button>
        </div>
      </div></template>
      <div v-if="error" class="drawer-error">{{error}}</div>
      <div v-if="loadingCard" class="empty">Загрузка…</div>
      <div v-else-if="card.period" class="period-drawer">
        <div class="period-summary">
          <div class="summary-row"><span>Период</span><b>{{dateRu(card.period.period_start)}} — {{dateRu(card.period.period_end)}}</b></div>
          <div class="summary-row"><span>Аккаунт</span><b>{{employeeName(card.client?.account_employee_id)}}</b></div>
          <div class="summary-row"><span>Срок согласования</span><b>{{card.client?.act_approval_days??'—'}}<template v-if="card.client?.act_approval_days!=null"> (дней)</template></b></div>
          <div class="summary-row"><span>Срок оплаты</span><b>{{card.client?.payment_days??'—'}}<template v-if="card.client?.payment_days!=null"> (дней)</template></b></div>
          <div class="summary-row"><span>Статус</span><div><UiBadge tone="info">{{card.period.status}}</UiBadge></div></div>
          <div class="summary-row"><span>ТШ отправлены / согласованы</span><b>{{dateRu(card.period.timesheets_sent_at)}} / {{dateRu(card.period.timesheets_approved_at)}}</b></div>
          <div class="summary-row"><span>Акт отправлен / согласован</span><b>{{dateRu(card.period.act_sent_at)}} / {{dateRu(card.period.act_approved_at)}}</b></div>
          <div class="summary-row"><span>Счёт оплачен</span><b>{{dateRu(card.period.paid_at)}}</b></div>
          <div class="summary-row"><span>Согласовано</span><b>{{num(card.summary.worked_hours)}} / {{num(card.summary.confirmed_hours)}} (ч)</b></div>
          <div class="summary-row"><span>Сумма</span><b>{{money(card.summary.worked_amount)}} / {{money(card.summary.confirmed_amount)}}</b></div>
        </div>
        <p v-if="card.period.status==='Новый' && !card.summary.worked_hours" class="stage-hint">Для отправки на согласование сначала заполните ТШ этого клиента за отчётный период.</p>
        <p v-else-if="card.period.status==='Новый' && !canAdvance" class="stage-hint">Перед отправкой клиенту подтвердите все ТШ отчётного периода на странице управления.</p>
        <p v-else-if="nextStage && !canAdvance" class="stage-hint">Для следующего этапа нужна дата текущего статуса. Руководитель может откатить период и повторить переход.</p>
        <div class="period-tabs"><button :class="{active:activeTab==='notes'}" @click="activeTab='notes'">▤ Заметки</button><button :class="{active:activeTab==='timesheets'}" @click="activeTab='timesheets'">◌ Таймшиты <span v-if="allTimesheetsApproved" class="timesheets-approved" title="Все таймшиты подтверждены">✓</span></button></div>
        <div v-if="activeTab==='notes'" class="notes-placeholder">Заметки отчётного периода будут проработаны отдельно.</div>
        <div v-else class="timesheets-table">
          <div class="ts-head"><span>Сотрудник</span><span>Ставка, руб</span><span>Отработано, ч</span><span>Согласовано, ч</span></div>
          <div class="ts-total"><span></span><span></span><strong>{{num(card.summary.worked_hours)}}</strong><strong>{{num(card.summary.confirmed_hours)}}</strong></div>
          <a v-for="row in card.timesheets" :key="row.term_id" :href="row.employee_id?timesheetLink(row):undefined" class="ts-row ts-link" :aria-label="row.employee_id?`Управление таймшитами: ${row.employee_name}`:`Партнерский специалист: ${row.employee_name}`"><div><strong>{{row.employee_name}} <span v-if="specialistConfirmed(row.employee_id)" class="specialist-approved" title="Все таймшиты специалиста подтверждены">✓</span></strong><small>{{row.project_name}} · {{dateRu(row.valid_from)}} — {{row.valid_to ? dateRu(row.valid_to) : 'по н.в.'}}</small></div><span>{{num(row.hourly_rate)}}</span><div><strong>{{num(row.worked_hours)}}</strong><small>{{money(row.worked_amount)}}</small></div><strong>{{num(row.confirmed_hours)}}</strong></a>
          <div v-if="!card.timesheets.length" class="empty">В выбранном периоде нет записей таймшитов</div>
          <div class="all-timesheets-link"><a :href="allTimesheetsLink">все таймшиты</a></div>
        </div>
      </div>
    </UiDrawer>

    <UiDrawer :open="stageDialog" :title="nextStage?.label || 'Дата этапа'" width="400px" @close="stageDialog=false">
      <form class="stage-date-form" @submit.prevent="advancePeriod">
        <label>Дата этапа<input v-model="stageDate" type="date" required></label>
        <div v-if="error" class="drawer-error">{{error}}</div>
        <UiButton type="submit" :disabled="saving || !stageDate">{{saving ? 'Сохраняю…' : 'Подтвердить переход'}}</UiButton>
      </form>
    </UiDrawer>

    <UiDrawer :open="createOpen" title="Новый отчётный период" width="560px" :min-width="460" @close="emit('update:createOpen',false)">
      <form class="create-form" @submit.prevent="createPeriod">
        <label>Клиент<UiSearchSelect v-model="create.client_id" :options="clientOptions" placeholder="Выберите клиента" search-placeholder="Поиск клиента" :clearable="false"/></label>
        <div class="range-value"><span>Начало: <b>{{dateRu(create.start)}}</b></span><span>Окончание: <b>{{dateRu(create.end)}}</b></span></div>
        <div class="calendar" :class="{disabled:!create.client_id}">
          <header><button type="button" @click="shiftMonth(-1)">‹</button><strong>{{calendarTitle}}</strong><button type="button" @click="shiftMonth(1)">›</button></header>
          <div class="week"><span v-for="d in ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']" :key="d">{{d}}</span></div>
          <div class="days"><template v-for="(day,index) in calendarDays" :key="day?.date||`blank-${index}`"><span v-if="!day"></span><button v-else type="button" :disabled="!create.client_id||day.disabled" :class="{blocked:day.disabled,selected:day.date===create.start||day.date===create.end,inrange:dateBetween(day.date,create.start,create.end)}" @click="chooseDate(day)">{{day.day}}</button></template></div>
        </div>
        <p class="calendar-hint">Занятые даты недоступны для выбора.</p>
        <div v-if="createError" class="drawer-error">{{createError}}</div>
        <div class="create-actions"><UiButton type="submit" :disabled="saving||!create.client_id||!create.start||!create.end">{{saving?'Создаю…':'Создать период'}}</UiButton></div>
      </form>
    </UiDrawer>
  </div>
</template>

<style scoped>
.reports-view{padding:14px 16px 30px}.reports-gantt-wrap{padding-top:2px}.gantt-toolbar{display:flex;justify-content:flex-end;margin-bottom:8px}.gantt-toolbar label{display:flex;align-items:center;gap:6px;font-size:12px}.gantt-toolbar select{height:30px;border:1px solid #dfe3e7;border-radius:7px;background:#fff}.reports-gantt{display:grid;grid-template-columns:minmax(140px,1.6fr) repeat(12,minmax(0,1fr));width:100%;border-top:1px solid #e6e8eb;border-left:1px solid #e6e8eb}.gantt-head,.gantt-client,.gantt-cell{min-width:0;border-right:1px solid #e6e8eb;border-bottom:1px solid #e6e8eb}.gantt-head{padding:7px 2px;text-align:center;font-size:10px;font-weight:700;background:#fafafa}.gantt-head.client-col{text-align:left;padding-left:8px}.gantt-client{padding:8px;font-size:11px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.gantt-cell{min-height:42px;padding:2px;display:grid;gap:2px;align-content:start}.gantt-period{border:0;border-radius:4px;background:#e5f6f0;color:#087f67;padding:3px 2px;font-size:8px;line-height:1.15;overflow:hidden;text-overflow:ellipsis;cursor:pointer}.drawer-error{padding:9px 11px;margin-bottom:10px;border:1px solid #efc4c4;border-radius:7px;background:#fff5f5;color:#b42318;font-size:12px}.period-drawer{padding:2px 8px 24px}.period-summary{display:grid}.summary-row{display:grid;grid-template-columns:170px minmax(0,1fr);align-items:center;gap:10px;min-height:42px;font-size:13px}.summary-row>span{color:#30363d}.summary-row b{font-weight:500}.summary-row>div{display:flex;align-items:center;gap:6px;min-width:0}.summary-row input{height:31px;border:1px solid #dce0e5;border-radius:7px;padding:5px 7px}.summary-row button,.pencil{border:0;background:transparent;color:#087f67;cursor:pointer}.period-range{flex-wrap:wrap}.period-tabs{margin-top:8px;background:#f3f3f3;border-radius:8px;padding:4px;display:grid;grid-template-columns:1fr 1fr;gap:4px}.period-tabs button{height:38px;border:0;border-radius:7px;background:transparent;font:inherit;font-weight:600;color:#5d6670}.period-tabs button.active{background:#fff;color:#20262d;box-shadow:0 1px 3px rgba(0,0,0,.08)}.notes-placeholder,.empty{padding:28px 10px;text-align:center;color:#7d858f;font-size:12px}.timesheets-table{margin-top:10px}.ts-head,.ts-row,.ts-total{display:grid;grid-template-columns:1.5fr .75fr .9fr .9fr;align-items:center;gap:8px;padding:9px;border-bottom:1px solid #e2e4e7;font-size:12px}.ts-head{background:#f7f7f7;font-weight:700;color:#59616b}.ts-total{background:#fafafa}.ts-row>div{display:grid;gap:2px}.ts-row small{color:#77808a}.create-form{display:grid;gap:14px;padding:4px 6px 24px}.create-form>label{display:grid;gap:6px;font-size:12px;color:#59616b}.range-value{display:flex;justify-content:space-between;gap:10px;font-size:12px}.calendar{border:1px solid #dfe3e7;border-radius:10px;padding:10px}.calendar.disabled{opacity:.6}.calendar header{display:grid;grid-template-columns:32px 1fr 32px;align-items:center;text-align:center;margin-bottom:8px}.calendar header button{border:0;background:transparent;font-size:22px}.calendar header strong{text-transform:capitalize;font-size:13px}.week,.days{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}.week span{text-align:center;font-size:10px;color:#7b838d;padding:4px}.days button{aspect-ratio:1;border:0;border-radius:7px;background:transparent;font:inherit;font-size:12px;cursor:pointer}.days button:hover:not(:disabled){background:#e8f7f2}.days button.blocked{background:#f2f2f2;color:#b0b4ba;text-decoration:line-through}.days button.inrange{background:#e5f6f0}.days button.selected{background:#12aa82;color:#fff;font-weight:700}.calendar-hint{margin:-5px 0 0;color:#7b838d;font-size:11px}.create-actions{display:flex;justify-content:flex-end}@media(max-width:1200px){.reports-gantt{grid-template-columns:minmax(120px,1.4fr) repeat(12,minmax(50px,1fr))}.gantt-period{font-size:7px}}@media(max-width:720px){.reports-view{padding:10px}.summary-row{grid-template-columns:120px 1fr}.ts-head,.ts-row,.ts-total{grid-template-columns:1.2fr .7fr .8fr .8fr}}
.period-menu-wrap{position:relative}.period-menu-trigger{border:0;border-radius:7px;background:#e5e5e5;color:#535961;width:30px;height:28px;font-weight:700;letter-spacing:1px;cursor:pointer}.period-menu{position:absolute;right:0;top:calc(100% + 6px);z-index:5;min-width:225px;padding:5px;border:1px solid #e0e2e5;border-radius:10px;background:#fafafa;box-shadow:0 4px 14px rgba(0,0,0,.12)}.period-menu button{display:flex;align-items:center;gap:9px;width:100%;min-height:36px;padding:7px 10px;border:0;border-radius:7px;background:transparent;text-align:left;font:inherit;font-size:12px;color:#079c79;cursor:pointer}.period-menu button:hover{background:#edf6f3}.period-menu button:disabled{opacity:.5;cursor:default}.period-menu button span{width:16px;font-size:16px}.period-menu .period-menu-delete{color:#ec3030}.period-menu .period-menu-delete:hover{background:#fff0f0}
.stage-hint{margin:8px 0;padding:9px 11px;border-radius:7px;background:#fff7e7;color:#8d6100;font-size:12px}.stage-date-form{display:grid;gap:14px;padding:8px}.stage-date-form label{display:grid;gap:7px;font-size:13px}.stage-date-form input{height:36px;padding:5px 9px;border:1px solid #dce0e5;border-radius:7px;font:inherit}.stage-date-form .irlix-button{justify-self:end}
.period-summary .summary-row { grid-template-columns: 205px minmax(0, 1fr); }
.ts-link { color: inherit; text-decoration: none; cursor: pointer; }
.ts-link:hover, .ts-link:focus-visible { background: #eef8f4; outline: 2px solid #b8e7d7; outline-offset: -2px; }
.timesheets-approved{display:inline-grid;place-items:center;width:17px;height:17px;margin-left:4px;border-radius:50%;background:#0aaa82;color:#fff;font-size:11px}.all-timesheets-link{display:flex;justify-content:flex-end;padding:12px 8px 0}.all-timesheets-link a{color:#078d6c;font-size:12px;font-weight:600;text-decoration:none}.all-timesheets-link a:hover{text-decoration:underline}
.specialist-approved{display:inline-grid;place-items:center;width:15px;height:15px;border-radius:50%;background:#0aaa82;color:#fff;font-size:10px}
@media (max-width: 720px) { .period-summary .summary-row { grid-template-columns: 155px minmax(0, 1fr); } }
</style>

