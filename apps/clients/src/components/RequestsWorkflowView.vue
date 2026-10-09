<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiFilterBar, UiSegmentedControl, UiSearchSelect, UiTreeToggle, UiKanbanBoard } from '@irlix/ui';

import AttemptProgress from './AttemptProgress.vue';
import InterviewScheduler from './InterviewScheduler.vue';
import InlineField from './InlineField.vue';
import DescriptionBlock from './DescriptionBlock.vue';
import EntityActions from './EntityActions.vue';
import RequestClientLogo from './RequestClientLogo.vue';
import { attemptEmployeeOptions, expectedConnectionLabel, positionMatchesStatus, requestDisplayStatus, requestLifetimeLabel, requestMatchesStatus, sortNewest } from '../workflow.js';

const props = defineProps({
  backgroundBlocked: Boolean,
  view: { type: String, required: true },
  mode: { type: String, default: 'tree' },
  requests: { type: Array, default: () => [] },
  clients: { type: Array, default: () => [] },
  leads: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
  access: { type: Object, default: () => ({}) },
  technologyOptions: { type: Array, default: () => [] },
  directionOptions: { type: Array, default: () => [] },
  levelOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(['changed', 'connect-attempt', 'open-client', 'card-open']);

const expandedRequests = reactive(new Set());
const expandedPositions = reactive(new Set());
const selectedRequestId = ref(null);
const selectedPositionId = ref(null);
const selectedAttemptId = ref(null);
const dialog = ref('');
const busy = ref(false);
const error = ref('');
const query = ref('');
const statusFilter = ref(['requests','positions'].includes(props.view) ? 'Открыт' : '');
const attemptStatusFilter = ref(['Новая','CV отправлено','Интервью назначено','Интервью пройдено','Ожидает подключения']);
const actionAttemptId = ref(null);
const menuAttemptId = ref(null);
const interviewPicker = ref(false);
const interviewAnchor = ref(null);
const connectionPicker = ref(false);
const connectionAnchor = ref(null);
const cvInput = ref(null);
const cvDragging = ref(false);
const draggingAttemptId = ref(null);
const dropStatus = ref('');
onBeforeUnmount(()=>emit('card-open',false));
const clientFilter = ref('');
const specialistFilter = ref('');
const technologyFilter = ref('');
const form = reactive({});
let treeInitialized = false;

const attemptStatuses = ['Новая','CV отправлено','Интервью назначено','Интервью пройдено','Ожидает подключения','Закрыт: успех','Закрыт: неудача'];
const requestMainStatuses = ['Открыт','Закрыт'];
const requestStatuses = ['Открыт','В работе','Закрыт'];
const requestStatus = requestDisplayStatus;
const positionStatuses = ['Открыт','В работе','Закрыт'];
const positionMainStatuses = ['Открыт','Закрыт'];
const positionStatus = position => position.display_status || position.status;
const directionId = position => String(position.direction_department_id || props.access.legacy_direction_ids?.[position.direction] || '');
const directionName = id => props.directionOptions.find(item => Number(item.value) === Number(id))?.label || '';
const expectedConnectionTimes = ['Неизвестно','Месяц','Квартал','Пол года','Год'];
const acceptableTuFormats = ['Не важно','Штат','Штат / ГПХ'];
const ratings = ['Положительно','Нейтрально','Отрицательно'];
const allFailureReasons = [
  'Запрос закрыт','Заведомо не подходил по уровню','Интервью: отрицательная ОС','Отказ специалиста',
  'CV: Недостаточно отраслевого опыта','Специалист уволился','Интервью: Положительная ОС без подключения',
  'CV: Нет ОС','Интервью: отменено','CV: Недостаточно ком. опыта','CV: Не пройдено',
  'CV: Недостаточно информации для положительного ответа','CV: Нет опыта в требующейся технологии',
  'Подключение не состоялось','Интервью: не прошел тестовое',
];
const productionManagerOptions=computed(()=>[...new Set(props.departments.filter(d=>d.is_production===true||Number(d.is_production)===1).map(d=>Number(d.manager_id)).filter(Boolean))].map(id=>({value:String(id),label:employeeName(id)})).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const rnId=position=>String(position.responsible_rn_employee_id||props.departments.find(d=>Number(d.id)===Number(directionId(position)))?.manager_id||'');
function assignManager(){form.responsible_rn_employee_id=String(props.departments.find(d=>Number(d.id)===Number(form.direction_department_id))?.manager_id||'');}
const lifetimeOptions=[1,2,3,4].map(value=>({value:String(value),label:`${value} ${value===1?'неделя':'недели'}`}));
const employeeOptions = computed(() => props.employees.map(item => ({ value:String(item.id), label:item.full_name || `#${item.id}` })));
const requestEmployeeOptions = computed(() => {
  const scope=props.access.permissions?.['requests.manage']?.scope||'none';
  const actorId=Number(props.access.employee?.id||0);
  const ids=scope==='all'?null:new Set((scope==='team'?props.access.team_employee_ids:[actorId]).map(Number));
  return employeeOptions.value.filter(option=>ids===null||ids.has(Number(option.value)));
});
const targetOptions = computed(() => [
  ...props.clients.map(item => ({ value:`client:${item.id}`, label:item.name, meta:'клиент' })),
  ...props.leads.filter(item => !item.converted_client_id).map(item => ({ value:`lead:${item.id}`, label:item.name, meta:'лид' })),
].sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const employeeName = id => props.employees.find(item => Number(item.id) === Number(id))?.full_name || (id ? `#${id}` : '—');
const clientById = id => props.clients.find(item => Number(item.id) === Number(id)) || null;
const clientName = id => clientById(id)?.name || `#${id}`;
const targetName = request => request?.target_name || (request?.client_id ? clientName(request.client_id) : props.leads.find(item=>Number(item.id)===Number(request?.lead_id))?.name) || '—';
const targetKey = request => request?.client_id ? `client:${request.client_id}` : `lead:${request?.lead_id}`;
const dateRu = value => value ? new Date(`${String(value).slice(0,10)}T00:00:00`).toLocaleDateString('ru-RU') : '—';
const dateTimeRu = value => value ? new Date(value).toLocaleString('ru-RU', { dateStyle:'short', timeStyle:'short' }) : '—';
const lifetimeText = request => requestLifetimeLabel(request?.lifetime_weeks, request?.deadline, dateRu);
const canManageRequests = computed(() => !!props.access.permissions?.['requests.manage']?.allowed);
const canManagePositions = computed(() => !!props.access.permissions?.['positions.manage']?.allowed);
const canManageAttempts = computed(() => !!props.access.permissions?.['attempts.manage']?.allowed);
const isAccount = canManageAttempts;
const attemptSpecialistOptions = computed(() => attemptEmployeeOptions(props.employees, props.departments, props.access.attempt_employee_ids || []));


const positions = computed(() => sortNewest(props.requests.flatMap(request => (request.positions || []).map(position => ({ ...position, request })))));
const attempts = computed(() => positions.value.flatMap(position => (position.attempts || []).map(attempt => ({ ...attempt, position, request:position.request, clientId:Number(position.request.client_id) }))));
const selectedRequest = computed(() => props.requests.find(item => Number(item.id) === Number(selectedRequestId.value)) || null);
const selectedPosition = computed(() => positions.value.find(item => Number(item.id) === Number(selectedPositionId.value)) || null);
const selectedAttempt = computed(() => attempts.value.find(item => Number(item.id) === Number(selectedAttemptId.value)) || null);
const actionAttempt = computed(() => attempts.value.find(item => Number(item.id) === Number(actionAttemptId.value)) || selectedAttempt.value);
watch(()=>!!selectedRequest.value||!!selectedPosition.value||!!selectedAttempt.value||!!dialog.value||interviewPicker.value||connectionPicker.value,open=>emit('card-open',open),{flush:'post'});
const selectedRequestClient = computed(() => selectedRequest.value ? clientById(selectedRequest.value.client_id) : null);
const selectedPositionClient = computed(() => selectedPosition.value ? clientById(selectedPosition.value.request.client_id) : null);
const positionDrawerTitle = computed(() => selectedPosition.value ? `${selectedPosition.value.request.title} - ${selectedPosition.value.technology || 'Технология не указана'} - ${selectedPosition.value.level}` : '');
const filteredPositions = computed(() => positions.value.filter(item => {
  const needle = query.value.trim().toLowerCase();
  return (!needle || `${item.technology} ${item.level} ${item.direction || ''}`.toLowerCase().includes(needle))
    && positionMatchesStatus(item, statusFilter.value)
    && (!clientFilter.value || targetKey(item.request) === clientFilter.value);
}));
const filteredAttempts = computed(() => attempts.value.filter(item => {
  const needle = query.value.trim().toLowerCase();
  return (!needle || `${item.specialist_name} ${item.position.technology} ${item.position.level}`.toLowerCase().includes(needle))
    && (!statusFilter.value || item.status === statusFilter.value)
    && (!clientFilter.value || targetKey(item.request) === clientFilter.value)
    && (!technologyFilter.value || item.position.technology === technologyFilter.value)
    && (!specialistFilter.value || (item.specialist_id ? 'employee:'+item.specialist_id : 'external:'+item.id) === specialistFilter.value);
}));
const specialistOptions = computed(() => [...new Map(attempts.value.map(item => [String(item.id), { value:item.specialist_id ? 'employee:'+item.specialist_id : 'external:'+item.id, label:item.specialist_name }])).values()].filter((item,index,all) => all.findIndex(other => other.value === item.value) === index));
const attemptTechnologyOptions = computed(() => [...new Set(attempts.value.map(item => item.position.technology).filter(Boolean))]
  .sort((a,b) => String(a).localeCompare(String(b), 'ru'))
  .map(value => ({ value, label:value })));
const filteredRequests = computed(() => sortNewest(props.requests).filter(item => {
  const needle = query.value.trim().toLowerCase();
  return (!needle || `${item.title} ${targetName(item)}`.toLowerCase().includes(needle))
    && requestMatchesStatus(item, statusFilter.value)
    && (!clientFilter.value || targetKey(item) === clientFilter.value);
}));
const availableFailureReasons = computed(() => allFailureReasons.filter(reason => {
  if (reason.startsWith('CV:')) return actionAttempt.value?.status === 'CV отправлено';
  if (reason.startsWith('Интервью:')) return actionAttempt.value?.status === 'Интервью назначено';
  return true;
}));

function openClient(request){if(request.client_id)emit('open-client',Number(request.client_id));}
function toggle(set, id) { set.has(Number(id)) ? set.delete(Number(id)) : set.add(Number(id)); }
function tone(status='') {
  if (status === 'Открыт') return 'info';
  if (status === 'Закрыт') return 'neutral';
  return status.includes('неудач') ? 'danger' : status.includes('успех') ? 'success' : status.includes('Интервью') ? 'info' : 'neutral';
}
function resetForm(values={}) { Object.keys(form).forEach(key => delete form[key]); Object.assign(form, values); error.value = ''; }
function openRequest(item) { selectedRequestId.value = Number(item.id); resetForm({ title:item.title, description:item.description || '', responsible_employee_id:String(item.responsible_employee_id), request_date:String(item.request_date || item.created_at || '').slice(0,10), lifetime_weeks:String(item.lifetime_weeks || 1), status:item.status }); }
function openPosition(item) { selectedPositionId.value = Number(item.id); resetForm({ direction_department_id:directionId(item), technology:item.technology, level:item.level, quantity:item.quantity, expected_connection_time:item.expected_connection_time || 'Неизвестно', acceptable_tu_format:item.acceptable_tu_format || 'Не важно', description:item.description || '', status:item.status }); }
function openAttempt(item) { actionAttemptId.value = null; menuAttemptId.value = null; selectedAttemptId.value = Number(item.id); resetForm(); }
function openDialog(kind, values={}) { dialog.value = kind; resetForm(values); }
function closeDialog() { dialog.value = ''; interviewPicker.value = false; connectionPicker.value = false; actionAttemptId.value = null; resetForm(); }
function openAttemptDialog(kind, attempt, values={}) { actionAttemptId.value = Number(attempt.id); openDialog(kind, values); }
function startInterview(attempt, event) { actionAttemptId.value = Number(attempt.id); interviewAnchor.value = event?.currentTarget?.getBoundingClientRect(); error.value = ''; interviewPicker.value = true; menuAttemptId.value = null; }
function startConnection(attempt, event) { actionAttemptId.value = Number(attempt.id); connectionAnchor.value = event?.currentTarget?.getBoundingClientRect(); error.value = ''; connectionPicker.value = true; menuAttemptId.value = null; }
const visibleAttempts = position => (position.attempts || []).filter(attempt => !attemptStatusFilter.value.length || attemptStatusFilter.value.includes(attempt.status));
const sortedPositions = request => sortNewest(request.positions || []);
watch(() => props.view, view => { statusFilter.value = ['requests','positions'].includes(view) ? 'Открыт' : ''; attemptStatusFilter.value = view === 'requests' ? ['Новая','CV отправлено','Интервью назначено','Интервью пройдено','Ожидает подключения'] : []; query.value = ''; menuAttemptId.value = null; });
function openCreatePosition(request) { openDialog('create-position', { request_id:Number(request.id), quantity:1, expected_connection_time:'Неизвестно', acceptable_tu_format:'Не важно' }); }

watch(() => props.requests, (requests) => {
  if (treeInitialized || !requests.length) return;
  requests.forEach((request) => {
    expandedRequests.add(Number(request.id));
    (request.positions || []).forEach(position => expandedPositions.add(Number(position.id)));
  });
  treeInitialized = true;
}, { immediate:true });

async function api(url, options={}) {
  const isForm = options.body instanceof FormData;
  const response = await fetch(url, { ...options, headers:{ Accept:'application/json', ...(isForm ? {} : {'Content-Type':'application/json'}), ...(options.headers || {}) } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}
async function run(action) {
  busy.value = true; error.value = '';
  try { await action(); closeDialog(); emit('changed'); }
  catch (exception) { error.value = exception.message || String(exception); }
  finally { busy.value = false; }
}
function createPosition() { return run(() => api(`/api/clients/requests/${Number(form.request_id || selectedRequest.value?.id)}/positions`, { method:'POST', body:JSON.stringify({ direction_department_id:Number(form.direction_department_id), direction:directionName(form.direction_department_id), technology:form.technology, level:form.level, quantity:Number(form.quantity || 1), expected_connection_time:form.expected_connection_time || 'Неизвестно', acceptable_tu_format:form.acceptable_tu_format || 'Не важно', description:form.description || null, responsible_rn_employee_id:Number(form.responsible_rn_employee_id)||null }) })); }
function createAttempt() {
  const specialist = props.employees.find(item => Number(item.id) === Number(form.specialist_id));
  const body = new FormData();
  body.append('is_external', form.is_external ? '1' : '0');
  if (!form.is_external) body.append('specialist_id', form.specialist_id);
  body.append('specialist_name', form.is_external ? String(form.specialist_name || '').trim() : specialist?.full_name || `#${form.specialist_id}`);
  if (form.description) body.append('description', form.description);
  if (form.cv) body.append('cv', form.cv);
  return run(() => api(`/api/clients/positions/${selectedPosition.value.id}/attempts`, { method:'POST', body }));
}
async function downloadCv(attempt=selectedAttempt.value) {
  error.value = '';
  try {
    const response = await fetch(`/api/clients/attempts/${attempt.id}/cv`, { headers:{ Accept:'application/octet-stream' } });
    if (!response.ok) throw new Error('Не удалось скачать CV');
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url; link.download = attempt.cv_original_name || 'cv'; link.click();
    URL.revokeObjectURL(url);
  } catch (exception) { error.value = exception.message || String(exception); }
}
function sendCv() { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/send-cv`, { method:'POST', body:'{}' })); }
function scheduleInterview(scheduled_at) { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/interviews`, { method:'POST', body:JSON.stringify({ scheduled_at }) })); }
function completeInterview() { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/interviews/${form.interview_id}/complete`, { method:'POST', body:JSON.stringify({ rating:form.rating, feedback:form.feedback }) })); }
function scheduleConnection(connection_date) { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/schedule-connection`, { method:'POST', body:JSON.stringify({ connection_date }) })); }
function closeSuccess() { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/close-success`, { method:'POST', body:'{}' })); }
function closeFailure() { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/close-failure`, { method:'POST', body:JSON.stringify({ reasons:form.reasons || [] }) })); }
async function deleteAttempt() {
  if (!window.confirm('Удалить новую попытку?')) return;
  busy.value = true; error.value = '';
  try {
    await api(`/api/clients/attempts/${actionAttempt.value.id}`, { method:'DELETE' });
    selectedAttemptId.value = null;
    emit('changed');
  } catch (exception) { error.value = exception.message || String(exception); }
  finally { busy.value = false; }
}
async function saveField(entity,id,key,value,done){
  busy.value=true;error.value='';
  const numeric=['responsible_employee_id','responsible_rn_employee_id','quantity','lifetime_weeks','direction_department_id'];
  const payload={[key]:numeric.includes(key)?(Number(value)||null):key==='description'?(value||null):value};
  try{await api(`/api/clients/${entity}/${id}`,{method:'PATCH',body:JSON.stringify(payload)});done(true);emit('changed');}
  catch(exception){error.value=exception.message||String(exception);done(false);}finally{busy.value=false;}
}
function requestActions(request){return canManageRequests.value?[{key:request.status==='Закрыт'?'open':'close',label:request.status==='Закрыт'?'Открыть запрос':'Закрыть запрос',danger:request.status!=='Закрыт'}]:[];}
function positionActions(position){if(!canManagePositions.value||(position.status==='Закрыт'&&position.request?.status==='Закрыт'))return [];return [{key:position.status==='Закрыт'?'open':'close',label:position.status==='Закрыт'?'Открыть позицию':'Закрыть позицию',danger:position.status!=='Закрыт'}];}
function parentAction(entity,item,action){return run(()=>api(`/api/clients/${entity}/${item.id}`,{method:'PATCH',body:JSON.stringify({status:action.key==='open'?'Открыт':'Закрыт'})}));}
function closeRequest() { return run(() => api(`/api/clients/requests/${selectedRequest.value.id}`, { method:'PATCH', body:JSON.stringify({ status:'Закрыт' }) })); }
function closePosition() { return run(() => api(`/api/clients/positions/${selectedPosition.value.id}`, { method:'PATCH', body:JSON.stringify({ status:'Закрыт' }) })); }
function selectCv(file) {
  if (!file) return;
  if (!/\.(pdf|doc|docx)$/i.test(file.name) || file.size > 15 * 1024 * 1024) { error.value = 'Выберите CV в формате PDF, DOC или DOCX, не более 15 МБ.'; return; }
  form.cv = file; error.value = ''; cvDragging.value = false;
}
function dropCv(event) { cvDragging.value = false; selectCv(event.dataTransfer?.files?.[0]); }
function startAttemptDrag(event, attempt) {
  if (!canManageAttempts.value || busy.value || attempt.status.startsWith('Закрыт')) { event.preventDefault(); return; }
  draggingAttemptId.value = Number(attempt.id);
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', String(attempt.id));
}
function endAttemptDrag() { draggingAttemptId.value = null; dropStatus.value = ''; }
function overAttemptColumn(event, status) {
  const attempt = attempts.value.find(item => Number(item.id) === draggingAttemptId.value);
  if (!attempt || !allowedDrop(attempt, status)) { dropStatus.value = ''; return; }
  event.preventDefault();
  event.dataTransfer.dropEffect = 'move';
  dropStatus.value = status;
}
function allowedDrop(attempt, status) {
  if (!canManageAttempts.value || busy.value || !attempt || attempt.status === status || attempt.status.startsWith('Закрыт')) return false;
  if (status === 'Закрыт: неудача') return true;
  if (attempt.status === 'Новая') return status === 'CV отправлено';
  if (attempt.status === 'CV отправлено') return status === 'Интервью назначено';
  if (attempt.status === 'Интервью назначено') return status === 'Интервью пройдено' && (attempt.interviews || []).some(interview => !interview.completed_at);
  if (attempt.status === 'Интервью пройдено') return status === 'Ожидает подключения';
  return attempt.status === 'Ожидает подключения' && status === 'Закрыт: успех';
}
function dropAttempt(event, status) {
  event.preventDefault();
  const attempt = attempts.value.find(item => Number(item.id) === draggingAttemptId.value);
  endAttemptDrag();
  if (!allowedDrop(attempt, status)) return;
  if (status === 'Закрыт: неудача') return openAttemptDialog('close-failure', attempt, { reasons:[] });
  if (status === 'CV отправлено') { actionAttemptId.value = attempt.id; return sendCv(); }
  if (status === 'Интервью назначено') return startInterview(attempt);
  if (status === 'Интервью пройдено') {
    const interview = latestPendingInterview(attempt);
    return interview && openAttemptDialog('complete-interview',attempt,{ interview_id:interview.id,rating:'Положительно' });
  }
  if (status === 'Ожидает подключения') return startConnection(attempt);
  if (status === 'Закрыт: успех') { actionAttemptId.value = attempt.id; return closeSuccess(); }
}
function toggleExternal() { form.specialist_id = ''; form.specialist_name = ''; }
const attemptFormComplete = computed(() => !!form.cv && (form.is_external ? String(form.specialist_name || '').trim() : form.specialist_id));
function attemptActions(attempt) {
  const actions = [];
  if (!canManageAttempts.value) return actions;
  const add = (key,label,danger=false,interview=null) => actions.push({key,label,danger,interview});
  if (attempt.status === 'Новая') add('send-cv','CV отправлено');
  if (['CV отправлено','Интервью назначено','Интервью пройдено'].includes(attempt.status)) add('interview', (attempt.interviews?.length ? 'Назначить '+(attempt.interviews.length+1)+' интервью' : 'Назначить интервью'));
  if (attempt.status === 'Интервью назначено') {
    (attempt.interviews || []).filter(interview => !interview.completed_at).forEach(interview => add('complete:'+interview.id, 'Завершить '+interview.sequence+' интервью', false, interview));
  }
  if (attempt.status === 'Интервью пройдено') add('connection-date','Назначить подключение');
  const clientId = Number(attempt.clientId || attempt.request?.client_id || attempt.position?.request?.client_id || selectedPosition.value?.request?.client_id);
  if (clientId && ['Ожидает подключения','Закрыт: успех'].includes(attempt.status) && !attempt.has_connection) add('connect','Создать подключение');
  if (attempt.status === 'Ожидает подключения') add('success','Закрыть с успехом');
  if (!attempt.status.startsWith('Закрыт')) add('failure','Закрыть с неудачей',true);
  if (attempt.status === 'Новая') add('delete','Удалить попытку',true);
  return actions;
}
function attemptAction(action, attempt, event) {
  actionAttemptId.value = Number(attempt.id); menuAttemptId.value = null;
  if (action.key === 'interview') return startInterview(attempt,event);
  if (action.key.startsWith('complete:')) return openAttemptDialog('complete-interview',attempt,{interview_id:action.interview.id,rating:'Положительно'});
  if (action.key === 'connection-date') return startConnection(attempt,event);
  if (action.key === 'failure') return openAttemptDialog('close-failure',attempt,{reasons:[]});
  if (action.key === 'connect') return emit('connect-attempt',attempt);
  return ({'send-cv':sendCv,success:closeSuccess,delete:deleteAttempt})[action.key]?.();
}
function dismissMenus(event) { if (!event.target.closest('.attempt-menu')) menuAttemptId.value = null; }
function dismissOnEscape(event) { if (event.key === 'Escape') menuAttemptId.value = null; }
onMounted(() => { document.addEventListener('click',dismissMenus); document.addEventListener('keydown',dismissOnEscape); });
onBeforeUnmount(() => { document.removeEventListener('click',dismissMenus); document.removeEventListener('keydown',dismissOnEscape); });
function latestPendingInterview(item) { return [...(item?.interviews || [])].reverse().find(interview => !interview.completed_at); }
</script>

<template>
  <section class="workflow-view">
    <template v-if="view==='requests'">
      <UiFilterBar class="workflow-filters"><input v-model="query" class="registry-search" placeholder="Поиск"><UiSearchSelect v-model="clientFilter" :options="targetOptions" placeholder="Клиенты / лиды"/><UiSearchSelect v-model="statusFilter" :options="requestStatuses" placeholder="Все статусы запросов"/><UiSearchSelect v-model="attemptStatusFilter" :options="attemptStatuses" placeholder="Статусы попыток" multiple/></UiFilterBar>
      <template v-if="mode==='tree'">
        <div class="workflow-head request-grid"><div>Запрос</div><div>Статус</div><span/></div>
        <template v-for="request in filteredRequests" :key="request.id">
          <div class="workflow-request request-grid">
            <div class="workflow-title"><button type="button" class="request-logo-link" :disabled="!request.client_id" :aria-label="request.client_id?'Открыть клиента '+targetName(request):'Лид '+targetName(request)" @click="openClient(request)"><RequestClientLogo :request="request" :client="clientById(request.client_id)||{name:targetName(request)}"/></button><UiTreeToggle :expanded="expandedRequests.has(Number(request.id))" :disabled="!(request.positions||[]).length" :hover="false" :label="(expandedRequests.has(Number(request.id))?'Свернуть':'Раскрыть')+' позиции запроса '+request.title" @click.stop="toggle(expandedRequests,request.id)" /><button class="entity-link" @click="openRequest(request)">{{request.title}}</button><button v-if="request.client_id" class="request-client request-client-link" type="button" @click="openClient(request)">({{targetName(request)}})</button><span v-else class="request-client">({{targetName(request)}} · лид)</span><button v-if="canManagePositions&&request.status!=='Закрыт'" class="text-action" @click="openCreatePosition(request)">+ позиция</button></div>
            <div class="workflow-status"><UiBadge :tone="tone(requestStatus(request))">{{requestStatus(request)}}</UiBadge></div><EntityActions :actions="requestActions(request)" :busy="busy" :label="'Действия запроса '+request.title" @action="action=>parentAction('requests',request,action)"/>
          </div>
          <div v-if="expandedRequests.has(Number(request.id))" class="request-tree-children">
           <div v-for="position in sortedPositions(request)" :key="position.id" class="position-tree-group">
            <div class="workflow-position request-grid">
              <div class="workflow-title workflow-title--position"><UiTreeToggle v-if="(position.attempts||[]).length" class="tree-branch-toggle" variant="plus" :hover="false" :expanded="expandedPositions.has(Number(position.id))" :label="(expandedPositions.has(Number(position.id))?'Скрыть':'Показать')+' попытки позиции'" @click.stop="toggle(expandedPositions,position.id)" /><span v-else class="tree-leaf-marker"/><button class="entity-link" @click="openPosition({...position,request})">{{position.technology || 'Технология не указана'}}, {{position.level}}</button><span class="position-quantity">x{{position.quantity}}</span><span v-if="rnId(position)" class="position-meta-tag">{{employeeName(rnId(position))}}</span><span v-if="expectedConnectionLabel(position.expected_connection_time)" class="position-meta-tag">{{expectedConnectionLabel(position.expected_connection_time)}}</span><span v-if="position.acceptable_tu_format&&position.acceptable_tu_format.toLowerCase()!=='не важно'" class="position-meta-tag">{{position.acceptable_tu_format}}</span></div>
              <div class="workflow-status"><UiBadge :tone="tone(positionStatus(position))">{{positionStatus(position)}}</UiBadge></div><EntityActions :actions="positionActions({...position,request})" :busy="busy" :label="'Действия позиции '+position.technology" @action="action=>parentAction('positions',{...position,request},action)"/>
            </div>
            <div v-if="expandedPositions.has(Number(position.id))" class="workflow-attempts">
              <div v-for="attempt in visibleAttempts(position)" :key="attempt.id" class="workflow-attempt request-grid"><span class="attempt-title"><AttemptProgress :attempt="attempt"/><button class="entity-link" @click="openAttempt(attempt)">{{attempt.specialist_name}}</button></span><span class="workflow-status"><a class="text-action cv-row-download" :href="`/api/clients/attempts/${attempt.id}/cv`" download @click.prevent="downloadCv(attempt)">Скачать CV</a><UiBadge :tone="tone(attempt.status)">{{attempt.status}}</UiBadge></span><EntityActions :actions="attemptActions({...attempt,request,clientId:Number(request.client_id)})" :busy="busy" :label="'Действия попытки '+attempt.specialist_name" @action="(action,event)=>attemptAction(action,{...attempt,position:{...position,request},request,clientId:Number(request.client_id)},event)"/></div>
            </div>
           </div>
          </div>
        </template>
      </template>
      <table v-else class="irlix-data-table request-list-table"><thead><tr><th>Запрос</th><th>Статус</th><th/></tr></thead><tbody><tr v-for="request in filteredRequests" :key="request.id"><td><span class="request-list-title"><button type="button" class="request-logo-link" :disabled="!request.client_id" @click="openClient(request)"><RequestClientLogo :request="request" :client="clientById(request.client_id)||{name:targetName(request)}"/></button><button class="entity-link" @click="openRequest(request)">{{request.title}}</button><button v-if="request.client_id" class="request-client request-client-link" type="button" @click="openClient(request)">({{targetName(request)}})</button><span v-else class="request-client">({{targetName(request)}} · лид)</span><button v-if="canManagePositions&&request.status!=='Закрыт'" class="text-action" @click="openCreatePosition(request)">+ позиция</button></span></td><td><span class="workflow-status"><UiBadge :tone="tone(requestStatus(request))">{{requestStatus(request)}}</UiBadge></span></td><td><EntityActions :actions="requestActions(request)" :busy="busy" :label="'Действия запроса '+request.title" @action="action=>parentAction('requests',request,action)"/></td></tr></tbody></table>
    </template>

    <template v-else-if="view==='positions'">
      <UiFilterBar class="workflow-filters"><input v-model="query" class="registry-search" placeholder="Поиск"><UiSearchSelect v-model="clientFilter" :options="targetOptions" placeholder="Клиенты / лиды"/><UiSearchSelect v-model="statusFilter" :options="positionStatuses" placeholder="Все статусы позиций"/></UiFilterBar>
      <table class="irlix-data-table"><thead><tr><th>Позиция</th><th>Запрос</th><th>Клиент / лид</th><th>Количество</th><th>Направление</th><th>Статус</th><th>Попытки</th></tr></thead><tbody><tr v-for="position in filteredPositions" :key="position.id"><td><button class="entity-link" @click="openPosition(position)">{{position.technology || 'Технология не указана'}} {{position.level}}</button></td><td>{{position.request.title}}</td><td>{{targetName(position.request)}}</td><td>{{position.quantity}}</td><td>{{position.direction||'—'}}</td><td><UiBadge :tone="tone(positionStatus(position))">{{positionStatus(position)}}</UiBadge></td><td>{{position.attempts?.length||0}}</td></tr></tbody></table>
    </template>

    <template v-else>
      <div class="attempt-board-header"><UiFilterBar class="workflow-filters"><input v-model="query" class="registry-search" placeholder="Поиск"><UiSearchSelect v-model="statusFilter" :options="attemptStatuses" placeholder="Статусы"/><UiSearchSelect v-model="clientFilter" :options="targetOptions" placeholder="Клиенты / лиды"/><UiSearchSelect v-model="technologyFilter" :options="attemptTechnologyOptions" placeholder="Технологии"/><UiSearchSelect v-model="specialistFilter" :options="specialistOptions" placeholder="Специалисты"/></UiFilterBar></div>
      <div v-if="error" class="error-banner kanban-error">{{error}}</div>
      <UiKanbanBoard class="attempt-kanban" :columns="attemptStatuses" :items="filteredAttempts" :active-target="dropStatus" label="Канбан попыток подключения" @dragover="overAttemptColumn" @drop="dropAttempt" @dragleave="dropStatus=''">
        <template #cards="{items}">
          <button v-for="attempt in items" :key="attempt.id" class="irlix-kanban-card attempt-card" :class="{'irlix-kanban-card--dragging':draggingAttemptId===Number(attempt.id)}" :draggable="canManageAttempts&&!busy&&!attempt.status.startsWith('Закрыт')" @dragstart="startAttemptDrag($event,attempt)" @dragend="endAttemptDrag" @click="openAttempt(attempt)">
            <strong :title="attempt.specialist_name">{{attempt.specialist_name}}</strong>
            <span :title="targetName(attempt.request)">{{targetName(attempt.request)}}</span>
            <small :title="(attempt.position.technology || 'Технология не указана')+' '+attempt.position.level">{{attempt.position.technology || 'Технология не указана'}} {{attempt.position.level}}</small>
            <small v-if="attempt.interviews?.length">Интервью: {{attempt.interviews.length}}</small>
          </button>
        </template>
      </UiKanbanBoard>
    </template>
  </section>

  <UiDrawer :inactive="backgroundBlocked||!!selectedPosition||!!selectedAttempt||!!dialog||interviewPicker||connectionPicker" :open="!!selectedRequest" :title="selectedRequest?.title || ''" width="50vw" :min-width="320" @close="selectedRequestId=null">
    <template #title><InlineField v-if="selectedRequest" :value="selectedRequest.title" :editable="canManageRequests" :busy="busy" label="название запроса" :maxlength="255" @save="(value,done)=>saveField('requests',selectedRequest.id,'title',value,done)"><strong>{{selectedRequest.title}}</strong></InlineField></template>
    <template #actions><EntityActions v-if="selectedRequest" :actions="requestActions(selectedRequest)" :busy="busy" label="Действия запроса" @action="action=>parentAction('requests',selectedRequest,action)"/></template>
    <div v-if="selectedRequest" class="reference-card request-reference-card">
      <div v-if="error&&!dialog" class="error-banner">{{error}}</div>
      <section class="reference-client-panel">
        <div class="reference-client-main">
          <strong class="reference-client-name">{{targetName(selectedRequest)}} <small v-if="selectedRequest.lead_id">· лид</small></strong>
          <div class="reference-managers">
            <span v-if="selectedRequestClient?.sales_employee_id" class="reference-manager"><i class="manager-letter manager-letter--sales">S</i>{{employeeName(selectedRequestClient.sales_employee_id)}}</span>
            <span v-if="selectedRequestClient?.account_employee_id" class="reference-manager"><i class="manager-letter manager-letter--account">A</i>{{employeeName(selectedRequestClient.account_employee_id)}}</span>
          </div>
        </div>
        <div class="reference-client-tags"><span v-if="selectedRequestClient?.sector" class="client-tag client-tag--sector">{{selectedRequestClient.sector}}</span><span v-if="selectedRequestClient?.type" class="client-tag client-tag--type">{{selectedRequestClient.type}}</span></div>
      </section>

      <section class="facts-grid request-facts">
        <div class="fact-item"><b>Статус</b><span><UiBadge :tone="tone(requestStatus(selectedRequest))">{{requestStatus(selectedRequest)}}</UiBadge></span></div>
        <div class="fact-item"><b>Ответственный за запрос</b><InlineField :value="selectedRequest.responsible_employee_id" :editable="canManageRequests&&requestEmployeeOptions.length>1" :busy="busy" label="ответственного за запрос" type="select" :options="requestEmployeeOptions" @save="(value,done)=>saveField('requests',selectedRequest.id,'responsible_employee_id',value,done)">{{employeeName(selectedRequest.responsible_employee_id)}}</InlineField></div>
        <div class="fact-item"><b>Дата запроса</b><InlineField :value="String(selectedRequest.request_date||selectedRequest.created_at||'').slice(0,10)" :editable="canManageRequests" :busy="busy" label="дату запроса" type="date" @save="(value,done)=>saveField('requests',selectedRequest.id,'request_date',value,done)">{{dateRu(selectedRequest.request_date||selectedRequest.created_at)}}</InlineField></div>
        <div class="fact-item"><b>Время жизни</b><InlineField :value="selectedRequest.lifetime_weeks||1" :editable="canManageRequests" :busy="busy" label="время жизни" type="select" :options="lifetimeOptions" @save="(value,done)=>saveField('requests',selectedRequest.id,'lifetime_weeks',value,done)">{{lifetimeText(selectedRequest)}}</InlineField></div>
      </section>

      <DescriptionBlock :key="'request-description:'+selectedRequest.id" label="Описание" :value="selectedRequest.description" :editable="canManageRequests" :busy="busy" :maxlength="20000" @save="(value,done)=>saveField('requests',selectedRequest.id,'description',value,done)"/>

      <div class="request-positions-heading"><h3>Позиции</h3><UiButton v-if="canManagePositions&&selectedRequest.status!=='Закрыт'" @click="openCreatePosition(selectedRequest)">＋ Новая позиция</UiButton></div>

      <section class="request-position-cards">
        <article v-for="position in sortedPositions(selectedRequest)" :key="position.id" class="request-position-card">
          <div class="request-position-card__main">
            <button class="position-card-title" type="button" @click="openPosition({...position,request:selectedRequest})"><strong>{{position.technology || 'Технология не указана'}}</strong><sup>{{position.level}}</sup></button>
            <span class="position-count">{{position.quantity}} {{Number(position.quantity)===1?'ставка':'ставки'}}</span>
            <details v-if="position.description" class="position-description-preview"><summary>Показать описание</summary><p>{{position.description}}</p></details>
          </div>
          <UiBadge :tone="tone(positionStatus(position))">{{positionStatus(position)}}</UiBadge>
        </article>
      </section>
    </div>
  </UiDrawer>

  <UiDrawer :inactive="backgroundBlocked||!!selectedAttempt||!!dialog||interviewPicker||connectionPicker" :open="!!selectedPosition" :title="positionDrawerTitle" width="40vw" :min-width="280" :z-index="1050" @close="selectedPositionId=null">
    <template #actions><EntityActions v-if="selectedPosition" :actions="positionActions(selectedPosition)" :busy="busy" label="Действия позиции" @action="action=>parentAction('positions',selectedPosition,action)"/></template>
    <div v-if="selectedPosition" class="reference-card position-reference-card">
      <div v-if="error&&!dialog" class="error-banner">{{error}}</div>
      <section class="reference-client-panel">
        <div class="reference-client-main">
          <strong class="reference-client-name">{{targetName(selectedPosition.request)}} <small v-if="selectedPosition.request.lead_id">· лид</small></strong>
          <div class="reference-managers">
            <span v-if="selectedPositionClient?.sales_employee_id" class="reference-manager"><i class="manager-letter manager-letter--sales">S</i>{{employeeName(selectedPositionClient.sales_employee_id)}}</span>
            <span v-if="selectedPositionClient?.account_employee_id" class="reference-manager"><i class="manager-letter manager-letter--account">A</i>{{employeeName(selectedPositionClient.account_employee_id)}}</span>
          </div>
        </div>
        <div class="reference-client-tags"><span v-if="selectedPositionClient?.sector" class="client-tag client-tag--sector">{{selectedPositionClient.sector}}</span><span v-if="selectedPositionClient?.type" class="client-tag client-tag--type">{{selectedPositionClient.type}}</span></div>
      </section>

      <section class="facts-grid position-facts-grid">
        <div class="fact-item"><b>Статус</b><span><UiBadge :tone="tone(positionStatus(selectedPosition))">{{positionStatus(selectedPosition)}}</UiBadge></span></div>
        <div class="fact-item"><b>Ответственный за запрос</b><span>{{employeeName(selectedPosition.request.responsible_employee_id)}}</span></div>
        <div class="fact-item"><b>Направление</b><InlineField :value="directionId(selectedPosition)" :editable="canManagePositions" :busy="busy" label="направление" type="select" :options="directionOptions" @save="(value,done)=>saveField('positions',selectedPosition.id,'direction_department_id',value,done)">{{selectedPosition.direction || '—'}}</InlineField></div>
        <div class="fact-item"><b>Технология</b><InlineField :value="selectedPosition.technology" :editable="canManagePositions" :busy="busy" label="технология" type="select" :options="technologyOptions" @save="(value,done)=>saveField('positions',selectedPosition.id,'technology',value,done)">{{selectedPosition.technology}}</InlineField></div>
        <div class="fact-item"><b>Уровень</b><InlineField :value="selectedPosition.level" :editable="canManagePositions" :busy="busy" label="уровень" type="select" :options="levelOptions" @save="(value,done)=>saveField('positions',selectedPosition.id,'level',value,done)">{{selectedPosition.level}}</InlineField></div>
        <div class="fact-item"><b>Количество</b><InlineField :value="selectedPosition.quantity" :editable="canManagePositions" :busy="busy" label="количество" type="number" :min="1" :max="100" @save="(value,done)=>saveField('positions',selectedPosition.id,'quantity',value,done)">{{selectedPosition.quantity}}</InlineField></div>
        <div class="fact-item"><b>Ответственный РН</b><InlineField :value="rnId(selectedPosition)" :editable="canManagePositions" :busy="busy" label="ответственный рн" type="select" :options="productionManagerOptions" :required="false" @save="(value,done)=>saveField('positions',selectedPosition.id,'responsible_rn_employee_id',value,done)">{{employeeName(rnId(selectedPosition))}}</InlineField></div>
        <div class="fact-item"><b>Дата создания</b><span>{{dateRu(selectedPosition.created_at)}}</span></div>
        <div class="fact-item"><b>Время жизни</b><span>{{lifetimeText(selectedPosition.request)}}</span></div>
        <div class="fact-item"><b>Ожидаемое время подключения</b><InlineField :value="selectedPosition.expected_connection_time||'Неизвестно'" :editable="canManagePositions" :busy="busy" label="ожидаемое время подключения" type="select" :options="expectedConnectionTimes" @save="(value,done)=>saveField('positions',selectedPosition.id,'expected_connection_time',value,done)">{{selectedPosition.expected_connection_time||'Неизвестно'}}</InlineField></div>
        <div class="fact-item"><b>Допустимый формат ТУ</b><InlineField :value="selectedPosition.acceptable_tu_format||'Не важно'" :editable="canManagePositions" :busy="busy" label="допустимый формат ту" type="select" :options="acceptableTuFormats" @save="(value,done)=>saveField('positions',selectedPosition.id,'acceptable_tu_format',value,done)">{{selectedPosition.acceptable_tu_format||'Не важно'}}</InlineField></div>
      </section>

      <DescriptionBlock :key="'parent-description:'+selectedPosition.id" label="Описание запроса" :value="selectedPosition.request.description"/>

      <DescriptionBlock :key="'position-description:'+selectedPosition.id" label="Описание позиции" :value="selectedPosition.description" :editable="canManagePositions" :busy="busy" @save="(value,done)=>saveField('positions',selectedPosition.id,'description',value,done)"/>

      <section class="attempts-reference-section">
        <div class="section-line"><h3>Попытки подключения</h3><UiButton v-if="canManageAttempts&&selectedPosition.status!=='Закрыт'&&selectedPosition.request.status!=='Закрыт'" compact @click="openDialog('create-attempt')">Новая попытка</UiButton></div>
        <div v-for="attempt in selectedPosition.attempts||[]" :key="attempt.id" class="position-attempt-row">
          <button class="entity-link" @click="openAttempt({...attempt,position:selectedPosition,request:selectedPosition.request,clientId:Number(selectedPosition.request.client_id)})">{{attempt.specialist_name}}</button>
          <span class="attempt-created">{{dateRu(attempt.created_at)}}</span><AttemptProgress :attempt="attempt"/><UiBadge :tone="tone(attempt.status)">{{attempt.status}}</UiBadge>
          <div v-if="attemptActions(attempt).length" class="attempt-menu">
            <button class="attempt-menu-toggle" type="button" :aria-expanded="menuAttemptId===attempt.id" :aria-label="'Действия попытки '+attempt.specialist_name" @click.stop="menuAttemptId=menuAttemptId===attempt.id?null:attempt.id">⋮</button>
            <div v-if="menuAttemptId===attempt.id" class="attempt-menu-panel" role="menu"><button v-for="action in attemptActions(attempt)" :key="action.key" type="button" role="menuitem" :class="{danger:action.danger}" :disabled="busy" @click="attemptAction(action,{...attempt,position:selectedPosition,request:selectedPosition.request,clientId:Number(selectedPosition.request.client_id)},$event)">{{action.label}}</button></div>
          </div><span v-else class="attempt-menu-placeholder"/>
        </div>
        <p v-if="!(selectedPosition.attempts||[]).length" class="empty-attempts">Попыток подключения пока нет</p>
      </section>
    </div>
  </UiDrawer>

  <UiDrawer :inactive="backgroundBlocked||!!dialog||interviewPicker||connectionPicker" :open="!!selectedAttempt" :title="selectedAttempt?`Попытка подключения · ${selectedAttempt.specialist_name}`:''" width="30vw" :min-width="240" :z-index="1080" @close="selectedAttemptId=null"><template #actions><AttemptProgress v-if="selectedAttempt" :attempt="selectedAttempt"/></template><div v-if="selectedAttempt" class="detail-card"><div v-if="error&&!dialog" class="error-banner">{{error}}</div><div class="facts-grid detail-summary"><div class="fact-item"><b>Специалист</b><span>{{selectedAttempt.specialist_name}}</span></div><div class="fact-item"><b>Статус</b><span><UiBadge :tone="tone(selectedAttempt.status)">{{selectedAttempt.status}}</UiBadge></span></div><div class="fact-item"><b>Позиция</b><span>{{selectedAttempt.position.technology}}, {{selectedAttempt.position.level}}</span></div><div class="fact-item"><b>Ответственный</b><span>{{employeeName(selectedAttempt.responsible_employee_id)}}</span></div><div class="fact-item"><b>CV</b><button class="text-action cv-download" type="button" @click="downloadCv(selectedAttempt)">{{selectedAttempt.cv_original_name||'Скачать'}}</button></div><div class="fact-item"><b>Дата создания</b><span>{{dateRu(selectedAttempt.created_at)}}</span></div></div><section v-if="selectedAttempt.description"><h3>Описание</h3><p>{{selectedAttempt.description}}</p></section><section v-if="selectedAttempt.cv_sent_at||selectedAttempt.interviews?.length||selectedAttempt.connection_date||selectedAttempt.status.startsWith('Закрыт:')" class="attempt-history"><h3>Этапы</h3><article v-if="selectedAttempt.cv_sent_at" class="attempt-history-stage"><i class="attempt-stage-marker"/><div><header class="attempt-stage-head"><h4>CV отправлено</h4><time>{{dateTimeRu(selectedAttempt.cv_sent_at)}}</time></header></div></article><article v-for="interview in selectedAttempt.interviews" :key="interview.id" class="attempt-history-stage interview-row"><i class="attempt-stage-marker"/><div><header class="attempt-stage-head"><h4>{{interview.sequence}} интервью</h4><time>{{dateTimeRu(interview.scheduled_at)}}</time></header><p v-if="interview.completed_at" class="interview-result">{{interview.rating}} — {{interview.feedback}}</p><UiButton v-else-if="isAccount&&canManageAttempts&&selectedAttempt.status==='Интервью назначено'" compact variant="secondary" @click="openAttemptDialog('complete-interview',selectedAttempt,{interview_id:interview.id,rating:'Положительно'})">Интервью завершено</UiButton></div></article><article v-if="selectedAttempt.connection_date||selectedAttempt.status.startsWith('Закрыт:')" class="attempt-history-stage"><i class="attempt-stage-marker"/><div><header class="attempt-stage-head"><h4>{{selectedAttempt.status.startsWith('Закрыт:')?'Итог попытки':'Подключение'}}</h4><time v-if="selectedAttempt.has_connection&&selectedAttempt.connection_started_at">{{dateRu(selectedAttempt.connection_started_at)}}</time><time v-else-if="selectedAttempt.closed_at">{{dateTimeRu(selectedAttempt.closed_at)}}</time><time v-else-if="selectedAttempt.connection_date">{{dateRu(selectedAttempt.connection_date)}}</time></header><p v-if="selectedAttempt.status.startsWith('Закрыт:')">{{selectedAttempt.status}}</p><p v-if="selectedAttempt.has_connection">Подключение состоялось</p><p v-else-if="selectedAttempt.connection_date&&!selectedAttempt.status.startsWith('Закрыт:')">Подключение назначено</p><ul v-if="selectedAttempt.failure_reasons?.length"><li v-for="reason in selectedAttempt.failure_reasons" :key="reason">{{reason}}</li></ul></div></article></section>
<div class="attempt-actions"><UiButton v-for="action in attemptActions(selectedAttempt).filter(action=>!action.key.startsWith('complete:'))" :key="action.key" :variant="action.danger?'danger':'primary'" :disabled="busy" @click="attemptAction(action,selectedAttempt,$event)">{{action.label}}</UiButton></div></div></UiDrawer>

  <UiDrawer :inactive="backgroundBlocked" :open="!!dialog" :title="({ 'create-position':'Новая позиция','create-attempt':'Новая попытка подключения','complete-interview':'Итоги интервью','close-failure':'Закрыть с неудачей' })[dialog]||''" width="520px" :z-index="1100" @close="closeDialog"><form class="entity-form" @submit.prevent="({ 'create-position':createPosition,'create-attempt':createAttempt,'complete-interview':completeInterview,'close-failure':closeFailure })[dialog]?.()"><div v-if="error" class="error-banner">{{error}}</div>
    <template v-if="dialog==='create-position'"><label>Технология<UiSearchSelect v-model="form.technology" :options="technologyOptions" placeholder="Не выбрано" search-placeholder="Поиск технологии" :clearable="false"/></label><label>Направление<UiSearchSelect v-model="form.direction_department_id" :options="directionOptions" @change="assignManager" placeholder="Не выбрано" search-placeholder="Поиск направления" :clearable="false"/></label><label>Ответственный РН<UiSearchSelect v-model="form.responsible_rn_employee_id" :options="productionManagerOptions" placeholder="Руководитель выбранного направления"/></label><label>Уровень<UiSearchSelect v-model="form.level" :options="levelOptions.map(value=>({value,label:value}))" placeholder="Не выбрано" search-placeholder="Поиск уровня" :clearable="false"/></label><label>Количество<input v-model="form.quantity" type="number" min="1" required></label><label>Ожидаемое время подключения<UiSearchSelect v-model="form.expected_connection_time" :options="expectedConnectionTimes.map(value=>({value,label:value}))" :clearable="false"/></label><label>Допустимый формат ТУ<UiSearchSelect v-model="form.acceptable_tu_format" :options="acceptableTuFormats.map(value=>({value,label:value}))" :clearable="false"/></label><label>Описание<textarea v-model="form.description"/></label></template>
    <template v-if="dialog==='create-attempt'">
      <label v-if="!form.is_external">Сотрудник<UiSearchSelect v-model="form.specialist_id" :options="attemptSpecialistOptions" placeholder="Выберите сотрудника" search-placeholder="Поиск сотрудника или направления" :clearable="false"/></label>
      <label v-else>ФИО партнерского специалиста<input v-model="form.specialist_name" required maxlength="255" placeholder="Введите ФИО"></label>
      <UiSegmentedControl :model-value="form.is_external?'partner':'employee'" :items="[{value:'employee',label:'Сотрудник'},{value:'partner',label:'Партнерский специалист'}]" aria-label="Тип специалиста" @update:model-value="value=>{form.is_external=value==='partner';toggleExternal()}"/>
      <div class="cv-field"><span>Файл CV<span class="required-marker">*</span></span><input ref="cvInput" class="cv-file-input" type="file" accept=".pdf,.doc,.docx" tabindex="-1" @change="selectCv($event.target.files[0])">
        <div class="cv-dropzone" :class="{dragging:cvDragging}" role="button" tabindex="0" aria-label="Загрузить файл CV" @click="cvInput?.click()" @keydown.enter.prevent="cvInput?.click()" @keydown.space.prevent="cvInput?.click()" @dragover.prevent="cvDragging=true" @dragleave.prevent="cvDragging=false" @drop.prevent="dropCv">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true"><path d="M24 29V5m-9 9 9-9 9 9M14 25H7v16h34V25h-7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="34" cy="34" r="2" fill="currentColor"/></svg>
          <span v-if="form.cv" class="cv-filename">{{form.cv.name}}</span><span v-else><u>Нажмите, чтобы загрузить</u> или перенесите сюда файл</span>
        </div><small>PDF, DOC, DOCX · до 15 МБ</small>
      </div>
      <label>Комментарий<textarea v-model="form.description" rows="4" maxlength="5000" placeholder="Необязательно"/></label>
    </template>
    <template v-if="dialog==='complete-interview'"><label>Оценка<UiSearchSelect v-model="form.rating" :options="ratings" :clearable="false" aria-label="Оценка интервью"/></label><label>Фидбек<textarea v-model="form.feedback" class="interview-feedback" rows="7" maxlength="1000" required/></label></template>
    <template v-if="dialog==='close-failure'"><fieldset class="reason-list"><legend>Причины</legend><label v-for="reason in availableFailureReasons" :key="reason"><input v-model="form.reasons" type="checkbox" :value="reason">{{reason}}</label></fieldset></template>
    <div class="form-actions"><UiButton type="submit" :disabled="busy||(dialog==='create-attempt'&&!attemptFormComplete)">{{busy?'Сохраняю…':'Сохранить'}}</UiButton><UiButton type="button" variant="secondary" @click="closeDialog">Отмена</UiButton></div>
  </form></UiDrawer>
  <InterviewScheduler v-if="interviewPicker" :open="interviewPicker" :anchor="interviewAnchor" :busy="busy" :error="error" @close="closeDialog" @apply="scheduleInterview"/>
  <InterviewScheduler v-if="connectionPicker" :open="connectionPicker" :anchor="connectionAnchor" :busy="busy" :error="error" title="Назначить подключение" :include-time="false" @close="closeDialog" @apply="scheduleConnection"/>
</template>

<style scoped>
.workflow-filters{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-start;gap:10px;padding:12px 16px}.workflow-filters :deep(.registry-search){flex:0 1 240px;width:240px;max-width:280px}.workflow-filters :deep(.ui-search-select){flex:0 1 210px;width:210px;max-width:250px}.workflow-head{background:#f2f3f4;color:#59616b;font-size:12px;font-weight:600}.request-grid{display:grid;grid-template-columns:minmax(0,1fr) 280px 36px;align-items:center;border-bottom:1px solid #edf0f2}.request-grid>div{min-width:0;padding:6px 10px}.request-grid>.entity-actions{padding:0;justify-self:center}.workflow-request{background:transparent;font-size:13px}.workflow-position{position:relative;background:transparent;font-size:12px}.workflow-title{position:relative;display:flex;align-items:center;gap:6px;min-height:22px;flex-wrap:wrap}.workflow-title--position{padding-left:64px!important}.workflow-title--position>.entity-link{margin-left:10px}.tree-branch-toggle{position:absolute;left:32px;top:50%;transform:translateY(-50%)}.tree-leaf-marker{position:absolute;z-index:1;left:40.5px;top:50%;width:5px;height:5px;border-radius:50%;background:#aeb5bc;transform:translateY(-50%)}.entity-link,.text-action{border:0;background:transparent;padding:0;cursor:pointer;font:inherit}.entity-link{font-weight:600;color:#20262d;text-align:left}.entity-link:hover,.text-action:hover{color:#078d6c}.text-action{color:#078d6c;font-size:11px;white-space:nowrap}.request-client,.position-quantity{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#8a929b;font-size:11px}.position-quantity{overflow:visible}.workflow-status{display:flex;justify-content:flex-end}.workflow-status :deep(.irlix-badge),.workflow-status :deep(.ui-badge){width:160px;flex:none;justify-content:center;white-space:nowrap}.workflow-attempts{position:relative}.workflow-attempt{width:100%;padding:0;border:0;border-bottom:1px solid #edf0f2;background:transparent;text-align:left;cursor:pointer}.workflow-attempt:hover{background:#fafcfc}.workflow-attempt>span{min-width:0;padding:6px 10px}.attempt-title{display:flex;align-items:center;gap:12px;position:relative;padding-left:69px!important;color:#20262d;font-size:12px}.attempt-title :deep(.attempt-progress){flex:none}.attempt-title>span{min-width:0;overflow-wrap:anywhere}.request-list-table th:nth-last-child(2),.request-list-table td:nth-last-child(2){width:180px}.request-list-table th:last-child,.request-list-table td:last-child{width:36px}.request-list-title{display:flex;align-items:center;gap:6px}.attempt-board-header{flex:none;background:var(--irlix-color-surface,#fff)}.kanban-error{margin:0 8px 8px}
.reference-card{display:grid;gap:18px;padding:4px 2px 28px;color:#171b20;font-size:14px}.reference-client-panel{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:14px 16px;border:1px solid #dcdfe3;border-radius:5px;background:#fff}.reference-client-main{display:grid;gap:8px;min-width:0}.reference-client-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:20px;line-height:1.1}.reference-managers{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.reference-manager{display:flex;align-items:center;gap:5px;color:#505862;white-space:nowrap}.manager-letter{display:grid;place-items:center;width:18px;height:18px;border:1px solid currentColor;border-radius:5px;font-size:11px;font-style:normal;line-height:1}.manager-letter--sales{color:#f38a29;background:#fff4e9}.manager-letter--account{color:#2cad69;background:#eaf9ef}.reference-client-tags{display:flex;align-items:center;justify-content:flex-end;gap:9px;flex-wrap:wrap}.client-tag{padding:6px 11px;border-radius:4px;font-size:12px;white-space:nowrap}.client-tag--sector{background:#c9f3e8;color:#087f67}.client-tag--type{background:#e4ddff;color:#7661d4}.request-facts{display:grid;gap:16px;padding:0 10px}.fact-row{display:grid;grid-template-columns:155px minmax(0,1fr);align-items:center;gap:14px}.fact-row>b,.position-fact>b{font-weight:500}.fact-row em{font-style:normal}.reference-fieldset{min-width:0;margin:0 0 0;padding:12px 18px 16px;border:1px solid #d9dde1;border-radius:5px}.reference-fieldset legend{padding:0 12px;color:#242a30;font-weight:600;font-size:15px}.reference-fieldset p{margin:0;white-space:pre-wrap;line-height:1.48}.request-positions-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}.request-positions-heading h3{margin:0;font-size:15px;font-weight:600}.request-position-cards{display:grid;gap:10px}.request-position-card{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:14px;border:1px solid #dbdee2;border-radius:7px;background:#fff}.request-position-card__main{display:grid;gap:5px;min-width:0}.position-card-title{display:flex;align-items:baseline;gap:2px;width:max-content;max-width:100%;padding:0;border:0;background:transparent;color:#1c252c;cursor:pointer}.position-card-title strong{font-size:16px}.position-card-title sup{color:#00a3a0;font-size:12px;font-weight:700}.position-count{font-size:12px;color:#656e78}.position-description-preview{font-size:12px}.position-description-preview summary{color:#4269bd;cursor:pointer;list-style:none}.position-description-preview summary::-webkit-details-marker{display:none}.position-description-preview p{margin:6px 0 0;color:#4b545e;white-space:pre-wrap}.new-position-bar{min-height:36px;border:0;border-radius:7px;background:#f1f1f2;color:#525b65;font-weight:600;cursor:pointer}.new-position-bar:hover{background:#e9ebed}.position-facts-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px 32px;padding:2px 10px}.position-fact{display:grid;grid-template-columns:155px minmax(0,1fr);align-items:center;gap:16px;min-width:0}.position-fact span{min-width:0;overflow:hidden;text-overflow:ellipsis}.attempts-reference-section{padding-top:4px}.attempts-reference-section h3{margin:0;font-size:15px}.empty-attempts{margin:10px 0;color:#8a929b;font-size:12px}.detail-card{display:grid;gap:18px;font-size:14px;color:#303943}.detail-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.detail-summary span{display:grid;gap:6px;font-size:14px}.detail-summary b{color:#737c86;font-size:13px;font-weight:500}.detail-card section{padding-top:16px;border-top:1px solid #eceef0}.detail-card h3{margin:0 0 10px;font-size:14px}.detail-card p{white-space:pre-wrap;line-height:1.55}.section-line{display:flex;align-items:center;justify-content:space-between}.detail-row{width:100%;display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:12px;padding:10px 0;border:0;border-bottom:1px solid #edf0f2;background:transparent;text-align:left;cursor:pointer}.interview-row{display:grid;gap:7px;padding:10px 0;border-bottom:1px solid #edf0f2;font-size:14px}.attempt-actions{display:flex;flex-wrap:wrap;gap:8px;padding-top:12px}.reason-list{display:grid;gap:9px;border:0;padding:0}.reason-list label{display:flex!important;grid-template-columns:auto 1fr!important;align-items:start;gap:8px!important;font-weight:400!important}.reason-list input{width:auto!important;min-height:auto!important;margin-top:2px}
@media(max-width:720px){.request-grid{grid-template-columns:minmax(0,1fr) 152px 30px}.workflow-status :deep(.irlix-badge),.workflow-status :deep(.ui-badge){width:132px}.detail-summary{grid-template-columns:1fr}.reference-client-panel{align-items:flex-start;flex-direction:column}.reference-client-tags{justify-content:flex-start}.position-facts-grid{grid-template-columns:1fr}.fact-row,.position-fact{grid-template-columns:135px minmax(0,1fr)}}


.position-attempt-row{display:grid;grid-template-columns:minmax(110px,1fr) auto auto auto 28px;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #edf0f2;font-size:14px}
.attempt-created{font-size:12px;color:#89929c}
.attempt-menu{position:relative}
.attempt-menu-toggle{display:grid;place-items:center;width:28px;height:32px;border:0;border-radius:7px;background:transparent;color:#64707c;font-size:24px;cursor:pointer}
.attempt-menu-toggle:hover{background:#edf4f1}
.attempt-menu-placeholder{width:28px}
.attempt-menu-panel{position:absolute;right:0;bottom:calc(100% + 4px);z-index:3;min-width:220px;padding:5px;border:1px solid #e0e5e9;border-radius:10px;background:#fff;box-shadow:0 8px 28px rgba(20,30,40,.15)}
.attempt-menu-panel button{width:100%;padding:9px 10px;border:0;border-radius:6px;background:transparent;color:#303943;font:inherit;font-size:13px;text-align:left;cursor:pointer}
.attempt-menu-panel button:hover{background:#eff7f4}
.attempt-menu-panel button.danger{color:#cc4754}
.attempt-progress-header{display:flex;align-items:center;gap:20px;padding:0 0 8px}
.interview-feedback{min-height:160px!important;resize:vertical}
.external-checkbox{display:flex!important;align-items:center;gap:8px!important;color:#4f5967}
.external-checkbox input{width:16px!important;height:16px!important;min-height:0!important;accent-color:var(--irlix-color-primary,#12b890)}
.cv-field{display:grid;gap:6px;color:#69737e;font-size:12px;font-weight:600}
.cv-file-input{display:none}
.cv-dropzone{display:flex;min-height:150px;flex-direction:column;justify-content:center;align-items:center;gap:14px;padding:20px;border:2px dashed #ccd5df;border-radius:4px;background:#fff;color:#636a72;font-size:14px;font-weight:600;text-align:center;cursor:pointer}
.cv-dropzone u{color:#333;text-underline-offset:2px}
.cv-dropzone.dragging,.cv-dropzone:hover,.cv-dropzone:focus-visible{border-color:var(--irlix-color-primary,#12b890);background:#f4fcf9;outline:0}
.cv-filename{word-break:break-word;color:#078d6c}
.cv-field small{font-size:11px;font-weight:400;color:#939aa3}
.required-marker{color:#dc5362;margin-left:2px}
@media(max-width:720px){.request-grid{grid-template-columns:minmax(0,1fr) 152px 30px}.position-attempt-row{grid-template-columns:minmax(90px,1fr) auto 28px;gap:8px}.position-attempt-row .attempt-created{display:none}.position-attempt-row>:deep(.attempt-progress){grid-column:1}.position-attempt-row>:deep(.irlix-badge){grid-column:2}.position-attempt-row .attempt-menu{grid-column:3;grid-row:1/3}}

.position-meta-tag{display:inline-block;padding:3px 7px;border-radius:5px;background:#eef0f2;color:#77818c;font-size:11px;font-weight:400;white-space:nowrap}.cv-row-download{margin-right:10px;font-size:12px}.workflow-attempt{cursor:default}.workflow-attempt .workflow-status{align-items:center}.workflow-attempt .entity-link{font-size:12px;font-weight:400}.position-facts-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,310px),1fr));gap:14px 20px}.position-fact{grid-template-columns:140px minmax(0,1fr)}.position-fact :deep(.inline-field){overflow:visible}.position-fact span{white-space:normal}.reference-client-name{font-size:18px}.fact-row{grid-template-columns:155px minmax(0,1fr)}
@media(max-width:720px){.workflow-attempt .workflow-status{flex-wrap:wrap;gap:5px}.cv-row-download{margin-right:0}.position-fact{grid-template-columns:130px minmax(0,1fr)}}
</style>

<style scoped>
.request-logo-link{display:grid;place-items:center;flex:none;padding:0;border:0;background:transparent;cursor:pointer}.request-logo-link:disabled{cursor:default}.request-client-link{border:0;padding:0;background:transparent;font:inherit;font-size:11px;text-align:left;cursor:pointer}.request-client-link:hover{color:#078d6c}.workflow-title--position{padding-left:72px!important}.tree-branch-toggle{left:61px}.tree-leaf-marker{left:69.5px}.attempt-title{padding-left:66px!important}.request-tree-children,.position-tree-group,.workflow-position,.workflow-attempts{position:relative}.request-grid:has(>.entity-actions.open){position:relative;z-index:100}
.facts-grid{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:16px 28px!important;padding:0 10px!important}.fact-item{display:grid;align-content:start;gap:2px;min-width:0}.fact-item>b{color:#7b848e;font-size:11px;font-weight:500;line-height:1.25}.fact-item>span,.fact-item>.text-action{display:flex;align-items:center;min-width:0;min-height:34px;font-size:14px}.fact-item>.text-action{width:max-content}.position-facts-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.detail-summary span{display:flex;gap:0}.detail-summary b{font-size:11px}.attempt-history{display:grid;gap:0;border-top:0!important}.attempt-history>h3{margin-bottom:14px}.attempt-history-stage{position:relative;display:grid;grid-template-columns:10px minmax(0,1fr);gap:10px;padding:0 0 12px;border:0!important;font-size:14px}.attempt-history-stage:last-child{padding-bottom:0}.attempt-history-stage:not(:last-child):before{content:"";position:absolute;left:3px;top:13px;bottom:0;border-left:1px dashed #aeb6bf}.attempt-stage-marker{position:relative;z-index:1;width:7px;height:7px;margin-top:6px;border-radius:50%;background:#aeb6bf}.attempt-stage-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px}.attempt-history-stage h4,.attempt-history-stage p{margin:0;font-size:14px}.attempt-history-stage time{color:#858e98;font-size:12px;white-space:nowrap}.attempt-history-stage ul{margin:6px 0 0;padding-left:18px}.interview-result{margin-top:7px!important;font-size:14px!important;line-height:1.5}.interview-row{border:0!important}
@media(max-width:720px){.facts-grid,.position-facts-grid{grid-template-columns:1fr!important}.attempt-stage-head{align-items:flex-start;flex-direction:column;gap:2px}}
</style>
