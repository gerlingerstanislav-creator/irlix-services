<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiFilterBar, UiSearchSelect } from '@irlix/ui';

const props = defineProps({
  view: { type: String, required: true },
  mode: { type: String, default: 'tree' },
  requests: { type: Array, default: () => [] },
  clients: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
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
const statusFilter = ref('');
const clientFilter = ref('');
const specialistFilter = ref('');
const technologyFilter = ref('');
const form = reactive({});
let treeInitialized = false;

const attemptStatuses = ['Новая','CV отправлено','Интервью назначено','Интервью пройдено','Ожидает подключения','Закрыт: успех','Закрыт: неудача'];
const requestStatuses = ['Новый','В работе','Закрыт: успех','Закрыт: неудача'];
const positionStatuses = ['Ждёт кандидатов','На рассмотрении','Частично закрыта','Закрыта: успех','Закрыта: неудача'];
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
const clientName = id => props.clients.find(item => Number(item.id) === Number(id))?.name || `#${id}`;
const dateRu = value => value ? new Date(value).toLocaleDateString('ru-RU') : '—';
const dateTimeRu = value => value ? new Date(value).toLocaleString('ru-RU', { dateStyle:'short', timeStyle:'short' }) : '—';
const roles = computed(() => props.access.roles || []);
const isAdmin = computed(() => !!props.access.platform_admin);
const isDirectionManager = computed(() => isAdmin.value || roles.value.includes('department-manager'));
const isAccount = computed(() => isAdmin.value || roles.value.some(role => ['account-manager','accounting-head','client-service-head'].includes(role)));
const canManageRequests = computed(() => isAccount.value && !!props.access.permissions?.['requests.manage']?.allowed);
const canManagePositions = computed(() => isAccount.value && !!props.access.permissions?.['positions.manage']?.allowed);
const canManageAttempts = computed(() => !!props.access.permissions?.['attempts.manage']?.allowed);

const positions = computed(() => props.requests.flatMap(request => (request.positions || []).map(position => ({ ...position, request }))));
const attempts = computed(() => positions.value.flatMap(position => (position.attempts || []).map(attempt => ({ ...attempt, position, request:position.request, clientId:Number(position.request.client_id) }))));
const selectedRequest = computed(() => props.requests.find(item => Number(item.id) === Number(selectedRequestId.value)) || null);
const selectedPosition = computed(() => positions.value.find(item => Number(item.id) === Number(selectedPositionId.value)) || null);
const selectedAttempt = computed(() => attempts.value.find(item => Number(item.id) === Number(selectedAttemptId.value)) || null);
const filteredPositions = computed(() => positions.value.filter(item => {
  const needle = query.value.trim().toLowerCase();
  return (!needle || `${item.technology} ${item.level} ${item.direction || ''}`.toLowerCase().includes(needle))
    && (!statusFilter.value || item.status === statusFilter.value)
    && (!clientFilter.value || String(item.request.client_id) === clientFilter.value);
}));
const filteredAttempts = computed(() => attempts.value.filter(item => {
  const needle = query.value.trim().toLowerCase();
  return (!needle || `${item.specialist_name} ${item.position.technology} ${item.position.level}`.toLowerCase().includes(needle))
    && (!statusFilter.value || item.status === statusFilter.value)
    && (!clientFilter.value || String(item.clientId) === clientFilter.value)
    && (!technologyFilter.value || item.position.technology === technologyFilter.value)
    && (!specialistFilter.value || String(item.specialist_id) === specialistFilter.value);
}));
const specialistOptions = computed(() => [...new Map(attempts.value.map(item => [String(item.specialist_id), { value:String(item.specialist_id), label:item.specialist_name }])).values()]);
const attemptTechnologyOptions = computed(() => [...new Set(attempts.value.map(item => item.position.technology).filter(Boolean))]
  .sort((a,b) => String(a).localeCompare(String(b), 'ru'))
  .map(value => ({ value, label:value })));
const filteredRequests = computed(() => props.requests.filter(item => {
  const needle = query.value.trim().toLowerCase();
  return (!needle || `${item.title} ${clientName(item.client_id)}`.toLowerCase().includes(needle))
    && (!statusFilter.value || item.status === statusFilter.value)
    && (!clientFilter.value || String(item.client_id) === clientFilter.value);
}));
const availableFailureReasons = computed(() => allFailureReasons.filter(reason => {
  if (reason.startsWith('CV:')) return selectedAttempt.value?.status === 'CV отправлено';
  if (reason.startsWith('Интервью:')) return selectedAttempt.value?.status === 'Интервью назначено';
  return true;
}));

function toggle(set, id) { set.has(Number(id)) ? set.delete(Number(id)) : set.add(Number(id)); }
function tone(status='') { return status.includes('неудач') ? 'danger' : status.includes('успех') ? 'success' : status.includes('Интервью') ? 'info' : 'neutral'; }
function resetForm(values={}) { Object.keys(form).forEach(key => delete form[key]); Object.assign(form, values); error.value = ''; }
function openRequest(item) { selectedRequestId.value = Number(item.id); resetForm({ title:item.title, description:item.description || '', responsible_employee_id:String(item.responsible_employee_id), request_date:String(item.request_date || item.created_at || '').slice(0,10), lifetime_weeks:String(item.lifetime_weeks || 1), status:item.status }); }
function openPosition(item) { selectedPositionId.value = Number(item.id); resetForm({ direction:item.direction || '', technology:item.technology, level:item.level, quantity:item.quantity, description:item.description || '', status:item.status }); }
function openAttempt(item) { selectedAttemptId.value = Number(item.id); resetForm({ control_date:String(item.control_date || '').slice(0,10), proposed_rate:item.proposed_rate || '' }); }
function openDialog(kind, values={}) { dialog.value = kind; resetForm(values); }
function closeDialog() { dialog.value = ''; resetForm(); }
function openCreatePosition(request) { openDialog('create-position', { request_id:Number(request.id), quantity:1 }); }

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
function savePosition() { return run(() => api(`/api/clients/positions/${selectedPosition.value.id}`, { method:'PATCH', body:JSON.stringify({ ...form, quantity:Number(form.quantity) }) })); }
function createPosition() { return run(() => api(`/api/clients/requests/${Number(form.request_id || selectedRequest.value?.id)}/positions`, { method:'POST', body:JSON.stringify({ direction:form.direction || null, technology:form.technology, level:form.level, quantity:Number(form.quantity || 1), description:form.description || null }) })); }
function createAttempt() {
  const specialist = props.employees.find(item => Number(item.id) === Number(form.specialist_id));
  const body = new FormData();
  body.append('specialist_id', form.specialist_id); body.append('specialist_name', specialist?.full_name || `#${form.specialist_id}`);
  if (form.responsible_employee_id) body.append('responsible_employee_id', form.responsible_employee_id);
  if (form.control_date) body.append('control_date', form.control_date);
  if (form.proposed_rate) body.append('proposed_rate', form.proposed_rate);
  if (form.description) body.append('description', form.description);
  if (form.cv) body.append('cv', form.cv);
  return run(() => api(`/api/clients/positions/${selectedPosition.value.id}/attempts`, { method:'POST', body }));
}
async function downloadCv() {
  error.value = '';
  try {
    const response = await fetch(`/api/clients/attempts/${selectedAttempt.value.id}/cv`, { headers:{ Accept:'application/octet-stream' } });
    if (!response.ok) throw new Error('Не удалось скачать CV');
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url; link.download = selectedAttempt.value.cv_original_name || 'cv'; link.click();
    URL.revokeObjectURL(url);
  } catch (exception) { error.value = exception.message || String(exception); }
}
function sendCv() { return run(() => api(`/api/clients/attempts/${selectedAttempt.value.id}/send-cv`, { method:'POST', body:'{}' })); }
function scheduleInterview() { return run(() => api(`/api/clients/attempts/${selectedAttempt.value.id}/interviews`, { method:'POST', body:JSON.stringify({ scheduled_at:form.scheduled_at }) })); }
function completeInterview() { return run(() => api(`/api/clients/attempts/${selectedAttempt.value.id}/interviews/${form.interview_id}/complete`, { method:'POST', body:JSON.stringify({ rating:form.rating, feedback:form.feedback }) })); }
function scheduleConnection() { return run(() => api(`/api/clients/attempts/${selectedAttempt.value.id}/schedule-connection`, { method:'POST', body:JSON.stringify({ connection_date:form.connection_date }) })); }
function closeSuccess() { return run(() => api(`/api/clients/attempts/${selectedAttempt.value.id}/close-success`, { method:'POST', body:'{}' })); }
function closeFailure() { return run(() => api(`/api/clients/attempts/${selectedAttempt.value.id}/close-failure`, { method:'POST', body:JSON.stringify({ reasons:form.reasons || [] }) })); }
async function deleteAttempt() {
  if (!window.confirm('Удалить новую попытку?')) return;
  busy.value = true; error.value = '';
  try {
    await api(`/api/clients/attempts/${selectedAttempt.value.id}`, { method:'DELETE' });
    selectedAttemptId.value = null;
    emit('changed');
  } catch (exception) { error.value = exception.message || String(exception); }
  finally { busy.value = false; }
}
function editRequestFromDrawer() { resetForm({ title:selectedRequest.value.title, description:selectedRequest.value.description || '', responsible_employee_id:String(selectedRequest.value.responsible_employee_id), request_date:String(selectedRequest.value.request_date || selectedRequest.value.created_at || '').slice(0,10), lifetime_weeks:String(selectedRequest.value.lifetime_weeks || 1), status:selectedRequest.value.status }); dialog.value = 'edit-request'; }
function editPositionFromDrawer() { resetForm({ direction:selectedPosition.value.direction || '', technology:selectedPosition.value.technology, level:selectedPosition.value.level, quantity:selectedPosition.value.quantity, description:selectedPosition.value.description || '', status:selectedPosition.value.status }); dialog.value = 'edit-position'; }
function latestPendingInterview(item) { return [...(item?.interviews || [])].reverse().find(interview => !interview.completed_at); }
</script>

<template>
  <section class="workflow-view">
    <template v-if="view==='requests'">
      <UiFilterBar><input v-model="query" class="registry-search" placeholder="Поиск"><UiSearchSelect v-model="clientFilter" :options="clientOptions" placeholder="Клиенты"/><UiSearchSelect v-model="statusFilter" :options="requestStatuses" placeholder="Статусы"/></UiFilterBar>
      <template v-if="mode==='tree'">
        <div class="workflow-head request-grid"><div>Запрос</div><div>Статус</div></div>
        <template v-for="request in filteredRequests" :key="request.id">
        <div class="workflow-request request-grid">
          <div class="workflow-title"><button v-if="(request.positions||[]).length" class="client-toggle" :class="{open:expandedRequests.has(Number(request.id))}" @click.stop="toggle(expandedRequests,request.id)"><span/></button><span v-else class="tree-toggle-placeholder"/><button class="entity-link" @click="openRequest(request)">{{request.title}}</button><span class="request-client">({{clientName(request.client_id)}})</span><button v-if="canManagePositions" class="text-action" @click="openCreatePosition(request)">+ позиция</button></div>
          <div class="workflow-status"><UiBadge :tone="tone(request.status)">{{request.status}}</UiBadge></div>
        </div>
        <template v-if="expandedRequests.has(Number(request.id))" v-for="position in request.positions||[]" :key="position.id">
          <div class="workflow-position request-grid">
            <div class="workflow-title workflow-title--position"><button v-if="(position.attempts||[]).length" class="client-toggle tree-branch-toggle" :class="{open:expandedPositions.has(Number(position.id))}" @click.stop="toggle(expandedPositions,position.id)"><span/></button><span v-else class="tree-leaf-marker"/><button class="entity-link" @click="openPosition({...position,request})">{{position.technology}} {{position.level}}</button><span class="position-quantity">x{{position.quantity}}</span></div>
            <div class="workflow-status"><UiBadge :tone="tone(position.status)">{{position.status}}</UiBadge></div>
          </div>
          <div v-if="expandedPositions.has(Number(position.id))" class="workflow-attempts">
            <button v-for="attempt in position.attempts||[]" :key="attempt.id" class="workflow-attempt request-grid" @click="openAttempt({...attempt,position:{...position,request},request,clientId:Number(request.client_id)})"><span class="attempt-title"><i class="tree-leaf-marker"/>{{attempt.specialist_name}}</span><span class="workflow-status"><UiBadge :tone="tone(attempt.status)">{{attempt.status}}</UiBadge></span></button>
          </div>
        </template>
        </template>
      </template>
      <table v-else class="irlix-data-table request-list-table"><thead><tr><th>Запрос</th><th>Статус</th></tr></thead><tbody><tr v-for="request in filteredRequests" :key="request.id"><td><span class="request-list-title"><button class="entity-link" @click="openRequest(request)">{{request.title}}</button><span class="request-client">({{clientName(request.client_id)}})</span><button v-if="canManagePositions" class="text-action" @click="openCreatePosition(request)">+ позиция</button></span></td><td><span class="workflow-status"><UiBadge :tone="tone(request.status)">{{request.status}}</UiBadge></span></td></tr></tbody></table>
    </template>

    <template v-else-if="view==='positions'">
      <UiFilterBar><input v-model="query" class="registry-search" placeholder="Поиск"><UiSearchSelect v-model="clientFilter" :options="clientOptions" placeholder="Клиенты"/><UiSearchSelect v-model="statusFilter" :options="positionStatuses" placeholder="Статусы"/></UiFilterBar>
      <table class="irlix-data-table"><thead><tr><th>Позиция</th><th>Запрос</th><th>Клиент</th><th>Количество</th><th>Направление</th><th>Статус</th><th>Попытки</th></tr></thead><tbody><tr v-for="position in filteredPositions" :key="position.id"><td><button class="entity-link" @click="openPosition(position)">{{position.technology}} {{position.level}}</button></td><td>{{position.request.title}}</td><td>{{clientName(position.request.client_id)}}</td><td>{{position.quantity}}</td><td>{{position.direction||'—'}}</td><td><UiBadge :tone="tone(position.status)">{{position.status}}</UiBadge></td><td>{{position.attempts?.length||0}}</td></tr></tbody></table>
    </template>

    <template v-else>
      <UiFilterBar><input v-model="query" class="registry-search" placeholder="Поиск"><UiSearchSelect v-model="statusFilter" :options="attemptStatuses" placeholder="Статусы"/><UiSearchSelect v-model="clientFilter" :options="clientOptions" placeholder="Клиенты"/><UiSearchSelect v-model="technologyFilter" :options="attemptTechnologyOptions" placeholder="Технологии"/><UiSearchSelect v-model="specialistFilter" :options="specialistOptions" placeholder="Специалисты"/></UiFilterBar>
      <div class="attempt-kanban"><section v-for="status in attemptStatuses" :key="status" class="kanban-column"><h3>{{status}} <span>{{filteredAttempts.filter(item=>item.status===status).length}}</span></h3><button v-for="attempt in filteredAttempts.filter(item=>item.status===status)" :key="attempt.id" class="attempt-card" @click="openAttempt(attempt)"><strong>{{attempt.specialist_name}}</strong><span>{{clientName(attempt.clientId)}}</span><small>{{attempt.position.technology}} {{attempt.position.level}}</small><small v-if="attempt.interviews?.length">Интервью: {{attempt.interviews.length}}</small></button></section></div>
    </template>
  </section>

  <UiDrawer :open="!!selectedRequest" :title="selectedRequest?.title || ''" width="650px" @close="selectedRequestId=null"><template #actions><UiButton v-if="canManageRequests" compact variant="secondary" @click="editRequestFromDrawer">Редактировать</UiButton></template><div v-if="selectedRequest" class="detail-card"><div class="detail-summary"><span><b>Клиент</b>{{clientName(selectedRequest.client_id)}}</span><span><b>Статус</b><UiBadge :tone="tone(selectedRequest.status)">{{selectedRequest.status}}</UiBadge></span><span><b>Дата запроса</b>{{dateRu(selectedRequest.request_date||selectedRequest.created_at)}}</span><span><b>Время жизни</b>{{selectedRequest.lifetime_weeks||1}} нед.</span><span><b>Ответственный</b>{{employeeName(selectedRequest.responsible_employee_id)}}</span></div><section><h3>Описание запроса</h3><p>{{selectedRequest.description||'Описание не заполнено'}}</p></section><section><div class="section-line"><h3>Позиции</h3><button v-if="canManagePositions" class="text-action" @click="openDialog('create-position',{quantity:1})">+ позиция</button></div><button v-for="position in selectedRequest.positions||[]" :key="position.id" class="detail-row" @click="openPosition({...position,request:selectedRequest})"><strong>{{position.technology}} {{position.level}}</strong><span>{{position.quantity}} шт.</span><UiBadge :tone="tone(position.status)">{{position.status}}</UiBadge></button></section></div></UiDrawer>

  <UiDrawer :open="!!selectedPosition" :title="selectedPosition?`${selectedPosition.technology} ${selectedPosition.level}`:''" width="650px" @close="selectedPositionId=null"><template #actions><UiButton v-if="canManagePositions" compact variant="secondary" @click="editPositionFromDrawer">Редактировать</UiButton></template><div v-if="selectedPosition" class="detail-card"><div class="detail-summary"><span><b>Запрос</b>{{selectedPosition.request.title}}</span><span><b>Клиент</b>{{clientName(selectedPosition.request.client_id)}}</span><span><b>Статус</b><UiBadge :tone="tone(selectedPosition.status)">{{selectedPosition.status}}</UiBadge></span><span><b>Направление</b>{{selectedPosition.direction||'—'}}</span><span><b>Количество</b>{{selectedPosition.quantity}}</span></div><section><h3>Описание позиции</h3><p>{{selectedPosition.description||'Описание не заполнено'}}</p></section><section><div class="section-line"><h3>Попытки</h3><UiButton v-if="canManageAttempts&&isDirectionManager" compact @click="openDialog('create-attempt')">Новая попытка</UiButton></div><button v-for="attempt in selectedPosition.attempts||[]" :key="attempt.id" class="detail-row" @click="openAttempt({...attempt,position:selectedPosition,request:selectedPosition.request,clientId:Number(selectedPosition.request.client_id)})"><strong>{{attempt.specialist_name}}</strong><span>{{dateRu(attempt.created_at)}}</span><UiBadge :tone="tone(attempt.status)">{{attempt.status}}</UiBadge></button></section></div></UiDrawer>

  <UiDrawer :open="!!selectedAttempt" :title="selectedAttempt?`Попытка подключения · ${selectedAttempt.specialist_name}`:''" width="650px" @close="selectedAttemptId=null"><div v-if="selectedAttempt" class="detail-card"><div class="detail-summary"><span><b>Специалист</b>{{selectedAttempt.specialist_name}}</span><span><b>Позиция</b>{{selectedAttempt.position.technology}} {{selectedAttempt.position.level}}</span><span><b>Ответственный</b>{{employeeName(selectedAttempt.responsible_employee_id)}}</span><span><b>Ставка</b>{{selectedAttempt.proposed_rate||'—'}}</span><span><b>Статус</b><UiBadge :tone="tone(selectedAttempt.status)">{{selectedAttempt.status}}</UiBadge></span><span><b>CV</b><button class="text-action cv-download" type="button" @click="downloadCv">{{selectedAttempt.cv_original_name||'Скачать'}}</button></span><span v-if="selectedAttempt.cv_sent_at"><b>CV отправлено</b>{{dateTimeRu(selectedAttempt.cv_sent_at)}}</span><span v-if="selectedAttempt.connection_date"><b>Дата подключения</b>{{dateRu(selectedAttempt.connection_date)}}</span></div><section v-if="selectedAttempt.description"><h3>Описание</h3><p>{{selectedAttempt.description}}</p></section><section v-if="selectedAttempt.interviews?.length"><h3>Интервью</h3><article v-for="interview in selectedAttempt.interviews" :key="interview.id" class="interview-row"><b>{{interview.sequence}} интервью · {{dateTimeRu(interview.scheduled_at)}}</b><span v-if="interview.completed_at">{{interview.rating}} — {{interview.feedback}}</span><UiButton v-else-if="isAccount" compact variant="secondary" @click="openDialog('complete-interview',{interview_id:interview.id,rating:'Положительно'})">Интервью завершено</UiButton></article></section><section v-if="selectedAttempt.failure_reasons?.length"><h3>Причины неудачи</h3><ul><li v-for="reason in selectedAttempt.failure_reasons" :key="reason">{{reason}}</li></ul></section><div class="attempt-actions"><UiButton v-if="selectedAttempt.status==='Новая'&&isAccount" @click="sendCv">CV отправлено</UiButton><UiButton v-if="isAccount&&['CV отправлено','Интервью назначено','Интервью пройдено'].includes(selectedAttempt.status)" @click="openDialog('schedule-interview')">Назначить {{(selectedAttempt.interviews?.length||0)+1}} интервью</UiButton><UiButton v-if="selectedAttempt.status==='Интервью пройдено'&&isAccount" @click="openDialog('schedule-connection')">Назначить подключение</UiButton><UiButton v-if="['Ожидает подключения','Закрыт: успех'].includes(selectedAttempt.status)&&!selectedAttempt.has_connection&&isAccount" @click="emit('connect-attempt',selectedAttempt)">Создать подключение</UiButton><UiButton v-if="selectedAttempt.status==='Ожидает подключения'&&isAccount" @click="closeSuccess">Закрыть с успехом</UiButton><UiButton v-if="!selectedAttempt.status.startsWith('Закрыт')&&((selectedAttempt.status==='Новая'&&isDirectionManager)||(selectedAttempt.status!=='Новая'&&isAccount))" variant="danger" @click="openDialog('close-failure',{reasons:[]})">Закрыть с неудачей</UiButton><UiButton v-if="selectedAttempt.status==='Новая'&&isDirectionManager" variant="danger" :disabled="busy" @click="deleteAttempt">Удалить попытку</UiButton></div></div></UiDrawer>

  <UiDrawer :open="!!dialog" :title="({ 'create-position':'Новая позиция','create-attempt':'Новая попытка подключения','edit-request':'Редактирование запроса','edit-position':'Редактирование позиции','schedule-interview':'Назначить интервью','complete-interview':'Итоги интервью','schedule-connection':'Назначить подключение','close-failure':'Закрыть с неудачей' })[dialog]||''" width="520px" :z-index="1100" @close="closeDialog"><form class="entity-form" @submit.prevent="({ 'create-position':createPosition,'create-attempt':createAttempt,'edit-request':saveRequest,'edit-position':savePosition,'schedule-interview':scheduleInterview,'complete-interview':completeInterview,'schedule-connection':scheduleConnection,'close-failure':closeFailure })[dialog]?.()"><div v-if="error" class="error-banner">{{error}}</div>
    <template v-if="dialog==='edit-request'"><label>Название<input v-model="form.title" required></label><label>Ответственный<UiSearchSelect v-model="form.responsible_employee_id" :options="employeeOptions" :clearable="false"/></label><label>Дата запроса<input v-model="form.request_date" type="date" required></label><label>Время жизни<UiSearchSelect v-model="form.lifetime_weeks" :options="[1,2,3,4].map(value=>({value:String(value),label:`${value} ${value===1?'неделя':'недели'}`}))" :clearable="false"/></label><label>Статус<select v-model="form.status"><option v-for="status in requestStatuses" :key="status">{{status}}</option></select></label><label>Описание<textarea v-model="form.description"/></label></template>
    <template v-if="['create-position','edit-position'].includes(dialog)"><label>Технология<UiSearchSelect v-model="form.technology" :options="technologyOptions" placeholder="Не выбрано" search-placeholder="Поиск технологии" :clearable="false"/></label><label>Направление<UiSearchSelect v-model="form.direction" :options="directionOptions" placeholder="Не выбрано" search-placeholder="Поиск направления" :clearable="false"/></label><label>Уровень<UiSearchSelect v-model="form.level" :options="levelOptions.map(value=>({value,label:value}))" placeholder="Не выбрано" search-placeholder="Поиск уровня" :clearable="false"/></label><label>Количество<input v-model="form.quantity" type="number" min="1" required></label><label v-if="dialog==='edit-position'">Статус<select v-model="form.status"><option v-for="status in positionStatuses" :key="status">{{status}}</option></select></label><label>Описание<textarea v-model="form.description"/></label></template>
    <template v-if="dialog==='create-attempt'"><label>Специалист<UiSearchSelect v-model="form.specialist_id" :options="employeeOptions" :clearable="false"/></label><label>Ответственный<UiSearchSelect v-model="form.responsible_employee_id" :options="employeeOptions"/></label><label>CV<input type="file" accept=".pdf,.doc,.docx" required @change="form.cv=$event.target.files[0]"></label><label>Контрольная дата<input v-model="form.control_date" type="date"></label><label>Предлагаемая ставка<input v-model="form.proposed_rate" type="number" min="0"></label><label>Комментарий<textarea v-model="form.description"/></label></template>
    <template v-if="dialog==='schedule-interview'"><label>Дата и время интервью<input v-model="form.scheduled_at" type="datetime-local" required></label></template>
    <template v-if="dialog==='complete-interview'"><label>Оценка<select v-model="form.rating" required><option v-for="rating in ratings" :key="rating">{{rating}}</option></select></label><label>Фидбек<textarea v-model="form.feedback" maxlength="1000" required/></label></template>
    <template v-if="dialog==='schedule-connection'"><label>Дата подключения<input v-model="form.connection_date" type="date" required></label></template>
    <template v-if="dialog==='close-failure'"><fieldset class="reason-list"><legend>Причины</legend><label v-for="reason in availableFailureReasons" :key="reason"><input v-model="form.reasons" type="checkbox" :value="reason">{{reason}}</label></fieldset></template>
    <div class="form-actions"><UiButton type="submit" :disabled="busy">{{busy?'Сохраняю…':'Сохранить'}}</UiButton><UiButton type="button" variant="secondary" @click="closeDialog">Отмена</UiButton></div>
  </form></UiDrawer>
</template>

<style scoped>
.workflow-head{background:#f2f3f4;color:#59616b;font-size:12px;font-weight:600}.request-grid{display:grid;grid-template-columns:minmax(0,1fr) 180px;align-items:center}.request-grid>div{min-width:0;padding:6px 10px;border-bottom:1px solid #edf0f2}.workflow-request{background:transparent;font-size:13px}.workflow-position{position:relative;margin-left:32px;background:transparent;font-size:12px}.workflow-title{position:relative;display:flex;align-items:center;gap:6px;min-height:22px}.workflow-title--position{padding-left:32px!important}.workflow-position::before,.workflow-attempts::before{content:"";position:absolute;left:11px;top:-1px;bottom:0;border-left:1px dashed #c8cdd2}.workflow-title--position::after,.attempt-title::after{content:"";position:absolute;left:11px;top:50%;width:20px;border-top:1px dashed #c8cdd2}.client-toggle{position:relative;z-index:1;width:22px;min-width:22px;height:22px;padding:0;display:grid;place-items:center;border:0;border-radius:6px;background:#fff;cursor:pointer}.client-toggle:hover{background:#e8ecee}.client-toggle span{width:7px;height:7px;border-right:1.5px solid #7d858f;border-bottom:1.5px solid #7d858f;transform:rotate(-45deg);transition:transform .14s ease}.client-toggle.open span{transform:rotate(45deg)}.tree-branch-toggle{position:absolute;left:0;top:50%;transform:translateY(-50%)}.tree-toggle-placeholder{width:22px;min-width:22px}.tree-leaf-marker{position:absolute;z-index:1;left:8.5px;top:50%;width:5px;height:5px;border-radius:50%;background:#aeb5bc;transform:translateY(-50%)}.attempt-title .tree-leaf-marker{left:9px}.entity-link,.text-action{border:0;background:transparent;padding:0;cursor:pointer;font:inherit}.entity-link{font-weight:600;color:#20262d;text-align:left}.entity-link:hover,.text-action:hover{color:#078d6c}.text-action{color:#078d6c;font-size:11px;white-space:nowrap}.request-client,.position-quantity{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#8a929b;font-size:11px}.position-quantity{overflow:visible}.workflow-status{display:flex;justify-content:flex-end}.workflow-status :deep(.irlix-badge){width:160px;justify-content:center;white-space:nowrap}.workflow-attempts{position:relative;margin-left:64px;padding:0 0 3px}.workflow-attempt{width:100%;border:0;background:transparent;text-align:left;cursor:pointer}.workflow-attempt:hover{background:#fafcfc}.workflow-attempt>span{min-width:0;padding:5px 10px;border-bottom:1px solid #f0f1f2}.attempt-title{position:relative;padding-left:32px!important;color:#20262d;font-size:12px}.attempt-title::after{width:20px}.request-list-table th:last-child,.request-list-table td:last-child{width:180px}.request-list-title{display:flex;align-items:center;gap:6px}.attempt-kanban{display:grid;grid-template-columns:repeat(7,minmax(210px,1fr));gap:10px;overflow:auto;padding:12px 16px}.kanban-column{min-height:520px;padding:10px;background:#f5f6f7}.kanban-column h3{display:flex;justify-content:space-between;margin:0 0 10px;font-size:12px}.kanban-column h3 span{color:#89919a}.attempt-card{width:100%;display:grid;gap:5px;margin-bottom:8px;padding:10px;border:0;background:#fff;box-shadow:0 1px 3px rgba(20,30,40,.09);text-align:left;cursor:pointer}.attempt-card span,.attempt-card small{font-size:10px;color:#6d7680}.detail-card{display:grid;gap:18px}.detail-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.detail-summary span{display:grid;gap:4px;font-size:12px}.detail-summary b{color:#737c86;font-size:10px}.detail-card section{padding-top:16px;border-top:1px solid #eceef0}.detail-card h3{margin:0 0 10px;font-size:14px}.detail-card p{white-space:pre-wrap;line-height:1.55}.section-line{display:flex;align-items:center;justify-content:space-between}.detail-row{width:100%;display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:12px;padding:10px 0;border:0;border-bottom:1px solid #edf0f2;background:transparent;text-align:left;cursor:pointer}.interview-row{display:grid;gap:7px;padding:10px 0;border-bottom:1px solid #edf0f2;font-size:12px}.attempt-actions{display:flex;flex-wrap:wrap;gap:8px;padding-top:12px}.reason-list{display:grid;gap:9px;border:0;padding:0}.reason-list label{display:flex!important;grid-template-columns:auto 1fr!important;align-items:start;gap:8px!important;font-weight:400!important}.reason-list input{width:auto!important;min-height:auto!important;margin-top:2px}@media(max-width:720px){.request-grid{grid-template-columns:minmax(0,1fr) 145px}.workflow-status :deep(.irlix-badge){width:132px}.attempt-kanban{padding-inline:10px}.detail-summary{grid-template-columns:1fr}}
.workflow-status :deep(.ui-badge){width:160px;justify-content:center;white-space:nowrap}
@media(max-width:720px){.workflow-status :deep(.ui-badge){width:132px}}
</style>
