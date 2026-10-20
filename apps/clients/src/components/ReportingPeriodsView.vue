<script setup>
import { displayEmployeeName } from '../displayEmployeeName';
import HistoryTimeline from './HistoryTimeline.vue';
import { rememberedView, rememberView } from '../persistView';
import { computed, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiSearchSelect, UiKanbanBoard, UiFilterBar, UiTabs, UiStatusCheck } from '@irlix/ui';

const props = defineProps({
  periods: { type: Array, default: () => [] },
  clients: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  mode: { type: String, default: 'kanban' },
  year: { type: [Number, String], default: () => new Date().getFullYear() },
  createOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['update:createOpen', 'update:year', 'changed']);

const stages = ['Новый','ТШ на согласовании','ТШ согласованы','Акт на согласовании','Акт согласован','Счет оплачен'];
const reportColumns = ref(stages.slice(0, -1));
const visibleReportColumns = computed(() => stages.filter(status => reportColumns.value.includes(status)));
const stageActions = ['ТШ отправлены на согласование', 'ТШ согласованы', 'Акт отправлен на согласование', 'Акт согласован', 'Счёт оплачен'];
const selectedId = ref(null);
const loadingCard = ref(false);
const saving = ref(false);
const error = ref('');
const activeTab = ref(rememberedView('report.card.tab', 'timesheets', ['timesheets','history']));
watch(activeTab, value => rememberView('report.card.tab', value));
const card = reactive({ period: null, client: null, timesheets: [], history: [], summary: {}, can_rollback: false });
const stageDialog = ref(false);
const draggingPeriodId = ref(null);
const dropStage = ref('');
const stageDate = ref('');
const menuOpen = ref(false);
const nextStage = computed(() => { if (card.period?.status === 'Акт согласован' || card.period?.status === 'Счет оплачен') return null; const index = stages.indexOf(card.period?.status); return index >= 0 && index < stages.length - 1 ? { status: stages[index + 1], label: stageActions[index] } : null; });
const previousStage = computed(() => { if(card.period?.status === 'Счет оплачен') return null; const index = stages.indexOf(card.period?.status); return index > 0 ? stages[index - 1] : null; });
const stageDateFields = { 'ТШ на согласовании':'timesheets_sent_at', 'ТШ согласованы':'timesheets_approved_at', 'Акт на согласовании':'act_sent_at', 'Акт согласован':'act_approved_at', 'Счет оплачен':'paid_at' };
const canAdvance = computed(() => card.period && nextStage.value && (card.period.status !== 'Новый' || (Number(card.summary.worked_hours) > 0 && card.summary.all_account_confirmed)) && (card.period.status === 'Новый' || !!card.period[stageDateFields[card.period.status]]));
function todayLocal() { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`; }
const create = reactive({ client_id: '', start: '', end: '' });
const createError = ref('');
const calendarCursor = ref(new Date().toISOString().slice(0, 7));
const ganttYear = computed({ get: () => Number(props.year), set: year => emit('update:year', Number(year)) });
const productionDays = reactive({});
const calendarErrors = reactive({});
const calendarYears = computed(() => {
  const current = new Date().getFullYear();
  const required = new Set();
  for (const period of props.periods) {
    if (!period.act_sent_at || period.paid_at) continue;
    const start = Number(String(period.act_sent_at).slice(0,4));
    if (!Number.isInteger(start) || start < 2000 || start > 2100) continue;
    for (let year = start; year <= Math.min(2100, current+2); year++) required.add(year);
  }
  return [...required];
});
async function loadProductionCalendar(year) {
  try {
    const response = await fetch(`/api/clients/production-calendar?year=${year}`, { headers: {Accept:'application/json'} });
    if (!response.ok) throw new Error('Calendar unavailable');
    const data = (await response.json()).data;
    if (!data?.available) throw new Error('Calendar incomplete');
    productionDays[year] = data.days;
    delete calendarErrors[year];
  } catch (_) { calendarErrors[year] = true; }
}
watch(calendarYears, years => years.forEach(year => { if (!productionDays[year] && !calendarErrors[year]) loadProductionCalendar(year); }), {immediate:true});

const clientOptions = computed(() => props.clients.map(c => ({ value: String(c.id), label: c.name })).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const employeeName = id => displayEmployeeName(props.employees.find(e => Number(e.id) === Number(id))?.full_name) || (id ? `#${id}` : '—');
const clientName = id => props.clients.find(c => Number(c.id) === Number(id))?.name || `#${id}`;
const dateRu = value => value ? new Date(`${String(value).slice(0,10)}T00:00:00`).toLocaleDateString('ru-RU') : '—';
const money = value => value === null ? '—' : `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0))} ₽`;
const num = value => value === null ? '—' : new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0));
const timesheetLink = row => `/timesheets/?${new URLSearchParams({ section:'management', month:String(card.period.period_start).slice(0,7), client_id:String(card.period.client_id), employee_id:String(row.employee_id) })}`;
const allTimesheetsLink = computed(() => card.period ? `/timesheets/?${new URLSearchParams({ section:'management', month:String(card.period.period_start).slice(0,7), client_id:String(card.period.client_id) })}` : '/timesheets/?section=management');
const shortEmployeeName = name => String(name || '').trim().split(/\s+/).slice(0, 2).join(' ');
async function addHistoryNote(text) {
  if (!card.period || saving.value) return;
  saving.value = true; error.value = '';
  try { await api(`/api/clients/reporting-periods/${card.period.id}/notes`, {method:'POST',body:JSON.stringify({text})}); await loadCard(); }
  catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}
// Display one row per employee and project, without losing amount calculations by rate period.
const groupedTimesheets = computed(() => {
  const groups = new Map();
  for (const row of card.timesheets || []) {
    const identity = row.employee_id != null ? `employee:${row.employee_id}` : row.partner_specialist_id != null ? `partner:${row.partner_specialist_id}` : `member:${row.project_member_id}`;
    const key = `${identity}|project:${row.project_id}`;
    if (!groups.has(key)) groups.set(key, { ...row, key, rates: [], worked_hours: 0, worked_amount: 0, confirmed_hours: 0, approved_overtime_hours: 0, confirmed_amount: 0, account_confirmed: true, has_worked_amount: true, has_confirmed_amount: true });
    const group = groups.get(key);
    group.rates.push({ term_id: row.term_id, rate: row.hourly_rate, from: row.valid_from, to: row.valid_to, worked_hours: row.worked_hours, confirmed_hours: row.confirmed_hours, overtime_hours: row.approved_overtime_hours });
    group.worked_hours += Number(row.worked_hours || 0);
    group.confirmed_hours += Number(row.confirmed_hours || 0);
    group.approved_overtime_hours += Number(row.approved_overtime_hours || 0);
    if (row.worked_amount == null) group.has_worked_amount = false;
    else group.worked_amount += Number(row.worked_amount);
    if (row.confirmed_amount == null) group.has_confirmed_amount = false;
    else group.confirmed_amount += Number(row.confirmed_amount);
    if (Number(row.worked_hours || 0) > 0 && !row.account_confirmed) group.account_confirmed = false;
  }
  return [...groups.values()].map(group => ({
    ...group,
    worked_hours: Math.round(group.worked_hours * 100) / 100,
    confirmed_hours: Math.round(group.confirmed_hours * 100) / 100,
    approved_overtime_hours: Math.round(group.approved_overtime_hours * 100) / 100,
    worked_amount: group.has_worked_amount ? Math.round(group.worked_amount * 100) / 100 : null,
    confirmed_amount: group.has_confirmed_amount ? Math.round(group.confirmed_amount * 100) / 100 : null,
    rates: group.rates.sort((a,b) => String(a.from).localeCompare(String(b.from))),
  }));
});
const assignmentLabel = row => row.project_name;
const approvalDrafts = reactive({});
const approvalStates = reactive({});
const approvalFeedbackTimers = new Map();
function feedback(id, state) {
  if (approvalFeedbackTimers.has(id)) clearTimeout(approvalFeedbackTimers.get(id));
  approvalFeedbackTimers.delete(id);
  approvalStates[id] = state;
  if (state === 'success') {
    approvalFeedbackTimers.set(id, setTimeout(() => {
      if (approvalStates[id] === 'success') approvalStates[id] = '';
      approvalFeedbackTimers.delete(id);
    }, 1700));
  }
}
const overtimeDraft = ref('');
const overtimeSaveState = ref('');
function draftKey(term, kind) { return `${term.term_id}:${kind}`; }
function draftFor(term, kind='hours') {
  const key = draftKey(term, kind);
  return approvalDrafts[key] ?? String(kind==='hours' ? term.confirmed_hours ?? 0 : term.overtime_hours ?? 0);
}
function setDraft(term, kind, value) {
  const key = draftKey(term, kind);
  approvalDrafts[key] = value;
  if (approvalStates[term.term_id] === 'error' || approvalStates[term.term_id] === 'success') feedback(term.term_id, '');
}
function applyApprovedHours(id, values) {
  const entry = card.timesheets.find(row => Number(row.term_id) === Number(id));
  if (!entry) return;
  const prevRegular = Number(entry.confirmed_hours || 0);
  const prevOvertime = Number(entry.approved_overtime_hours || 0);
  const previousAmount = entry.confirmed_amount;
  entry.confirmed_hours = Number(values.hours);
  entry.approved_overtime_hours = Number(values.overtime_hours);
  entry.confirmed_total_hours = Math.round((entry.confirmed_hours+entry.approved_overtime_hours)*100)/100;
  if (entry.hourly_rate != null) entry.confirmed_amount = Math.round(
    (entry.confirmed_hours + entry.approved_overtime_hours * Number(card.period.overtime_multiplier)) * Number(entry.hourly_rate)*100)/100;
  if (card.summary) {
    const rDelta = entry.confirmed_hours - prevRegular;
    const oDelta = entry.approved_overtime_hours - prevOvertime;
    card.summary.confirmed_hours = Math.round((Number(card.summary.confirmed_hours || 0) + rDelta)*100)/100;
    card.summary.approved_overtime_hours = Math.round((Number(card.summary.approved_overtime_hours || 0) + oDelta)*100)/100;
    card.summary.confirmed_total_hours = Math.round((Number(card.summary.confirmed_hours || 0) + Number(card.summary.approved_overtime_hours || 0))*100)/100;
    if (entry.confirmed_amount != null && previousAmount != null && card.summary.confirmed_amount != null)
      card.summary.confirmed_amount = Math.round((Number(card.summary.confirmed_amount)+entry.confirmed_amount-previousAmount)*100)/100;
  }
}
async function saveApprovedEdit(term, kind) {
  const id = term.term_id;
  if (approvalStates[id] === 'saving' || card.period?.status === 'Счет оплачен') return;
  // Save both independent fields atomically. This also avoids losing the
  // second edit if AM tabs rapidly across the two numbers.
  const values = {};
  for (const field of ['hours','overtime_hours']) {
    const raw = String(draftFor(term, field)).trim().replace(',', '.');
    const value = Number(raw);
    if (!/^\\d+(?:\\.\\d{1,2})?$/.test(raw) || !Number.isFinite(value) || value > 999999.99) {
      feedback(id, 'error'); error.value='Введите корректное количество согласованных часов.';
      return;
    }
    values[field]=value;
  }
  if (values.hours === Number(term.confirmed_hours)
      && values.overtime_hours === Number(term.overtime_hours)) { feedback(id, ''); return; }
  const periodId=card.period?.id;
  feedback(id,'saving');error.value='';
  try {
    const result=await api(`/api/clients/reporting-periods/${periodId}/approved-hours/${id}`,
      {method:'PATCH',body:JSON.stringify(values)});
    if(card.period?.id!==periodId)return;
    applyApprovedHours(id,result.data);
    approvalDrafts[draftKey(term,'hours')]=String(values.hours);
    approvalDrafts[draftKey(term,'overtime_hours')]=String(values.overtime_hours);
    feedback(id,'success');
  } catch(e) {feedback(id,'error');error.value=e.message||String(e);}
}
async function saveOvertimeMultiplier() {
  if (!card.period || card.period.status === 'Счет оплачен' || overtimeSaveState.value === 'saving') return;
  const raw = String(overtimeDraft.value).trim().replace(',', '.');
  const multiplier = Number(raw);
  if (!/^\\d+(?:\\.\\d{1,3})?$/.test(raw) || !Number.isFinite(multiplier) || multiplier<=0 || multiplier>99999.999) {
    overtimeSaveState.value='error'; error.value='Введите корректный положительный коэффициент переработок.';
    return;
  }
  if (multiplier === Number(card.period.overtime_multiplier)) { overtimeSaveState.value=''; return; }
  const periodId = card.period.id;
  overtimeSaveState.value='saving'; error.value='';
  try {
    const result = await api(`/api/clients/reporting-periods/${periodId}/overtime-multiplier`,
      {method:'PATCH',body:JSON.stringify({overtime_multiplier:multiplier})});
    if (card.period?.id !== periodId) return;
    card.period.overtime_multiplier=result.data.overtime_multiplier;
    let sum=0;
    let missing=false;
    for(const row of card.timesheets){
      if(row.hourly_rate==null){missing=true;continue;}
      row.confirmed_amount=Math.round(
        (Number(row.confirmed_hours)+Number(row.approved_overtime_hours)*multiplier)*Number(row.hourly_rate)*100)/100;
      sum+=row.confirmed_amount;
    }
    if(card.summary)card.summary.confirmed_amount=missing?null:Math.round(sum*100)/100;
    overtimeDraft.value=String(multiplier);overtimeSaveState.value='success';
  }catch(e){overtimeSaveState.value='error';error.value=e.message||String(e);}
}
const allTimesheetsApproved = computed(() => Number(card.summary.worked_hours || 0) > 0 && card.timesheets.filter(row => Number(row.worked_hours || 0) > 0).every(row => row.account_confirmed));

async function api(url, options={}) {
  const response = await fetch(url, { ...options, headers: { Accept:'application/json','Content-Type':'application/json',...(options.headers||{}) } });
  const body = await response.json().catch(()=>({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors||{}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

async function openPeriod(period) {
  selectedId.value = Number(period.id);
  activeTab.value = rememberedView('report.card.tab', 'timesheets', ['timesheets','history']);
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
    overtimeDraft.value=String(card.period?.overtime_multiplier ?? 1);
    overtimeSaveState.value='';
    Object.keys(approvalDrafts).forEach(k=>delete approvalDrafts[k]);
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
  if (!period || saving.value || loadingCard.value || period.status === 'Счет оплачен' || stage === 'Счет оплачен') return false;
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
const yearOptions = computed(() => years.value.map(year => ({ value: String(year), label: String(year) })));
const months = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
const ganttClients = computed(() => props.clients.filter(client => props.periods.some(p => Number(p.client_id)===Number(client.id) && Number(String(p.period_start).slice(0,4))<=ganttYear.value && Number(String(p.period_end).slice(0,4))>=ganttYear.value)));
function periodsInMonth(clientId, monthIndex) {
  const from = `${ganttYear.value}-${String(monthIndex+1).padStart(2,'0')}-01`;
  const endDate = new Date(ganttYear.value, monthIndex+1, 0);
  const to = `${ganttYear.value}-${String(monthIndex+1).padStart(2,'0')}-${String(endDate.getDate()).padStart(2,'0')}`;
  return props.periods.filter(p => Number(p.client_id)===Number(clientId) && String(p.period_start).slice(0,10)<=to && String(p.period_end).slice(0,10)>=from);
}
// Дата последней отправки акта — единая точка отсчёта обоих сроков.
function deadlinePassed(start, days, dayType='working') {
  if (!start || days === null || days === undefined || days === '' || !Number.isInteger(Number(days)) || Number(days) < 0) return false;
  const date = new Date(String(start).slice(0,10) + 'T00:00:00Z');
  if (Number.isNaN(date.getTime())) return false;
  let remaining = Number(days);
  if (dayType !== 'calendar' && !productionDays[date.getUTCFullYear()]) return false;
  while (remaining > 0) {
    date.setUTCDate(date.getUTCDate()+1);
    if (dayType === 'calendar') remaining--;
    else {
      const day = productionDays[date.getUTCFullYear()]?.[date.toISOString().slice(0,10)];
      if (!day) return false; // Never invent holidays or assume Monday–Friday.
      if (day.is_working) remaining--;
    }
  }
  return todayLocal() > date.toISOString().slice(0,10);
}
function periodTone(period) {
  if (period.status === 'Счет оплачен' || period.paid_at) return 'paid';
  const client = props.clients.find(item => Number(item.id) === Number(period.client_id));
  if (client && period.act_sent_at && (
    (!period.act_approved_at && deadlinePassed(period.act_sent_at, client.act_approval_days, client.act_approval_day_type))
    || (!period.paid_at && deadlinePassed(period.act_sent_at, client.payment_days, client.payment_day_type))
  )) return 'overdue';
  return 'progress';
}
function stageDateLabel(period) {
  if (period.paid_at) return `Оплачено: ${dateRu(period.paid_at)}`;
  if (period.act_approved_at) return `Акт согласован: ${dateRu(period.act_approved_at)}`;
  if (period.timesheets_approved_at) return `ТШ согласованы: ${dateRu(period.timesheets_approved_at)}`;
  return '';
}
</script>

<template>
  <div class="reports-view" :class="{ 'reports-view--kanban': mode==='kanban' }">
    <UiFilterBar v-if="mode==='kanban'" class="irlix-kanban-filters"><UiSearchSelect v-model="reportColumns" :options="stages" placeholder="Столбцы" multiple/></UiFilterBar>
    <UiKanbanBoard v-if="mode==='kanban'" class="reports-kanban" :columns="visibleReportColumns" :items="periods" :active-target="dropStage" label="Канбан отчетных периодов" @dragover="overPeriodColumn" @drop="dropPeriod" @dragleave="dropStage=''">
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
      
      <div class="reports-gantt">
        <div class="gantt-head client-col">Клиент</div><div v-for="month in months" :key="month" class="gantt-head">{{month}}</div>
        <template v-for="client in ganttClients" :key="client.id">
          <div class="gantt-client">{{client.name}}</div>
          <div v-for="(_,monthIndex) in months" :key="monthIndex" class="gantt-cell">
            <button v-for="period in periodsInMonth(client.id,monthIndex)" :key="period.id" type="button" class="gantt-period" :class="`gantt-period--${periodTone(period)}`" :title="`${dateRu(period.period_start)} – ${dateRu(period.period_end)} · ${period.status}`" @click="openPeriod(period)"><span class="gantt-period-dates">{{dateRu(period.period_start)}} – {{dateRu(period.period_end)}}</span><span class="gantt-period-status">{{period.status}}</span></button>
          </div>
        </template>
      </div>
      <div v-if="!ganttClients.length" class="empty">За {{ganttYear}} год отчётных периодов нет</div>
    </div>

    <UiDrawer :open="!!selectedId" :title="card.period ? `ОП ${card.client?.name||''} ${dateRu(card.period.period_start)} - ${dateRu(card.period.period_end)}` : 'Отчётный период'" width="660px" :min-width="520" @close="closeCard">
      <template #actions><div v-if="card.period" class="period-menu-wrap">
        <button type="button" class="period-menu-trigger" aria-label="Действия с отчётным периодом" :aria-expanded="menuOpen" @click="menuOpen=!menuOpen">···</button>
        <div v-if="menuOpen" class="period-menu">
          <span v-if="card.period.status==='Акт согласован'" class="stage-hint">Финальное подтверждение оплаты выполняется в Finance.</span>
          <button v-if="nextStage" type="button" :disabled="saving || !canAdvance" :title="!canAdvance ? (card.period.status==='Новый' ? 'Все заполненные ТШ должны быть подтверждены аккаунт-менеджером' : 'Не заполнена дата предыдущего этапа') : ''" @click="openStageDialog"><span aria-hidden="true">✓</span>{{nextStage.label}}</button>
          <button v-if="previousStage && card.can_rollback" type="button" :disabled="saving" @click="rollbackPeriod"><span aria-hidden="true">↶</span>Вернуть: {{previousStage}}</button>
          <button v-if="card.period.status !== 'Счет оплачен'" type="button" class="period-menu-delete" :disabled="saving" @click="deletePeriod"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v6m4-6v6"/></svg>Удалить</button>
        </div>
      </div></template>
      <div v-if="error" class="drawer-error">{{error}}</div>
      <div v-if="loadingCard" class="empty">Загрузка…</div>
      <div v-else-if="card.period" class="period-drawer" :class="{ 'period-drawer--history': activeTab==='history' }">
        <div class="period-summary">
          <div class="summary-row"><span>Период</span><b>{{dateRu(card.period.period_start)}} — {{dateRu(card.period.period_end)}}</b></div>
          <div class="summary-row"><span>Аккаунт</span><b>{{employeeName(card.client?.account_employee_id)}}</b></div>
          <div class="summary-row"><span>Срок согласования</span><b>{{card.client?.act_approval_days == null ? '—' : `${card.client.act_approval_days} ${card.client?.act_approval_day_type==='calendar'?'календарных':'рабочих'} дней`}}</b></div>
          <div class="summary-row"><span>Коэффициент переработок</span><div class="period-coefficient"><input v-model="overtimeDraft" type="text" inputmode="decimal" aria-label="Коэффициент переработок периода" :disabled="card.period.status==='Счет оплачен'||overtimeSaveState==='saving'" @keydown.enter.prevent="$event.target.blur()" @blur="saveOvertimeMultiplier" /><span v-if="overtimeSaveState==='saving'" class="ts-save-spinner" aria-label="Сохранение"></span><span v-else-if="overtimeSaveState==='success'" class="ts-save-success">✓</span><span v-else-if="overtimeSaveState==='error'" class="ts-save-error">!</span></div></div>
          <div class="summary-row"><span>Срок оплаты</span><b>{{card.client?.payment_days == null ? '—' : `${card.client.payment_days} ${card.client?.payment_day_type==='calendar'?'календарных':'рабочих'} дней`}}</b></div>
          <div class="summary-row"><span>Статус</span><div><UiBadge tone="info">{{card.period.status}}</UiBadge></div></div>
          <div class="summary-row"><span>ТШ отправлены / согласованы</span><b>{{dateRu(card.period.timesheets_sent_at)}} / {{dateRu(card.period.timesheets_approved_at)}}</b></div>
          <div class="summary-row"><span>Акт отправлен / согласован</span><b>{{dateRu(card.period.act_sent_at)}} / {{dateRu(card.period.act_approved_at)}}</b></div>
          <div class="summary-row"><span>Счёт оплачен</span><b>{{dateRu(card.period.paid_at)}}</b></div>
          <div class="summary-row"><span>Согласовано / отработано</span><b>{{num(card.summary.confirmed_hours)}} + {{num(card.summary.approved_overtime_hours)}} / {{num(card.summary.worked_hours)}} ч.</b></div>
          <div class="summary-row"><span>Сумма</span><b>{{money(card.summary.worked_amount)}} / {{money(card.summary.confirmed_amount)}}</b></div>
        </div>
        <p v-if="card.period.status==='Новый' && !card.summary.worked_hours" class="stage-hint">Для отправки на согласование сначала заполните ТШ этого клиента за отчётный период.</p>
        <p v-else-if="card.period.status==='Новый' && !canAdvance" class="stage-hint">Перед отправкой клиенту подтвердите все ТШ отчётного периода на странице управления.</p>
        <p v-else-if="nextStage && !canAdvance" class="stage-hint">Для следующего этапа нужна дата текущего статуса. Руководитель может откатить период и повторить переход.</p>
        <UiTabs v-model="activeTab" variant="card" class="period-tabs" :style="{ '--irlix-card-tabs-count': 2 }" :items="[{value:'history', label:'История', iconName:'list'},{value:'timesheets',label:'Таймшиты',iconName:'file-text',complete:allTimesheetsApproved}]" />
        <div v-if="activeTab==='history'" class="period-history-tab"><HistoryTimeline :events="card.history || []" :busy="saving" :editable="true" @submit="addHistoryNote" /></div>
        <div v-else class="timesheets-table">
          <div class="ts-head"><span>Сотрудник</span><span>Срок ставки</span><span>Ставка, ₽/ч</span><span>Согласовано / Отработано</span></div>
          <div class="ts-total"><span></span><span></span><span></span><div><strong>{{num(card.summary.confirmed_hours)}} + {{num(card.summary.approved_overtime_hours)}} / {{num(card.summary.worked_hours)}} ч.</strong><small>{{num(card.summary.confirmed_amount)}} / {{money(card.summary.worked_amount)}}</small></div></div>
          <div v-for="row in groupedTimesheets" :key="row.key" class="ts-row ts-grouped-row">
            <div class="ts-employee">
              <div class="ts-employee-title"><a v-if="row.employee_id" :href="timesheetLink(row)" class="ts-employee-name" :title="row.employee_name"><strong>{{shortEmployeeName(row.employee_name)}}</strong></a><strong v-else class="ts-employee-name">{{shortEmployeeName(row.employee_name)}}</strong><UiStatusCheck v-if="row.worked_hours > 0 && row.account_confirmed" label="Все таймшиты специалиста подтверждены" /></div>
              <small>{{assignmentLabel(row)}}</small>
            </div>
            <div class="ts-period-column"><div v-for="term in row.rates" :key="term.term_id" class="ts-term-line">{{dateRu(term.from)}} — {{term.to ? dateRu(term.to) : 'по н.в.'}}</div></div>
            <div class="ts-rate-column"><div v-for="term in row.rates" :key="term.term_id" class="ts-term-line">{{num(term.rate)}}</div></div>
            <div class="ts-hours-column">
              <div v-for="term in row.rates" :key="term.term_id" class="ts-term-line ts-hours-line">
                <input :value="draftFor(term,'hours')" type="text" inputmode="decimal" class="ts-approved-input" :aria-label="`Основные согласованные часы, ставка ${num(term.rate)}`" :disabled="card.period?.status === 'Счет оплачен' || approvalStates[term.term_id] === 'saving'" @input="setDraft(term,'hours',$event.target.value)" @keydown.enter.prevent="$event.target.blur()" @blur="saveApprovedEdit(term,'hours')" />
                <span> + </span>
                <input :value="draftFor(term,'overtime_hours')" type="text" inputmode="decimal" class="ts-approved-input" :aria-label="`Согласованные переработки, ставка ${num(term.rate)}`" :disabled="card.period?.status === 'Счет оплачен' || approvalStates[term.term_id] === 'saving'" @input="setDraft(term,'overtime_hours',$event.target.value)" @keydown.enter.prevent="$event.target.blur()" @blur="saveApprovedEdit(term,'overtime_hours')" />
                <span> / {{num(term.worked_hours)}} ч.</span>
                <span v-if="approvalStates[term.term_id] === 'saving'" class="ts-save-spinner" aria-label="Сохранение" title="Сохранение"></span>
                <span v-else-if="approvalStates[term.term_id] === 'success'" class="ts-save-success" role="status" title="Сохранено">✓</span>
                <span v-else-if="approvalStates[term.term_id] === 'error'" class="ts-save-error" role="status" title="Не сохранено">!</span>
              </div>
              <small class="ts-amount-total">{{num(row.confirmed_amount)}} / {{money(row.worked_amount)}}</small>
            </div>
          </div>
          <div v-if="!groupedTimesheets.length" class="empty">В выбранном периоде нет записей таймшитов</div>
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
.reports-view{padding:14px 16px 30px}.reports-view--kanban{padding:0}.reports-gantt-wrap{padding-top:2px}.gantt-toolbar{display:flex;justify-content:flex-end;margin-bottom:8px}.gantt-toolbar label{display:flex;align-items:center;gap:6px;font-size:var(--irlix-font-size-caption)}.gantt-year-select{width:110px}.reports-gantt{display:grid;grid-template-columns:minmax(140px,1.6fr) repeat(12,minmax(0,1fr));width:100%;border-top:1px solid var(--irlix-color-border);border-left:1px solid var(--irlix-color-border)}.gantt-head,.gantt-client,.gantt-cell{min-width:0;border-right:1px solid var(--irlix-color-border);border-bottom:1px solid var(--irlix-color-border)}.gantt-head{padding:7px 2px;text-align:center;font-size:var(--irlix-font-size-caption);font-weight:700;background:var(--irlix-color-surface-muted)}.gantt-head.client-col{text-align:left;padding-left:8px}.gantt-client{padding:8px;font-size:var(--irlix-font-size-caption);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.gantt-cell{min-height:58px;padding:0;display:flex;flex-direction:column;gap:0}.gantt-period{flex:1;width:100%;min-height:58px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;border:0;border-radius:0;background:var(--irlix-status-info-bg);color:var(--irlix-color-text);padding:5px 2px;font-size:var(--irlix-font-size-caption);line-height:1.15;overflow-wrap:anywhere;text-align:center;cursor:pointer}.gantt-period+.gantt-period{border-top:1px solid var(--irlix-color-border)}.gantt-period--paid{background:var(--irlix-status-success-bg)}.gantt-period--overdue{background:var(--irlix-status-danger-bg)}.gantt-period-dates{font-size:10px;font-weight:600}.gantt-period-status{font-size:11px}.drawer-error{padding:9px 11px;margin-bottom:10px;border:1px solid var(--irlix-status-danger-border);border-radius:7px;background:var(--irlix-color-surface-muted);color:var(--irlix-status-danger-text);font-size:var(--irlix-font-size-caption)}.period-drawer{padding:2px 8px 24px}.period-summary{display:grid}.summary-row{display:grid;grid-template-columns:170px minmax(0,1fr);align-items:center;gap:10px;min-height:42px;font-size:var(--irlix-font-size-table)}.summary-row>span{color:var(--irlix-color-text)}.summary-row b{font-weight:500}.summary-row>div{display:flex;align-items:center;gap:6px;min-width:0}.summary-row input{height:31px;border:1px solid var(--irlix-color-border);border-radius:7px;padding:5px 7px}.summary-row button,.pencil{border:0;background:transparent;color:var(--irlix-color-primary-text);cursor:pointer}.period-range{flex-wrap:wrap}.period-tabs{margin-top:8px;width:100%}
.notes-placeholder,.empty{padding:28px 10px;text-align:center;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}.timesheets-table{margin-top:10px}.ts-head,.ts-row,.ts-total{display:grid;grid-template-columns:minmax(0,1fr) minmax(90px,100px) minmax(112px,125px) minmax(120px,135px);align-items:center;gap:8px;padding:9px;border-bottom:1px solid var(--irlix-color-border);font-size:var(--irlix-font-size-caption)}.ts-head{background:var(--irlix-color-surface-muted);font-weight:700;color:var(--irlix-color-text)}.ts-total{background:var(--irlix-color-surface-muted)}.ts-total>div{display:grid;gap:2px;text-align:center}.ts-total small{color:var(--irlix-color-text-muted)}.ts-row>div{display:grid;gap:2px}.ts-row small{color:var(--irlix-color-text-muted)}.timesheets-table{overflow-x:auto}.ts-head,.ts-row,.ts-total{min-width:590px}.ts-head>:not(:first-child),.ts-row>:not(:first-child),.ts-total>:not(:first-child){text-align:center;justify-self:stretch;white-space:nowrap}.ts-row>div:not(:first-child){justify-items:center}.ts-employee{min-width:0}.ts-employee-title{display:flex;align-items:center;gap:5px;flex-wrap:nowrap;min-width:0}.ts-employee-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ts-employee-title .create-form{display:grid;gap:14px;padding:4px 6px 24px}.create-form>label{display:grid;gap:6px;font-size:var(--irlix-font-size-caption);color:var(--irlix-color-text)}.range-value{display:flex;justify-content:space-between;gap:10px;font-size:var(--irlix-font-size-caption)}.calendar{border:1px solid var(--irlix-color-border);border-radius:10px;padding:10px}.calendar.disabled{opacity:.6}.calendar header{display:grid;grid-template-columns:32px 1fr 32px;align-items:center;text-align:center;margin-bottom:8px}.calendar header button{border:0;background:transparent;font-size:var(--irlix-font-size-page-title)}.calendar header strong{text-transform:capitalize;font-size:var(--irlix-font-size-table)}.week,.days{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}.week span{text-align:center;font-size:var(--irlix-font-size-caption);color:var(--irlix-color-text-muted);padding:4px}.days button{aspect-ratio:1;border:0;border-radius:7px;background:transparent;font:inherit;font-size:var(--irlix-font-size-caption);cursor:pointer}.days button:hover:not(:disabled){background:var(--irlix-color-surface-muted)}.days button.blocked{background:var(--irlix-color-surface-muted);color:var(--irlix-color-text-muted);text-decoration:line-through}.days button.inrange{background:var(--irlix-color-surface-muted)}.days button.selected{background:var(--irlix-color-primary);color:var(--irlix-color-on-accent);font-weight:700}.calendar-hint{margin:-5px 0 0;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}.create-actions{display:flex;justify-content:flex-end}@media(max-width:1200px){.reports-gantt{grid-template-columns:minmax(120px,1.4fr) repeat(12,minmax(50px,1fr))}.gantt-period{font-size:var(--irlix-font-size-caption)}}@media(max-width:720px){.reports-view{padding:10px}.reports-view--kanban{padding:0}.summary-row{grid-template-columns:120px 1fr}.ts-head,.ts-row,.ts-total{grid-template-columns:minmax(0,1fr) 100px 125px 135px}}
.period-menu-wrap{position:relative}.period-menu-trigger{border:0;border-radius:7px;background:var(--irlix-color-surface-muted);color:var(--irlix-color-text);width:30px;height:28px;font-weight:700;letter-spacing:1px;cursor:pointer}.period-menu{position:absolute;right:0;top:calc(100% + 6px);z-index:5;min-width:225px;padding:5px;border:1px solid var(--irlix-color-border);border-radius:10px;background:var(--irlix-color-surface-muted);box-shadow:0 4px 14px color-mix(in srgb, var(--irlix-color-shadow-base) 12%, transparent)}.period-menu button{display:flex;align-items:center;gap:9px;width:100%;min-height:36px;padding:7px 10px;border:0;border-radius:7px;background:transparent;text-align:left;font:inherit;font-size:var(--irlix-font-size-caption);color:var(--irlix-color-primary-text);cursor:pointer}.period-menu button:hover{background:var(--irlix-color-surface-muted)}.period-menu button:disabled{opacity:.5;cursor:default}.period-menu button span{width:16px;font-size:var(--irlix-font-size-section-title)}.period-menu .period-menu-delete{color:var(--irlix-status-danger-text)}.period-menu .period-menu-delete:hover{background:var(--irlix-color-surface-muted)}
.stage-hint{margin:8px 0;padding:9px 11px;border-radius:7px;background:var(--irlix-color-surface-muted);color:var(--irlix-status-warning-text);font-size:var(--irlix-font-size-caption)}.stage-date-form{display:grid;gap:14px;padding:8px}.stage-date-form label{display:grid;gap:7px;font-size:var(--irlix-font-size-table)}.stage-date-form input{height:36px;padding:5px 9px;border:1px solid var(--irlix-color-border);border-radius:7px;font:inherit}.stage-date-form .irlix-button{justify-self:end}
.period-summary .summary-row { grid-template-columns:205px minmax(0, 1fr); }
.ts-link { color:inherit; text-decoration:none; cursor:pointer; }
.ts-link:hover, .ts-link:focus-visible { background:var(--irlix-color-surface-muted); outline:2px solid var(--irlix-status-success-border); outline-offset:-2px; }
.all-timesheets-link{display:flex;justify-content:flex-end;padding:12px 8px 0}.all-timesheets-link a{color:var(--irlix-color-primary-text);font-size:var(--irlix-font-size-caption);font-weight:600;text-decoration:none}.all-timesheets-link a:hover{text-decoration:underline}
.period-drawer--history{display:flex;flex-direction:column;height:100%;min-height:0;overflow:hidden;padding-bottom:0}
.period-drawer--history .period-summary{flex:none}
.period-drawer--history .summary-row{min-height:30px}
.period-drawer--history .period-tabs{flex:none}
.period-history-tab{display:flex;flex:1 1 auto;flex-direction:column;min-height:0;overflow:hidden;padding:12px 0 16px}
.period-history-tab :deep(.client-history){flex:1 1 auto;min-height:0;height:100%;max-height:100%}
.period-history-tab :deep(.client-history__feed){min-height:0;overflow-y:auto;overscroll-behavior:contain}
:global(.irlix-drawer-layer:has(.period-history-tab) .irlix-drawer-body){display:flex;flex-direction:column;min-height:0;overflow:hidden}
:global(.irlix-drawer-layer:has(.period-history-tab) .irlix-drawer-body > .period-drawer){flex:1 1 auto;min-height:0;width:100%}
@media (max-width:720px) { .period-summary .summary-row { grid-template-columns:155px minmax(0, 1fr); } }
.ts-head,.ts-row,.ts-total{grid-template-columns:minmax(0,1fr) minmax(135px,165px) minmax(75px,95px) minmax(175px,215px);gap:6px}
.ts-row.ts-grouped-row{padding-top:5px;padding-bottom:5px}
.ts-period-column,.ts-rate-column,.ts-hours-column{display:flex!important;flex-direction:column;gap:2px;align-self:start}
.ts-term-line{height:24px;display:flex;align-items:center;justify-content:center;white-space:nowrap}
.ts-hours-line{font-weight:600;position:relative;gap:4px}
.ts-amount-total{display:block;min-height:18px;margin-top:3px;color:var(--irlix-color-text-muted);text-align:center;font-weight:400}
.period-coefficient input{width:85px;max-width:100%;text-align:left;font:inherit;color:var(--irlix-color-text);background:transparent}.ts-approved-input{appearance:none;-moz-appearance:textfield;box-sizing:content-box;width:32px;min-height:0!important;height:20px;padding:0!important;margin:0;text-align:right;font:inherit!important;line-height:20px!important;font-size:inherit!important;font-weight:inherit!important;color:inherit!important;background:transparent!important;border:1px solid transparent!important;border-radius:2px!important;box-shadow:none!important;outline:none!important}
.ts-approved-input:focus{outline:none!important;border-color:transparent!important;box-shadow:none!important}
.ts-approved-input:disabled{opacity:1;cursor:default}
.ts-save-spinner{display:inline-block;width:11px;height:11px;border:2px solid var(--irlix-color-border);border-top-color:var(--irlix-color-primary);border-radius:50%;animation:ts-saving-spin .7s linear infinite;margin-left:5px}
.ts-save-success{color:var(--irlix-color-success,var(--irlix-color-primary));margin-left:5px}
.ts-save-error{color:var(--irlix-color-danger,#c83737);margin-left:5px}
@keyframes ts-saving-spin{to{transform:rotate(360deg)}}
.ts-employee-title a{color:inherit;text-decoration:none}
.ts-employee-title a:hover{text-decoration:underline}
.ts-total>div{display:grid;gap:2px;text-align:center}
@media(max-width:720px){.ts-head,.ts-row,.ts-total{grid-template-columns:minmax(0,1fr) 135px 75px 195px}}

/* Four independent grid tracks: text never paints over the adjacent rate cell. */
.timesheets-table{container-type:inline-size;overflow-x:auto}
.ts-head,.ts-row,.ts-total{
  grid-template-columns:minmax(160px,1.15fr) minmax(95px,.85fr) minmax(76px,84px) minmax(200px,230px);
  column-gap:4px;min-width:0;
}
.ts-head>*,.ts-row>*,.ts-total>*{min-width:0;box-sizing:border-box}
.ts-head>span{text-align:center!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ts-head>span:first-child{text-align:left!important}
.ts-row>.ts-employee{min-width:0;text-align:left;justify-items:start}
.ts-employee-title{justify-content:flex-start;width:100%}
.ts-employee-name{display:block;max-width:100%}
.ts-period-column,.ts-rate-column,.ts-hours-column{min-width:0;width:100%;overflow:hidden}
.ts-period-column .ts-term-line{
  box-sizing:border-box;display:block;width:100%;min-width:0;max-width:100%;
  padding:0 2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  line-height:24px;text-align:center;
}
.ts-rate-column .ts-term-line,.ts-hours-column .ts-term-line{min-width:0;max-width:100%;text-align:center;justify-content:center}
.ts-amount-total{width:100%;text-align:center}
@container (max-width:620px){
  .ts-head,.ts-row,.ts-total{
    grid-template-columns:minmax(150px,1.05fr) minmax(65px,.65fr) minmax(70px,78px) minmax(200px,230px);
    column-gap:3px;
  }
}
</style>

