<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiFilterBar, UiSearchSelect } from '@irlix/ui';

import AttemptProgress from './AttemptProgress.vue';
import InterviewScheduler from './InterviewScheduler.vue';
import { attemptEmployeeOptions, positionMatchesStatus, sortNewest } from '../workflow.js';

const props = defineProps({
  view: { type: String, required: true },
  mode: { type: String, default: 'tree' },
  requests: { type: Array, default: () => [] },
  clients: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
  access: { type: Object, default: () => ({}) },
  technologyOptions: { type: Array, default: () => [] },
  directionOptions: { type: Array, default: () => [] },
  levelOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(['changed', 'connect-attempt']);

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
const attemptStatusFilter = ref('');
const actionAttemptId = ref(null);
const menuAttemptId = ref(null);
const interviewPicker = ref(false);
const interviewAnchor = ref(null);
const cvInput = ref(null);
const cvDragging = ref(false);
const clientFilter = ref('');
const specialistFilter = ref('');
const technologyFilter = ref('');
const form = reactive({});
let treeInitialized = false;

const attemptStatuses = ['Новая','CV отправлено','Интервью назначено','Интервью пройдено','Ожидает подключения','Закрыт: успех','Закрыт: неудача'];
const requestStatuses = ['Открыт','Закрыт'];
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
const employeeOptions = computed(() => props.employees.map(item => ({ value:String(item.id), label:item.full_name || `#${item.id}` })));
const clientOptions = computed(() => props.clients.map(item => ({ value:String(item.id), label:item.name })));
const employeeName = id => props.employees.find(item => Number(item.id) === Number(id))?.full_name || (id ? `#${id}` : '—');
const clientById = id => props.clients.find(item => Number(item.id) === Number(id)) || null;
const clientName = id => clientById(id)?.name || `#${id}`;
const dateRu = value => value ? new Date(`${String(value).slice(0,10)}T00:00:00`).toLocaleDateString('ru-RU') : '—';
const dateTimeRu = value => value ? new Date(value).toLocaleString('ru-RU', { dateStyle:'short', timeStyle:'short' }) : '—';
const roles = computed(() => props.access.roles || []);
const isAdmin = computed(() => !!props.access.platform_admin);
const isDirectionManager = computed(() => isAdmin.value || roles.value.includes('department-manager'));
const isAccount = computed(() => isAdmin.value || roles.value.some(role => ['account-manager','accounting-head','client-service-head'].includes(role)));
const canManageRequests = computed(() => isAccount.value && !!props.access.permissions?.['requests.manage']?.allowed);
const canManagePositions = computed(() => isAccount.value && !!props.access.permissions?.['positions.manage']?.allowed);
const canManageAttempts = computed(() => !!props.access.permissions?.['attempts.manage']?.allowed);
const attemptSpecialistOptions = computed(() => attemptEmployeeOptions(props.employees, props.departments, props.access.attempt_employee_ids || []));


const positions = computed(() => sortNewest(props.requests.flatMap(request => (request.positions || []).map(position => ({ ...position, request })))));
const attempts = computed(() => positions.value.flatMap(position => (position.attempts || []).map(attempt => ({ ...attempt, position, request:position.request, clientId:Number(position.request.client_id) }))));
const selectedRequest = computed(() => props.requests.find(item => Number(item.id) === Number(selectedRequestId.value)) || null);
const selectedPosition = computed(() => positions.value.find(item => Number(item.id) === Number(selectedPositionId.value)) || null);
const selectedAttempt = computed(() => attempts.value.find(item => Number(item.id) === Number(selectedAttemptId.value)) || null);
const actionAttempt = computed(() => attempts.value.find(item => Number(item.id) === Number(actionAttemptId.value)) || selectedAttempt.value);
const selectedRequestClient = computed(() => selectedRequest.value ? clientById(selectedRequest.value.client_id) : null);
const selectedPositionClient = computed(() => selectedPosition.value ? clientById(selectedPosition.value.request.client_id) : null);
const positionDrawerTitle = computed(() => selectedPosition.value ? `${selectedPosition.value.request.title} - ${selectedPosition.value.technology} - ${selectedPosition.value.level}` : '');
const filteredPositions = computed(() => positions.value.filter(item => {
  const needle = query.value.trim().toLowerCase();
  return (!needle || `${item.technology} ${item.level} ${item.direction || ''}`.toLowerCase().includes(needle))
    && positionMatchesStatus(item, statusFilter.value)
    && (!clientFilter.value || String(item.request.client_id) === clientFilter.value);
}));
const filteredAttempts = computed(() => attempts.value.filter(item => {
  const needle = query.value.trim().toLowerCase();
  return (!needle || `${item.specialist_name} ${item.position.technology} ${item.position.level}`.toLowerCase().includes(needle))
    && (!statusFilter.value || item.status === statusFilter.value)
    && (!clientFilter.value || String(item.clientId) === clientFilter.value)
    && (!technologyFilter.value || item.position.technology === technologyFilter.value)
    && (!specialistFilter.value || (item.specialist_id ? 'employee:'+item.specialist_id : 'external:'+item.id) === specialistFilter.value);
}));
const specialistOptions = computed(() => [...new Map(attempts.value.map(item => [String(item.id), { value:item.specialist_id ? 'employee:'+item.specialist_id : 'external:'+item.id, label:item.specialist_name }])).values()].filter((item,index,all) => all.findIndex(other => other.value === item.value) === index));
const attemptTechnologyOptions = computed(() => [...new Set(attempts.value.map(item => item.position.technology).filter(Boolean))]
  .sort((a,b) => String(a).localeCompare(String(b), 'ru'))
  .map(value => ({ value, label:value })));
const filteredRequests = computed(() => sortNewest(props.requests).filter(item => {
  const needle = query.value.trim().toLowerCase();
  return (!needle || `${item.title} ${clientName(item.client_id)}`.toLowerCase().includes(needle))
    && (!statusFilter.value || item.status === statusFilter.value)
    && (!clientFilter.value || String(item.client_id) === clientFilter.value);
}));
const availableFailureReasons = computed(() => allFailureReasons.filter(reason => {
  if (reason.startsWith('CV:')) return actionAttempt.value?.status === 'CV отправлено';
  if (reason.startsWith('Интервью:')) return actionAttempt.value?.status === 'Интервью назначено';
  return true;
}));

function toggle(set, id) { set.has(Number(id)) ? set.delete(Number(id)) : set.add(Number(id)); }
function tone(status='') {
  if (status === 'Открыт') return 'info';
  if (status === 'Закрыт') return 'neutral';
  return status.includes('неудач') ? 'danger' : status.includes('успех') ? 'success' : status.includes('Интервью') ? 'info' : 'neutral';
}
function resetForm(values={}) { Object.keys(form).forEach(key => delete form[key]); Object.assign(form, values); error.value = ''; }
function openRequest(item) { selectedRequestId.value = Number(item.id); resetForm({ title:item.title, description:item.description || '', responsible_employee_id:String(item.responsible_employee_id), request_date:String(item.request_date || item.created_at || '').slice(0,10), lifetime_weeks:String(item.lifetime_weeks || 1), status:item.status }); }
function openPosition(item) { selectedPositionId.value = Number(item.id); resetForm({ direction_department_id:directionId(item), technology:item.technology, level:item.level, quantity:item.quantity, expected_connection_time:item.expected_connection_time || 'Неизвестно', acceptable_tu_format:item.acceptable_tu_format || 'Не важно', description:item.description || '', status:item.status }); }
function openAttempt(item) { actionAttemptId.value = null; menuAttemptId.value = null; selectedAttemptId.value = Number(item.id); resetForm({ control_date:String(item.control_date || '').slice(0,10), proposed_rate:item.proposed_rate || '' }); }
function openDialog(kind, values={}) { dialog.value = kind; resetForm(values); }
function closeDialog() { dialog.value = ''; interviewPicker.value = false; actionAttemptId.value = null; resetForm(); }
function openAttemptDialog(kind, attempt, values={}) { actionAttemptId.value = Number(attempt.id); openDialog(kind, values); }
function startInterview(attempt, event) { actionAttemptId.value = Number(attempt.id); interviewAnchor.value = event?.currentTarget?.getBoundingClientRect(); error.value = ''; interviewPicker.value = true; menuAttemptId.value = null; }
const visibleAttempts = position => (position.attempts || []).filter(attempt => !attemptStatusFilter.value || attempt.status === attemptStatusFilter.value);
const sortedPositions = request => sortNewest(request.positions || []);
watch(() => props.view, view => { statusFilter.value = ['requests','positions'].includes(view) ? 'Открыт' : ''; attemptStatusFilter.value = ''; query.value = ''; menuAttemptId.value = null; });
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
function saveRequest() { return run(() => api(`/api/clients/requests/${selectedRequest.value.id}`, { method:'PATCH', body:JSON.stringify({ ...form, responsible_employee_id:Number(form.responsible_employee_id), request_date:form.request_date, lifetime_weeks:Number(form.lifetime_weeks) }) })); }
function savePosition() { return run(() => api(`/api/clients/positions/${selectedPosition.value.id}`, { method:'PATCH', body:JSON.stringify({ ...form, direction_department_id:Number(form.direction_department_id), direction:directionName(form.direction_department_id), quantity:Number(form.quantity), expected_connection_time:form.expected_connection_time || 'Неизвестно', acceptable_tu_format:form.acceptable_tu_format || 'Не важно' }) })); }
function createPosition() { return run(() => api(`/api/clients/requests/${Number(form.request_id || selectedRequest.value?.id)}/positions`, { method:'POST', body:JSON.stringify({ direction_department_id:Number(form.direction_department_id), direction:directionName(form.direction_department_id), technology:form.technology, level:form.level, quantity:Number(form.quantity || 1), expected_connection_time:form.expected_connection_time || 'Неизвестно', acceptable_tu_format:form.acceptable_tu_format || 'Не важно', description:form.description || null }) })); }
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
async function downloadCv() {
  error.value = '';
  try {
    const response = await fetch(`/api/clients/attempts/${actionAttempt.value.id}/cv`, { headers:{ Accept:'application/octet-stream' } });
    if (!response.ok) throw new Error('Не удалось скачать CV');
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url; link.download = selectedAttempt.value.cv_original_name || 'cv'; link.click();
    URL.revokeObjectURL(url);
  } catch (exception) { error.value = exception.message || String(exception); }
}
function sendCv() { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/send-cv`, { method:'POST', body:'{}' })); }
function scheduleInterview(scheduled_at) { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/interviews`, { method:'POST', body:JSON.stringify({ scheduled_at }) })); }
function completeInterview() { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/interviews/${form.interview_id}/complete`, { method:'POST', body:JSON.stringify({ rating:form.rating, feedback:form.feedback }) })); }
function scheduleConnection() { return run(() => api(`/api/clients/attempts/${actionAttempt.value.id}/schedule-connection`, { method:'POST', body:JSON.stringify({ connection_date:form.connection_date }) })); }
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
function editRequestFromDrawer() { resetForm({ title:selectedRequest.value.title, description:selectedRequest.value.description || '', responsible_employee_id:String(selectedRequest.value.responsible_employee_id), request_date:String(selectedRequest.value.request_date || selectedRequest.value.created_at || '').slice(0,10), lifetime_weeks:String(selectedRequest.value.lifetime_weeks || 1), status:selectedRequest.value.status }); dialog.value = 'edit-request'; }
function editPositionFromDrawer() { resetForm({ direction_department_id:directionId(selectedPosition.value), technology:selectedPosition.value.technology, level:selectedPosition.value.level, quantity:selectedPosition.value.quantity, expected_connection_time:selectedPosition.value.expected_connection_time || 'Неизвестно', acceptable_tu_format:selectedPosition.value.acceptable_tu_format || 'Не важно', description:selectedPosition.value.description || '', status:selectedPosition.value.status }); dialog.value = 'edit-position'; }
function closeRequest() { return run(() => api(`/api/clients/requests/${selectedRequest.value.id}`, { method:'PATCH', body:JSON.stringify({ status:'Закрыт' }) })); }
function closePosition() { return run(() => api(`/api/clients/positions/${selectedPosition.value.id}`, { method:'PATCH', body:JSON.stringify({ status:'Закрыт' }) })); }
function selectCv(file) {
  if (!file) return;
  if (!/\.(pdf|doc|docx)$/i.test(file.name) || file.size > 15 * 1024 * 1024) { error.value = 'Выберите CV в формате PDF, DOC или DOCX, не более 15 МБ.'; return; }
  form.cv = file; error.value = ''; cvDragging.value = false;
}
function dropCv(event) { cvDragging.value = false; selectCv(event.dataTransfer?.files?.[0]); }
function toggleExternal() { form.specialist_id = ''; form.specialist_name = ''; }
const attemptFormComplete = computed(() => !!form.cv && (form.is_external ? String(form.specialist_name || '').trim() : form.specialist_id));
function attemptActions(attempt) {
  const actions = [];
  if (!canManageAttempts.value) return actions;
  const add = (key,label,danger=false,interview=null) => actions.push({key,label,danger,interview});
  if (attempt.status === 'Новая' && isAccount.value) add('send-cv','CV отправлено');
  if (isAccount.value && ['CV отправлено','Интервью назначено','Интервью пройдено'].includes(attempt.status)) add('interview', (attempt.interviews?.length ? 'Назначить '+(attempt.interviews.length+1)+' интервью' : 'Назначить интервью'));
  if (isAccount.value && attempt.status === 'Интервью назначено') {
    (attempt.interviews || []).filter(interview => !interview.completed_at).forEach(interview => add('complete:'+interview.id, 'Завершить '+interview.sequence+' интервью', false, interview));
  }
  if (isAccount.value && attempt.status === 'Интервью пройдено') add('connection-date','Назначить подключение');
  if (isAccount.value && ['Ожидает подключения','Закрыт: успех'].includes(attempt.status) && !attempt.has_connection) add('connect','Создать подключение');
  if (isAccount.value && attempt.status === 'Ожидает подключения') add('success','Закрыть с успехом');
  if (!attempt.status.startsWith('Закрыт') && (attempt.status === 'Новая' ? isDirectionManager.value : isAccount.value)) add('failure','Закрыть с неудачей',true);
  if (attempt.status === 'Новая' && isDirectionManager.value) add('delete','Удалить попытку',true);
  return actions;
}
function attemptAction(action, attempt, event) {
  actionAttemptId.value = Number(attempt.id); menuAttemptId.value = null;
  if (action.key === 'interview') return startInterview(attempt,event);
  if (action.key.startsWith('complete:')) return openAttemptDialog('complete-interview',attempt,{interview_id:action.interview.id,rating:'Положительно'});
  if (action.key === 'connection-date') return openAttemptDialog('schedule-connection',attempt);
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
      <UiFilterBar><input v-model="query" class="registry-search" placeholder="Поиск"><UiSearchSelect v-model="clientFilter" :options="clientOptions" placeholder="Клиенты"/><UiSearchSelect v-model="statusFilter" :options="requestStatuses" placeholder="Все статусы запросов"/><UiSearchSelect v-model="attemptStatusFilter" :options="attemptStatuses" placeholder="Все статусы попыток"/></UiFilterBar>
      <template v-if="mode==='tree'">
        <div class="workflow-head request-grid"><div>Запрос</div><div>Статус</div></div>
        <template v-for="request in filteredRequests" :key="request.id">
          <div class="workflow-request request-grid">
            <div class="workflow-title"><button v-if="(request.positions||[]).length" class="client-toggle" :class="{open:expandedRequests.has(Number(request.id))}" @click.stop="toggle(expandedRequests,request.id)"><span/></button><span v-else class="tree-toggle-placeholder"/><button class="entity-link" @click="openRequest(request)">{{request.title}}</button><span class="request-client">({{clientName(request.client_id)}})</span><button v-if="canManagePositions&&request.status!=='Закрыт'" class="text-action" @click="openCreatePosition(request)">+ позиция</button></div>
            <div class="workflow-status"><UiBadge :tone="tone(request.status)">{{request.status}}</UiBadge></div>
          </div>
          <template v-if="expandedRequests.has(Number(request.id))" v-for="position in sortedPositions(request)" :key="position.id">
            <div class="workflow-position request-grid">
              <div class="workflow-title workflow-title--position"><button v-if="(position.attempts||[]).length" class="client-toggle tree-branch-toggle" :class="{open:expandedPositions.has(Number(position.id))}" @click.stop="toggle(expandedPositions,position.id)"><span/></button><span v-else class="tree-leaf-marker"/><button class="entity-link" @click="openPosition({...position,request})">{{position.technology}} {{position.level}}</button><span class="position-quantity">x{{position.quantity}}</span></div>
              <div class="workflow-status"><UiBadge :tone="tone(positionStatus(position))">{{positionStatus(position)}}</UiBadge></div>
            </div>
            <div v-if="expandedPositions.has(Number(position.id))" class="workflow-attempts">
              <button v-for="attempt in visibleAttempts(position)" :key="attempt.id" class="workflow-attempt request-grid" @click="openAttempt({...attempt,position:{...position,request},request,clientId:Number(request.client_id)})"><span class="attempt-title"><i class="tree-leaf-marker"/>{{attempt.specialist_name}}</span><span class="workflow-status workflow-attempt-status"><AttemptProgress :attempt="attempt"/><UiBadge :tone="tone(attempt.status)">{{attempt.status}}</UiBadge></span></button>
            </div>
          </template>
        </template>
      </template>
      <table v-else class="irlix-data-table request-list-table"><thead><tr><th>Запрос</th><th>Статус</th></tr></thead><tbody><tr v-for="request in filteredRequests" :key="request.id"><td><span class="request-list-title"><button class="entity-link" @click="openRequest(request)">{{request.title}}</button><span class="request-client">({{clientName(request.client_id)}})</span><button v-if="canManagePositions&&request.status!=='Закрыт'" class="text-action" @click="openCreatePosition(request)">+ позиция</button></span></td><td><span class="workflow-status"><UiBadge :tone="tone(request.status)">{{request.status}}</UiBadge></span></td></tr></tbody></table>
    </template>

    <template v-else-if="view==='positions'">
      <UiFilterBar><input v-model="query" class="registry-search" placeholder="Поиск"><UiSearchSelect v-model="clientFilter" :options="clientOptions" placeholder="Клиенты"/><UiSearchSelect v-model="statusFilter" :options="positionStatuses" placeholder="Все статусы позиций"/></UiFilterBar>
      <table class="irlix-data-table"><thead><tr><th>Позиция</th><th>Запрос</th><th>Клиент</th><th>Количество</th><th>Направление</th><th>Статус</th><th>Попытки</th></tr></thead><tbody><tr v-for="position in filteredPositions" :key="position.id"><td><button class="entity-link" @click="openPosition(position)">{{position.technology}} {{position.level}}</button></td><td>{{position.request.title}}</td><td>{{clientName(position.request.client_id)}}</td><td>{{position.quantity}}</td><td>{{position.direction||'—'}}</td><td><UiBadge :tone="tone(positionStatus(position))">{{positionStatus(position)}}</UiBadge></td><td>{{position.attempts?.length||0}}</td></tr></tbody></table>
    </template>

    <template v-else>
      <UiFilterBar><input v-model="query" class="registry-search" placeholder="Поиск"><UiSearchSelect v-model="statusFilter" :options="attemptStatuses" placeholder="Статусы"/><UiSearchSelect v-model="clientFilter" :options="clientOptions" placeholder="Клиенты"/><UiSearchSelect v-model="technologyFilter" :options="attemptTechnologyOptions" placeholder="Технологии"/><UiSearchSelect v-model="specialistFilter" :options="specialistOptions" placeholder="Специалисты"/></UiFilterBar>
      <div class="attempt-kanban"><section v-for="status in attemptStatuses" :key="status" class="kanban-column"><h3>{{status}} <span>{{filteredAttempts.filter(item=>item.status===status).length}}</span></h3><button v-for="attempt in filteredAttempts.filter(item=>item.status===status)" :key="attempt.id" class="attempt-card" @click="openAttempt(attempt)"><strong>{{attempt.specialist_name}}</strong><span>{{clientName(attempt.clientId)}}</span><small>{{attempt.position.technology}} {{attempt.position.level}}</small><small v-if="attempt.interviews?.length">Интервью: {{attempt.interviews.length}}</small></button></section></div>
    </template>
  </section>

  <UiDrawer :open="!!selectedRequest" :title="selectedRequest?.title || ''" width="min(1120px, 94vw)" :resizable="false" @close="selectedRequestId=null">
    <template #actions><UiButton v-if="canManageRequests&&selectedRequest.status!=='Закрыт'" compact variant="danger" :disabled="busy" @click="closeRequest">Закрыть запрос</UiButton><UiButton v-if="canManageRequests" compact variant="secondary" @click="editRequestFromDrawer">Редактировать</UiButton></template>
    <div v-if="selectedRequest" class="reference-card request-reference-card">
      <div v-if="error&&!dialog" class="error-banner">{{error}}</div>
      <section class="reference-client-panel">
        <div class="reference-client-main">
          <strong class="reference-client-name">{{selectedRequestClient?.name || clientName(selectedRequest.client_id)}}</strong>
          <div class="reference-managers">
            <span v-if="selectedRequestClient?.sales_employee_id" class="reference-manager"><i class="manager-letter manager-letter--sales">S</i>{{employeeName(selectedRequestClient.sales_employee_id)}}</span>
            <span v-if="selectedRequestClient?.account_employee_id" class="reference-manager"><i class="manager-letter manager-letter--account">A</i>{{employeeName(selectedRequestClient.account_employee_id)}}</span>
          </div>
        </div>
        <div class="reference-client-tags"><span v-if="selectedRequestClient?.sector" class="client-tag client-tag--sector">{{selectedRequestClient.sector}}</span><span v-if="selectedRequestClient?.type" class="client-tag client-tag--type">{{selectedRequestClient.type}}</span></div>
      </section>

      <section class="request-facts">
        <div class="fact-row"><b>Статус</b><span><UiBadge :tone="tone(selectedRequest.status)">{{selectedRequest.status}}</UiBadge></span></div>
        <div class="fact-row"><b>Дата запроса</b><span>{{dateRu(selectedRequest.request_date || selectedRequest.created_at)}} <em v-if="selectedRequest.deadline">[до {{dateRu(selectedRequest.deadline)}}]</em></span></div>
      </section>

      <fieldset class="reference-fieldset">
        <legend>⊖ Описание</legend>
        <p>{{selectedRequest.description || 'Описание не заполнено'}}</p>
      </fieldset>

      <nav class="request-card-tabs"><span class="active">◉ Позиции</span></nav>

      <section class="request-position-cards">
        <article v-for="position in sortedPositions(selectedRequest)" :key="position.id" class="request-position-card">
          <div class="request-position-card__main">
            <button class="position-card-title" type="button" @click="openPosition({...position,request:selectedRequest})"><strong>{{position.technology}}</strong><sup>{{position.level}}</sup></button>
            <span class="position-count">{{position.quantity}} {{Number(position.quantity)===1?'ставка':'ставки'}}</span>
            <details v-if="position.description" class="position-description-preview"><summary>Показать описание</summary><p>{{position.description}}</p></details>
          </div>
          <UiBadge :tone="tone(positionStatus(position))">{{positionStatus(position)}}</UiBadge>
        </article>
        <button v-if="canManagePositions&&selectedRequest.status!=='Закрыт'" class="new-position-bar" type="button" @click="openCreatePosition(selectedRequest)">♧＋ Новая позиция</button>
      </section>
    </div>
  </UiDrawer>

  <UiDrawer :open="!!selectedPosition" :title="positionDrawerTitle" width="min(920px, 82vw)" :resizable="false" :z-index="1050" @close="selectedPositionId=null">
    <template #actions><UiButton v-if="canManagePositions&&selectedPosition.status!=='Закрыт'" compact variant="danger" :disabled="busy" @click="closePosition">Закрыть позицию</UiButton><UiButton v-if="canManagePositions" compact variant="secondary" @click="editPositionFromDrawer">Редактировать</UiButton></template>
    <div v-if="selectedPosition" class="reference-card position-reference-card">
      <div v-if="error&&!dialog" class="error-banner">{{error}}</div>
      <section class="reference-client-panel">
        <div class="reference-client-main">
          <strong class="reference-client-name">{{selectedPositionClient?.name || clientName(selectedPosition.request.client_id)}}</strong>
          <div class="reference-managers">
            <span v-if="selectedPositionClient?.sales_employee_id" class="reference-manager"><i class="manager-letter manager-letter--sales">S</i>{{employeeName(selectedPositionClient.sales_employee_id)}}</span>
            <span v-if="selectedPositionClient?.account_employee_id" class="reference-manager"><i class="manager-letter manager-letter--account">A</i>{{employeeName(selectedPositionClient.account_employee_id)}}</span>
          </div>
        </div>
        <div class="reference-client-tags"><span v-if="selectedPositionClient?.sector" class="client-tag client-tag--sector">{{selectedPositionClient.sector}}</span><span v-if="selectedPositionClient?.type" class="client-tag client-tag--type">{{selectedPositionClient.type}}</span></div>
      </section>

      <section class="position-facts-grid">
        <div class="position-fact"><b>Статус</b><span><UiBadge :tone="tone(positionStatus(selectedPosition))">{{positionStatus(selectedPosition)}}</UiBadge></span></div>
        <div class="position-fact"><b>Ответственный</b><span>{{employeeName(selectedPosition.request.responsible_employee_id)}}</span></div>
        <div class="position-fact"><b>Направление</b><span>{{selectedPosition.direction || '—'}}</span></div>
        <div class="position-fact"><b>Технология</b><span>{{selectedPosition.technology}}</span></div>
        <div class="position-fact"><b>Уровень</b><span>{{selectedPosition.level}}</span></div>
        <div class="position-fact"><b>Количество</b><span>{{selectedPosition.quantity}}</span></div>
        <div class="position-fact"><b>Дата создания</b><span>{{dateRu(selectedPosition.created_at)}}</span></div>
        <div class="position-fact"><b>Срок запроса</b><span>{{dateRu(selectedPosition.request.deadline)}}</span></div>
        <div class="position-fact"><b>Ожидаемое время подключения</b><span>{{selectedPosition.expected_connection_time || 'Неизвестно'}}</span></div>
        <div class="position-fact"><b>Допустимый формат ТУ</b><span>{{selectedPosition.acceptable_tu_format || 'Не важно'}}</span></div>
      </section>

      <fieldset class="reference-fieldset">
        <legend>⊖ Описание запроса</legend>
        <p>{{selectedPosition.request.description || 'Описание не заполнено'}}</p>
      </fieldset>

      <fieldset class="reference-fieldset">
        <legend>⊖ Описание позиции</legend>
        <p>{{selectedPosition.description || 'Описание не заполнено'}}</p>
      </fieldset>

      <section class="attempts-reference-section">
        <div class="section-line"><h3>Попытки подключения</h3><UiButton v-if="canManageAttempts&&isDirectionManager&&selectedPosition.status!=='Закрыт'&&selectedPosition.request.status!=='Закрыт'" compact @click="openDialog('create-attempt')">Новая попытка</UiButton></div>
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

  <UiDrawer :open="!!selectedAttempt" :title="selectedAttempt?`Попытка подключения · ${selectedAttempt.specialist_name}`:''" width="min(720px, 70vw)" :resizable="false" :z-index="1080" @close="selectedAttemptId=null"><div v-if="selectedAttempt" class="detail-card"><div v-if="error&&!dialog" class="error-banner">{{error}}</div><div class="attempt-progress-header"><AttemptProgress :attempt="selectedAttempt" large/><UiBadge :tone="tone(selectedAttempt.status)">{{selectedAttempt.status}}</UiBadge></div><div class="detail-summary"><span><b>Специалист</b>{{selectedAttempt.specialist_name}}</span><span><b>Позиция</b>{{selectedAttempt.position.technology}} {{selectedAttempt.position.level}}</span><span><b>Ответственный</b>{{employeeName(selectedAttempt.responsible_employee_id)}}</span><span><b>Ставка</b>{{selectedAttempt.proposed_rate||'—'}}</span><span><b>Статус</b><UiBadge :tone="tone(selectedAttempt.status)">{{selectedAttempt.status}}</UiBadge></span><span><b>CV</b><button class="text-action cv-download" type="button" @click="downloadCv">{{selectedAttempt.cv_original_name||'Скачать'}}</button></span><span v-if="selectedAttempt.cv_sent_at"><b>CV отправлено</b>{{dateTimeRu(selectedAttempt.cv_sent_at)}}</span><span v-if="selectedAttempt.connection_date"><b>Дата подключения</b>{{dateRu(selectedAttempt.connection_date)}}</span></div><section v-if="selectedAttempt.description"><h3>Описание</h3><p>{{selectedAttempt.description}}</p></section><section v-if="selectedAttempt.interviews?.length"><h3>Интервью</h3><article v-for="interview in selectedAttempt.interviews" :key="interview.id" class="interview-row"><b>{{interview.sequence}} интервью · {{dateTimeRu(interview.scheduled_at)}}</b><span v-if="interview.completed_at">{{interview.rating}} — {{interview.feedback}}</span><UiButton v-else-if="isAccount&&canManageAttempts&&selectedAttempt.status==='Интервью назначено'" compact variant="secondary" @click="openAttemptDialog('complete-interview',selectedAttempt,{interview_id:interview.id,rating:'Положительно'})">Интервью завершено</UiButton></article></section><section v-if="selectedAttempt.failure_reasons?.length"><h3>Причины неудачи</h3><ul><li v-for="reason in selectedAttempt.failure_reasons" :key="reason">{{reason}}</li></ul></section><div class="attempt-actions"><UiButton v-for="action in attemptActions(selectedAttempt).filter(action=>!action.key.startsWith('complete:'))" :key="action.key" :variant="action.danger?'danger':'primary'" :disabled="busy" @click="attemptAction(action,selectedAttempt,$event)">{{action.label}}</UiButton></div></div></UiDrawer>

  <UiDrawer :open="!!dialog" :title="({ 'create-position':'Новая позиция','create-attempt':'Новая попытка подключения','edit-request':'Редактирование запроса','edit-position':'Редактирование позиции','complete-interview':'Итоги интервью','schedule-connection':'Назначить подключение','close-failure':'Закрыть с неудачей' })[dialog]||''" width="520px" :z-index="1100" @close="closeDialog"><form class="entity-form" @submit.prevent="({ 'create-position':createPosition,'create-attempt':createAttempt,'edit-request':saveRequest,'edit-position':savePosition,'complete-interview':completeInterview,'schedule-connection':scheduleConnection,'close-failure':closeFailure })[dialog]?.()"><div v-if="error" class="error-banner">{{error}}</div>
    <template v-if="dialog==='edit-request'"><label>Название<input v-model="form.title" required></label><label>Ответственный<UiSearchSelect v-model="form.responsible_employee_id" :options="employeeOptions" :clearable="false"/></label><label>Дата запроса<input v-model="form.request_date" type="date" required></label><label>Время жизни<UiSearchSelect v-model="form.lifetime_weeks" :options="[1,2,3,4].map(value=>({value:String(value),label:`${value} ${value===1?'неделя':'недели'}`}))" :clearable="false"/></label><label>Статус<select v-model="form.status"><option v-for="status in requestStatuses" :key="status">{{status}}</option></select></label><label>Описание<textarea v-model="form.description"/></label></template>
    <template v-if="['create-position','edit-position'].includes(dialog)"><label>Технология<UiSearchSelect v-model="form.technology" :options="technologyOptions" placeholder="Не выбрано" search-placeholder="Поиск технологии" :clearable="false"/></label><label>Направление<UiSearchSelect v-model="form.direction_department_id" :options="directionOptions" placeholder="Не выбрано" search-placeholder="Поиск направления" :clearable="false"/></label><label>Уровень<UiSearchSelect v-model="form.level" :options="levelOptions.map(value=>({value,label:value}))" placeholder="Не выбрано" search-placeholder="Поиск уровня" :clearable="false"/></label><label>Количество<input v-model="form.quantity" type="number" min="1" required></label><label>Ожидаемое время подключения<UiSearchSelect v-model="form.expected_connection_time" :options="expectedConnectionTimes.map(value=>({value,label:value}))" :clearable="false"/></label><label>Допустимый формат ТУ<UiSearchSelect v-model="form.acceptable_tu_format" :options="acceptableTuFormats.map(value=>({value,label:value}))" :clearable="false"/></label><label v-if="dialog==='edit-position'">Статус<select v-model="form.status"><option v-for="status in positionMainStatuses" :key="status">{{status}}</option></select></label><label>Описание<textarea v-model="form.description"/></label></template>
    <template v-if="dialog==='create-attempt'">
      <label v-if="!form.is_external">Сотрудник<UiSearchSelect v-model="form.specialist_id" :options="attemptSpecialistOptions" placeholder="Выберите сотрудника" search-placeholder="Поиск сотрудника или направления" :clearable="false"/></label>
      <label v-else>ФИО специалиста<input v-model="form.specialist_name" required maxlength="255" placeholder="Введите ФИО"></label>
      <label class="external-checkbox"><input v-model="form.is_external" type="checkbox" @change="toggleExternal">Внешний специалист</label>
      <div class="cv-field"><span>Файл CV<span class="required-marker">*</span></span><input ref="cvInput" class="cv-file-input" type="file" accept=".pdf,.doc,.docx" tabindex="-1" @change="selectCv($event.target.files[0])">
        <div class="cv-dropzone" :class="{dragging:cvDragging}" role="button" tabindex="0" aria-label="Загрузить файл CV" @click="cvInput?.click()" @keydown.enter.prevent="cvInput?.click()" @keydown.space.prevent="cvInput?.click()" @dragover.prevent="cvDragging=true" @dragleave.prevent="cvDragging=false" @drop.prevent="dropCv">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true"><path d="M24 29V5m-9 9 9-9 9 9M14 25H7v16h34V25h-7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="34" cy="34" r="2" fill="currentColor"/></svg>
          <span v-if="form.cv" class="cv-filename">{{form.cv.name}}</span><span v-else><u>Нажмите, чтобы загрузить</u> или перенесите сюда файл</span>
        </div><small>PDF, DOC, DOCX · до 15 МБ</small>
      </div>
      <label>Комментарий<textarea v-model="form.description" rows="4" maxlength="5000" placeholder="Необязательно"/></label>
    </template>
    <template v-if="dialog==='complete-interview'"><label>Оценка<UiSearchSelect v-model="form.rating" :options="ratings" :clearable="false" aria-label="Оценка интервью"/></label><label>Фидбек<textarea v-model="form.feedback" class="interview-feedback" rows="7" maxlength="1000" required/></label></template>
    <template v-if="dialog==='schedule-connection'"><label>Дата подключения<input v-model="form.connection_date" type="date" required></label></template>
    <template v-if="dialog==='close-failure'"><fieldset class="reason-list"><legend>Причины</legend><label v-for="reason in availableFailureReasons" :key="reason"><input v-model="form.reasons" type="checkbox" :value="reason">{{reason}}</label></fieldset></template>
    <div class="form-actions"><UiButton type="submit" :disabled="busy||(dialog==='create-attempt'&&!attemptFormComplete)">{{busy?'Сохраняю…':'Сохранить'}}</UiButton><UiButton type="button" variant="secondary" @click="closeDialog">Отмена</UiButton></div>
  </form></UiDrawer>
  <InterviewScheduler v-if="interviewPicker" :open="interviewPicker" :anchor="interviewAnchor" :busy="busy" :error="error" @close="closeDialog" @apply="scheduleInterview"/>
</template>

<style scoped>
.workflow-head{background:#f2f3f4;color:#59616b;font-size:12px;font-weight:600}.request-grid{display:grid;grid-template-columns:minmax(0,1fr) 262px;align-items:center}.request-grid>div{min-width:0;padding:6px 10px;border-bottom:1px solid #edf0f2}.workflow-request{background:transparent;font-size:13px}.workflow-position{position:relative;margin-left:32px;background:transparent;font-size:12px}.workflow-title{position:relative;display:flex;align-items:center;gap:6px;min-height:22px}.workflow-title--position{padding-left:32px!important}.workflow-position::before,.workflow-attempts::before{content:"";position:absolute;left:11px;top:-1px;bottom:0;border-left:1px dashed #c8cdd2}.workflow-title--position::after,.attempt-title::after{content:"";position:absolute;left:11px;top:50%;width:20px;border-top:1px dashed #c8cdd2}.client-toggle{position:relative;z-index:1;width:22px;min-width:22px;height:22px;padding:0;display:grid;place-items:center;border:0;border-radius:6px;background:#fff;cursor:pointer}.client-toggle:hover{background:#e8ecee}.client-toggle span{width:7px;height:7px;border-right:1.5px solid #7d858f;border-bottom:1.5px solid #7d858f;transform:rotate(-45deg);transition:transform .14s ease}.client-toggle.open span{transform:rotate(45deg)}.tree-branch-toggle{position:absolute;left:0;top:50%;transform:translateY(-50%)}.tree-toggle-placeholder{width:22px;min-width:22px}.tree-leaf-marker{position:absolute;z-index:1;left:8.5px;top:50%;width:5px;height:5px;border-radius:50%;background:#aeb5bc;transform:translateY(-50%)}.attempt-title .tree-leaf-marker{left:8.5px}.entity-link,.text-action{border:0;background:transparent;padding:0;cursor:pointer;font:inherit}.entity-link{font-weight:600;color:#20262d;text-align:left}.entity-link:hover,.text-action:hover{color:#078d6c}.text-action{color:#078d6c;font-size:11px;white-space:nowrap}.request-client,.position-quantity{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#8a929b;font-size:11px}.position-quantity{overflow:visible}.workflow-status{display:flex;justify-content:flex-end}.workflow-status :deep(.irlix-badge),.workflow-status :deep(.ui-badge){width:160px;justify-content:center;white-space:nowrap}.workflow-attempts{position:relative;margin-left:64px;padding:0 0 3px}.workflow-attempt{width:100%;padding:0;border:0;background:transparent;text-align:left;cursor:pointer}.workflow-attempt:hover{background:#fafcfc}.workflow-attempt>span{min-width:0;padding:5px 10px;border-bottom:1px solid #f0f1f2}.attempt-title{position:relative;padding-left:32px!important;color:#20262d;font-size:12px}.attempt-title::after{width:20px}.request-list-table th:last-child,.request-list-table td:last-child{width:180px}.request-list-title{display:flex;align-items:center;gap:6px}.attempt-kanban{display:grid;grid-template-columns:repeat(7,minmax(210px,1fr));gap:10px;overflow:auto;padding:12px 16px}.kanban-column{min-height:520px;padding:10px;background:#f5f6f7}.kanban-column h3{display:flex;justify-content:space-between;margin:0 0 10px;font-size:12px}.kanban-column h3 span{color:#89919a}.attempt-card{width:100%;display:grid;gap:5px;margin-bottom:8px;padding:10px;border:0;background:#fff;box-shadow:0 1px 3px rgba(20,30,40,.09);text-align:left;cursor:pointer}.attempt-card span,.attempt-card small{font-size:10px;color:#6d7680}
.reference-card{display:grid;gap:18px;padding:4px 2px 28px;color:#171b20;font-size:14px}.reference-client-panel{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:14px 16px;border:1px solid #dcdfe3;border-radius:5px;background:#fff}.reference-client-main{display:grid;gap:8px;min-width:0}.reference-client-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:20px;line-height:1.1}.reference-managers{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.reference-manager{display:flex;align-items:center;gap:5px;color:#505862;white-space:nowrap}.manager-letter{display:grid;place-items:center;width:18px;height:18px;border:1px solid currentColor;border-radius:5px;font-size:11px;font-style:normal;line-height:1}.manager-letter--sales{color:#f38a29;background:#fff4e9}.manager-letter--account{color:#2cad69;background:#eaf9ef}.reference-client-tags{display:flex;align-items:center;justify-content:flex-end;gap:9px;flex-wrap:wrap}.client-tag{padding:6px 11px;border-radius:4px;font-size:12px;white-space:nowrap}.client-tag--sector{background:#c9f3e8;color:#087f67}.client-tag--type{background:#e4ddff;color:#7661d4}.request-facts{display:grid;gap:16px;padding:0 10px}.fact-row{display:grid;grid-template-columns:155px minmax(0,1fr);align-items:center;gap:14px}.fact-row>b,.position-fact>b{font-weight:500}.fact-row em{font-style:normal}.reference-fieldset{min-width:0;margin:0 0 0;padding:12px 18px 16px;border:1px solid #d9dde1;border-radius:5px}.reference-fieldset legend{padding:0 12px;color:#242a30;font-weight:600;font-size:15px}.reference-fieldset p{margin:0;white-space:pre-wrap;line-height:1.48}.request-card-tabs{display:grid;grid-template-columns:1fr;padding:4px;background:#f1f1f2;border-radius:8px}.request-card-tabs .active{display:flex;align-items:center;justify-content:center;min-height:36px;border-radius:7px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);font-weight:600}.request-position-cards{display:grid;gap:10px}.request-position-card{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:14px;border:1px solid #dbdee2;border-radius:7px;background:#fff}.request-position-card__main{display:grid;gap:5px;min-width:0}.position-card-title{display:flex;align-items:baseline;gap:2px;width:max-content;max-width:100%;padding:0;border:0;background:transparent;color:#1c252c;cursor:pointer}.position-card-title strong{font-size:16px}.position-card-title sup{color:#00a3a0;font-size:12px;font-weight:700}.position-count{font-size:12px;color:#656e78}.position-description-preview{font-size:12px}.position-description-preview summary{color:#4269bd;cursor:pointer;list-style:none}.position-description-preview summary::-webkit-details-marker{display:none}.position-description-preview p{margin:6px 0 0;color:#4b545e;white-space:pre-wrap}.new-position-bar{min-height:36px;border:0;border-radius:7px;background:#f1f1f2;color:#525b65;font-weight:600;cursor:pointer}.new-position-bar:hover{background:#e9ebed}.position-facts-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px 32px;padding:2px 10px}.position-fact{display:grid;grid-template-columns:155px minmax(0,1fr);align-items:center;gap:16px;min-width:0}.position-fact span{min-width:0;overflow:hidden;text-overflow:ellipsis}.attempts-reference-section{padding-top:4px}.attempts-reference-section h3{margin:0;font-size:15px}.empty-attempts{margin:10px 0;color:#8a929b;font-size:12px}.detail-card{display:grid;gap:18px;font-size:14px;color:#303943}.detail-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.detail-summary span{display:grid;gap:6px;font-size:14px}.detail-summary b{color:#737c86;font-size:13px;font-weight:500}.detail-card section{padding-top:16px;border-top:1px solid #eceef0}.detail-card h3{margin:0 0 10px;font-size:14px}.detail-card p{white-space:pre-wrap;line-height:1.55}.section-line{display:flex;align-items:center;justify-content:space-between}.detail-row{width:100%;display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:12px;padding:10px 0;border:0;border-bottom:1px solid #edf0f2;background:transparent;text-align:left;cursor:pointer}.interview-row{display:grid;gap:7px;padding:10px 0;border-bottom:1px solid #edf0f2;font-size:14px}.attempt-actions{display:flex;flex-wrap:wrap;gap:8px;padding-top:12px}.reason-list{display:grid;gap:9px;border:0;padding:0}.reason-list label{display:flex!important;grid-template-columns:auto 1fr!important;align-items:start;gap:8px!important;font-weight:400!important}.reason-list input{width:auto!important;min-height:auto!important;margin-top:2px}
@media(max-width:720px){.request-grid{grid-template-columns:minmax(0,1fr) 145px}.workflow-status :deep(.irlix-badge),.workflow-status :deep(.ui-badge){width:132px}.attempt-kanban{padding-inline:10px}.detail-summary{grid-template-columns:1fr}.reference-client-panel{align-items:flex-start;flex-direction:column}.reference-client-tags{justify-content:flex-start}.position-facts-grid{grid-template-columns:1fr}.fact-row,.position-fact{grid-template-columns:135px minmax(0,1fr)}}

.workflow-attempt-status{align-items:center;gap:12px}
.workflow-attempt-status :deep(.attempt-progress){flex:none}
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
@media(max-width:720px){.request-grid{grid-template-columns:minmax(0,1fr) 230px}.position-attempt-row{grid-template-columns:minmax(90px,1fr) auto 28px;gap:8px}.position-attempt-row .attempt-created{display:none}.position-attempt-row>:deep(.attempt-progress){grid-column:1}.position-attempt-row>:deep(.irlix-badge){grid-column:2}.position-attempt-row .attempt-menu{grid-column:3;grid-row:1/3}}
</style>
