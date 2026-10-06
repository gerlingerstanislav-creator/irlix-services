<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { UiAppSidebar, UiAppTopbar } from '@irlix/ui';
import { auth } from './auth';
import { recruitmentApi } from './api';

const navItems = [
  { id: 'dashboard', label: 'Обзор', icon: 'dashboard' },
  { id: 'requests', label: 'Заявки', icon: 'briefcase' },
  { id: 'candidates', label: 'Кандидаты', icon: 'users' },
  { id: 'hiring', label: 'Найм', icon: 'tasks' },
  { id: 'interviews', label: 'Интервью', icon: 'assessment' },
  { id: 'offers', label: 'Офферы', icon: 'document' },
  { id: 'talent-pools', label: 'Talent Pools', icon: 'users' },
  { id: 'employment', label: 'Трудоустройство', icon: 'briefcase' },
  { id: 'onboarding', label: 'Онбординг', icon: 'dashboard' },
  { id: 'tasks', label: 'Задачи', icon: 'tasks' },
  { id: 'analytics', label: 'Аналитика', icon: 'assessment' },
];

const stageDefinitions = [
  ['new', 'Новый'], ['outreach', 'Поиск контакта'], ['contact', 'Контакт'], ['hr_interview', 'HR интервью'],
  ['manager_interview', 'HR + руководитель'], ['decision', 'Решение'], ['offer', 'Оффер'], ['accepted', 'Оффер принят'],
];
const employmentStages = [['request','Заявка'],['review','Проверка'],['approval','Согласование'],['documents','Документы'],['ready','Готов к выходу'],['employed','Трудоустроен']];
const offerStatuses = [['draft','Подготовка'],['approval','Согласование'],['ready','Готов к отправке'],['sent','Отправлен'],['accepted','Принят'],['rejected','Отклонён'],['withdrawn','Отозван']];

const loading = ref(true);
const error = ref('');
const workspace = reactive({ candidates: [], requests: [], pools: [], employment: [], onboarding: [], tasks: [], interviews: [], offers: [], funnel: {} });
const selectedCandidate = ref(null);
const currentPath = ref(window.location.pathname);
const modal = ref(null);

const candidateForm = reactive({ full_name:'', role:'', grade:'', city:'', salary_expectation:'', source:'', recruiter_name:'', email:'', phone:'', stack:'' });
const requestForm = reactive({ title:'', department_name:'', manager_name:'', recruiter_name:'', positions_count:1, priority:'medium', description:'' });
const poolForm = reactive({ name:'', description:'' });
const taskForm = reactive({ title:'', assignee:'', entity_type:'', entity_id:'', due_at:'' });

const normalize = () => currentPath.value.replace(/^\/recruitment\/?/, '').replace(/\/$/, '');
const route = computed(() => {
  const path = normalize();
  if (!path) return { section: 'dashboard' };
  const detail = path.match(/^candidates\/(\d+)$/);
  if (detail) return { section: 'candidate', id: Number(detail[1]) };
  return { section: navItems.some((item) => item.id === path) ? path : 'dashboard' };
});
const sidebarSection = computed(() => route.value.section === 'candidate' ? 'candidates' : route.value.section);
const processes = computed(() => workspace.candidates.map(c => c.hiring_process ? ({...c.hiring_process, candidate:c}) : null).filter(Boolean));
const activeOffers = computed(() => workspace.offers.filter(o => !['accepted','rejected','withdrawn'].includes(o.status)));
const dashboardStats = computed(() => [
  ['Активные заявки', workspace.requests.filter(r => !['closed','cancelled'].includes(r.status)).length],
  ['Кандидаты в работе', processes.value.filter(p => !['rejected','hired'].includes(p.stage_type)).length],
  ['Интервью', workspace.interviews.filter(i => i.status === 'scheduled').length],
  ['Активные офферы', activeOffers.value.length],
]);

function navigate(path='') {
  const target = path ? `/recruitment/${path}` : '/recruitment/';
  if (window.location.pathname !== target) window.history.pushState({}, '', target);
  currentPath.value = window.location.pathname;
  syncCandidate();
}
function syncCandidate() {
  if (route.value.section === 'candidate') selectedCandidate.value = workspace.candidates.find(c => Number(c.id) === route.value.id) || null;
}
function initials(name='') { return name.split(' ').filter(Boolean).slice(0,2).map(v => v[0]).join('').toUpperCase(); }
function fmtDate(value) { if (!value) return '—'; const d = new Date(value); return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('ru-RU'); }
function fmtDateTime(value) { if (!value) return '—'; const d = new Date(value); return Number.isNaN(d.getTime()) ? value : d.toLocaleString('ru-RU'); }
function stageLabel(type) { return stageDefinitions.find(([id]) => id === type)?.[1] || type || '—'; }
function employmentLabel(type) { return employmentStages.find(([id]) => id === type)?.[1] || type || '—'; }
function offerLabel(type) { return offerStatuses.find(([id]) => id === type)?.[1] || type || '—'; }

async function reload() {
  loading.value = true; error.value = '';
  try {
    const data = await recruitmentApi.workspace();
    Object.assign(workspace, data);
    workspace.interviews ||= []; workspace.offers ||= []; workspace.tasks ||= [];
    syncCandidate();
  } catch (e) { error.value = e.message || 'Не удалось загрузить Recruitment'; }
  finally { loading.value = false; }
}

async function createCandidate() {
  await recruitmentApi.createCandidate({ ...candidateForm, stack: candidateForm.stack.split(',').map(v=>v.trim()).filter(Boolean) });
  Object.keys(candidateForm).forEach(k => candidateForm[k] = k === 'stack' ? '' : '');
  modal.value = null; await reload();
}
async function createRequest() {
  await recruitmentApi.createRequest({ ...requestForm, positions_count:Number(requestForm.positions_count) });
  modal.value = null; await reload();
}
async function createPool() { await recruitmentApi.createPool(poolForm); poolForm.name=''; poolForm.description=''; modal.value=null; await reload(); }
async function createTask() { await recruitmentApi.createTask({ ...taskForm, entity_id: taskForm.entity_id ? Number(taskForm.entity_id) : null, due_at: taskForm.due_at || null }); modal.value=null; await reload(); }
async function moveStage(process, stage_type, stage_label) { await recruitmentApi.moveStage(process.id, { stage_type, stage_label }); await reload(); }
async function completeTask(task) { await recruitmentApi.completeTask(task.id); await reload(); }
async function advanceEmployment(item) {
  const index = employmentStages.findIndex(([id]) => id === item.stage); if (index < 0 || index === employmentStages.length - 1) return;
  await recruitmentApi.updateEmployment(item.id, { stage: employmentStages[index+1][0] }); await reload();
}
async function advanceOffer(item) {
  const index = offerStatuses.findIndex(([id]) => id === item.status); if (index < 0 || index === offerStatuses.length - 1) return;
  await recruitmentApi.updateOffer(item.id, { status: offerStatuses[index+1][0] }); await reload();
}
async function addCandidateToPool(pool) {
  const raw = window.prompt('ID кандидата для добавления в Talent Pool'); if (!raw) return;
  await recruitmentApi.addToPool(pool.id, Number(raw)); await reload();
}
async function scheduleInterview(process) {
  const when = window.prompt('Дата и время интервью, например 2026-10-01 14:00'); if (!when) return;
  const type = window.prompt('Тип интервью', process.stage_type === 'manager_interview' ? 'manager' : 'hr') || 'hr';
  await recruitmentApi.createInterview({ hiring_process_id:process.id, type, scheduled_at:when, participants:'' }); await reload();
}
async function createOffer(process) {
  await recruitmentApi.createOffer({ hiring_process_id:process.id, role:process.candidate.role || 'Специалист', compensation:process.candidate.salary_expectation || '', planned_start_date:null }); await reload();
}
async function startEmployment(process) {
  const c = process.candidate;
  const department_name = window.prompt('Направление / подразделение', '') || 'Не указано';
  const manager_name = window.prompt('Руководитель', '') || 'Не указан';
  const planned_start_date = window.prompt('Плановая дата выхода YYYY-MM-DD'); if (!planned_start_date) return;
  await recruitmentApi.createEmployment({ candidate_id:c.id, hiring_process_id:process.id, role:c.role || 'Специалист', department_name, manager_name, planned_start_date }); await reload();
}

const onPopState = () => { currentPath.value = window.location.pathname; syncCandidate(); };
onMounted(() => { window.addEventListener('popstate', onPopState); reload(); });
onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));
</script>

<template>
  <div class="recruitment-app">
    <UiAppSidebar :section="sidebarSection" :items="navItems" current-service="recruitment" :current-user="auth.user" :platform-access="() => auth.fetch('/api/employees/access/me')" @update:section="id => navigate(id === 'dashboard' ? '' : id)" @logout="auth.logout()" />
    <main class="irlix-service-workspace">
      <UiAppTopbar service="recruitment" :section="sidebarSection" :items="navItems" :loading="loading" />
      <div class="content irlix-service-content">
      <div v-if="loading" class="panel">Загружаем Recruitment…</div>
      <div v-else-if="error" class="panel"><h2>Не удалось загрузить данные</h2><p>{{ error }}</p><button class="primary" @click="reload">Повторить</button></div>

      <template v-else-if="route.section === 'dashboard'">
        <header class="page-head"><div><div class="eyebrow">Recruitment</div><h1>Обзор найма</h1><p>Заявки, кандидаты, интервью, офферы и подготовка выхода.</p></div><button class="primary" @click="modal='request'">+ Новая заявка</button></header>
        <section class="metric-grid"><article v-for="[label,value] in dashboardStats" :key="label" class="metric"><span>{{ label }}</span><strong>{{ value }}</strong><small>Актуально сейчас</small></article></section>
        <section class="panel"><div class="panel-head"><div><h2>Воронка</h2><p>Нормализованные этапы HiringProcess</p></div><button class="ghost" @click="navigate('analytics')">Аналитика →</button></div><div class="funnel"><div v-for="[id,label] in stageDefinitions" :key="id" class="funnel-step"><strong>{{ workspace.funnel[id] || 0 }}</strong><span>{{ label }}</span></div></div></section>
        <section class="dashboard-grid"><article class="panel"><h2>Ближайшие интервью</h2><div class="timeline"><div v-for="item in workspace.interviews.slice(0,5)" :key="item.id"><time>{{ fmtDateTime(item.scheduled_at) }}</time><b>{{ item.candidate_name || 'Кандидат' }}</b><span>{{ item.type }}</span></div><p v-if="!workspace.interviews.length">Интервью пока нет.</p></div></article><article class="panel"><h2>Задачи</h2><div class="task-list"><label v-for="task in workspace.tasks.slice(0,5)" :key="task.id"><input type="checkbox" @change="completeTask(task)"> {{ task.title }}</label><p v-if="!workspace.tasks.length">Открытых задач нет.</p></div></article></section>
      </template>

      <template v-else-if="route.section === 'requests'">
        <header class="page-head"><div><div class="eyebrow">Потребность бизнеса</div><h1>Заявки на подбор</h1><p>Доступны Recruitment и руководителям направлений в рамках scope.</p></div><button class="primary" @click="modal='request'">+ Создать заявку</button></header>
        <section class="table-card"><table><thead><tr><th>Заявка</th><th>Направление</th><th>Руководитель</th><th>Recruiter</th><th>Кандидаты</th><th>Статус</th></tr></thead><tbody><tr v-for="item in workspace.requests" :key="item.id"><td><b>{{ item.title }}</b><small>{{ item.positions_count }} поз. · {{ item.priority }}</small></td><td>{{ item.department_name }}</td><td>{{ item.manager_name }}</td><td>{{ item.recruiter_name || '—' }}</td><td>{{ item.candidates_count }}</td><td><span class="status">{{ item.status }}</span></td></tr></tbody></table></section>
      </template>

      <template v-else-if="route.section === 'candidates'">
        <header class="page-head"><div><div class="eyebrow">Recruiting CRM</div><h1>Кандидаты</h1><p>Единая база людей независимо от количества попыток найма.</p></div><button class="primary" @click="modal='candidate'">+ Кандидат</button></header>
        <section class="table-card"><table><thead><tr><th>Кандидат</th><th>Стек</th><th>Локация</th><th>Этап</th><th>Recruiter</th></tr></thead><tbody><tr v-for="c in workspace.candidates" :key="c.id" @click="navigate(`candidates/${c.id}`)"><td><div class="person-cell"><span class="avatar">{{ initials(c.full_name) }}</span><span><b>{{ c.full_name }}</b><small>{{ c.role || 'Роль не указана' }} · {{ c.grade || '—' }}</small></span></div></td><td><div class="chips"><span v-for="skill in c.stack" :key="skill">{{ skill }}</span></div></td><td>{{ c.city || '—' }}</td><td><span class="status">{{ c.hiring_process?.stage_label || 'В базе' }}</span></td><td>{{ c.recruiter_name || '—' }}</td></tr></tbody></table></section>
      </template>

      <template v-else-if="route.section === 'candidate'">
        <button class="back" @click="navigate('candidates')">← К кандидатам</button>
        <template v-if="selectedCandidate"><section class="profile-head"><span class="avatar xl">{{ initials(selectedCandidate.full_name) }}</span><div><h1>{{ selectedCandidate.full_name }}</h1><p>{{ selectedCandidate.role }} · {{ selectedCandidate.grade }}</p></div><span class="stage-pill">{{ selectedCandidate.hiring_process?.stage_label || 'В базе' }}</span></section><section class="profile-grid"><article class="panel"><h2>Профиль</h2><div class="details"><div><span>Email</span><b>{{ selectedCandidate.email || '—' }}</b></div><div><span>Телефон</span><b>{{ selectedCandidate.phone || '—' }}</b></div><div><span>Локация</span><b>{{ selectedCandidate.city || '—' }}</b></div><div><span>Ожидания</span><b>{{ selectedCandidate.salary_expectation || '—' }}</b></div></div><h3>Стек</h3><div class="chips large"><span v-for="skill in selectedCandidate.stack" :key="skill">{{ skill }}</span></div></article><article class="panel"><h2>Talent Pools</h2><div class="pool-box" v-for="pool in selectedCandidate.pools" :key="pool">{{ pool }}</div><p v-if="!selectedCandidate.pools?.length">Кандидат пока не состоит в пулах.</p></article></section></template>
      </template>

      <template v-else-if="route.section === 'hiring'">
        <header class="page-head"><div><div class="eyebrow">Hiring pipeline</div><h1>Найм</h1><p>Движение конкретных попыток найма по нормализованным этапам.</p></div></header>
        <section class="kanban"><article v-for="[id,label] in stageDefinitions" :key="id" class="kanban-col"><header><b>{{ label }}</b><span>{{ processes.filter(p=>p.stage_type===id).length }}</span></header><button v-for="p in processes.filter(p=>p.stage_type===id)" :key="p.id" class="candidate-card" @click="navigate(`candidates/${p.candidate.id}`)"><b>{{ p.candidate.full_name }}</b><span>{{ p.candidate.role }}</span><small>{{ p.recruiter_name || p.candidate.recruiter_name || '—' }}</small></button></article></section>
        <section class="panel"><h2>Действия с процессами</h2><div class="task-list"><label v-for="p in processes" :key="p.id"><b>{{ p.candidate.full_name }}</b> · {{ p.stage_label }} <button class="ghost" @click="scheduleInterview(p)">Интервью</button> <button class="ghost" @click="createOffer(p)">Оффер</button> <button class="ghost" @click="startEmployment(p)">Трудоустройство</button><select @change="e=>moveStage(p,e.target.value,stageLabel(e.target.value))"><option value="">Перевести…</option><option v-for="[id,label] in stageDefinitions" :key="id" :value="id">{{ label }}</option></select></label></div></section>
      </template>

      <template v-else-if="route.section === 'interviews'">
        <header class="page-head"><div><div class="eyebrow">Календарь и feedback</div><h1>Интервью</h1><p>HR, руководители и дополнительные оценки.</p></div></header><section class="table-card"><table><thead><tr><th>Кандидат</th><th>Тип</th><th>Дата</th><th>Участники</th><th>Статус</th></tr></thead><tbody><tr v-for="i in workspace.interviews" :key="i.id"><td>{{ i.candidate_name }}</td><td>{{ i.type }}</td><td>{{ fmtDateTime(i.scheduled_at) }}</td><td>{{ i.participants || '—' }}</td><td><span class="status">{{ i.status }}</span></td></tr></tbody></table></section>
      </template>

      <template v-else-if="route.section === 'offers'">
        <header class="page-head"><div><div class="eyebrow">Offer workflow</div><h1>Офферы</h1><p>Подготовка, согласование, отправка и решение кандидата.</p></div></header><section class="offer-grid"><article v-for="o in workspace.offers" :key="o.id" class="panel offer"><h2>{{ o.candidate_name || o.role }}</h2><span class="status">{{ offerLabel(o.status) }}</span><dl><div><dt>Роль</dt><dd>{{ o.role }}</dd></div><div><dt>Условия</dt><dd>{{ o.compensation || '—' }}</dd></div><div><dt>Выход</dt><dd>{{ fmtDate(o.planned_start_date) }}</dd></div></dl><button class="primary full" @click="advanceOffer(o)">Следующий этап</button></article></section>
      </template>

      <template v-else-if="route.section === 'talent-pools'">
        <header class="page-head"><div><div class="eyebrow">Recruiting CRM</div><h1>Talent Pools</h1><p>Пулы кандидатов для повторной работы с базой.</p></div><button class="primary" @click="modal='pool'">+ Создать пул</button></header><section class="pool-grid"><article v-for="p in workspace.pools" :key="p.id" class="panel pool-card"><span class="pool-count">{{ p.count }}</span><h2>{{ p.name }}</h2><p>{{ p.description }}</p><button class="ghost" @click="addCandidateToPool(p)">+ Кандидат</button></article></section>
      </template>

      <template v-else-if="route.section === 'employment'">
        <header class="page-head"><div><div class="eyebrow">После принятого оффера</div><h1>Трудоустройство</h1><p>Отдельный workflow до создания Employee.</p></div></header><div class="workflow"><template v-for="([id,label],index) in employmentStages" :key="id"><span>{{ label }}</span><i v-if="index<employmentStages.length-1">→</i></template></div><section class="table-card"><table><thead><tr><th>Кандидат</th><th>Роль</th><th>Руководитель</th><th>Дата выхода</th><th>Этап</th><th></th></tr></thead><tbody><tr v-for="e in workspace.employment" :key="e.id"><td>{{ e.candidate_name || `#${e.candidate_id}` }}</td><td>{{ e.role }}</td><td>{{ e.manager_name }}</td><td>{{ fmtDate(e.planned_start_date) }}</td><td><span class="status">{{ employmentLabel(e.stage) }}</span></td><td><button class="ghost" @click.stop="advanceEmployment(e)">Следующий этап</button></td></tr></tbody></table></section>
      </template>

      <template v-else-if="route.section === 'onboarding'">
        <header class="page-head"><div><div class="eyebrow">Подготовка выхода</div><h1>Онбординг</h1><p>Экран доступен HR и руководителям направлений в пределах scope.</p></div></header><section class="onboarding-grid"><article v-for="o in workspace.onboarding" :key="o.id" class="panel"><h2>{{ o.employee_name }}</h2><p>{{ o.role }} · {{ o.manager_name }}</p><div class="progress"><i :style="{width:`${o.total_steps ? Math.round(o.completed_steps/o.total_steps*100) : 0}%`}"></i></div><b>{{ o.completed_steps }} / {{ o.total_steps }} шагов</b><p>Следующее: {{ o.next_action || '—' }}</p><small>Выход: {{ fmtDate(o.planned_start_date) }}</small></article></section>
      </template>

      <template v-else-if="route.section === 'tasks'">
        <header class="page-head"><div><div class="eyebrow">Рабочая очередь</div><h1>Задачи</h1><p>Действия с привязкой к объектам Recruitment.</p></div><button class="primary" @click="modal='task'">+ Задача</button></header><section class="panel"><div class="task-list"><label v-for="t in workspace.tasks" :key="t.id"><input type="checkbox" @change="completeTask(t)"> <b>{{ t.title }}</b> · {{ t.assignee || 'без исполнителя' }} · {{ fmtDateTime(t.due_at) }}</label><p v-if="!workspace.tasks.length">Открытых задач нет.</p></div></section>
      </template>

      <template v-else-if="route.section === 'analytics'">
        <header class="page-head"><div><div class="eyebrow">Recruitment analytics</div><h1>Аналитика</h1><p>Воронка и конверсия по текущим данным Recruitment.</p></div></header><section class="panel"><div class="bars"><div v-for="[id,label] in stageDefinitions" :key="id"><span>{{ label }}</span><i :style="{width:`${Math.min(100,(workspace.funnel[id]||0)*8)}%`}"></i><b>{{ workspace.funnel[id] || 0 }}</b></div></div></section>
      </template>
      </div>
    </main>

    <div v-if="modal" class="modal-backdrop" @click.self="modal=null"><form v-if="modal==='candidate'" class="modal-card" @submit.prevent="createCandidate"><h2>Новый кандидат</h2><input v-model="candidateForm.full_name" required placeholder="ФИО"><input v-model="candidateForm.role" placeholder="Роль"><input v-model="candidateForm.grade" placeholder="Grade"><input v-model="candidateForm.city" placeholder="Город"><input v-model="candidateForm.salary_expectation" placeholder="Ожидания"><input v-model="candidateForm.source" placeholder="Источник"><input v-model="candidateForm.recruiter_name" placeholder="Recruiter"><input v-model="candidateForm.email" type="email" placeholder="Email"><input v-model="candidateForm.phone" placeholder="Телефон"><input v-model="candidateForm.stack" placeholder="Стек через запятую"><div><button class="ghost" type="button" @click="modal=null">Отмена</button><button class="primary">Создать</button></div></form><form v-else-if="modal==='request'" class="modal-card" @submit.prevent="createRequest"><h2>Новая заявка</h2><input v-model="requestForm.title" required placeholder="Позиция"><input v-model="requestForm.department_name" required placeholder="Направление"><input v-model="requestForm.manager_name" required placeholder="Руководитель"><input v-model="requestForm.recruiter_name" placeholder="Recruiter"><input v-model.number="requestForm.positions_count" type="number" min="1"><select v-model="requestForm.priority"><option value="low">Низкий</option><option value="medium">Средний</option><option value="high">Высокий</option></select><textarea v-model="requestForm.description" placeholder="Описание"></textarea><div><button class="ghost" type="button" @click="modal=null">Отмена</button><button class="primary">Создать</button></div></form><form v-else-if="modal==='pool'" class="modal-card" @submit.prevent="createPool"><h2>Новый Talent Pool</h2><input v-model="poolForm.name" required placeholder="Название"><textarea v-model="poolForm.description" placeholder="Описание"></textarea><div><button class="ghost" type="button" @click="modal=null">Отмена</button><button class="primary">Создать</button></div></form><form v-else-if="modal==='task'" class="modal-card" @submit.prevent="createTask"><h2>Новая задача</h2><input v-model="taskForm.title" required placeholder="Что сделать"><input v-model="taskForm.assignee" placeholder="Исполнитель"><input v-model="taskForm.entity_type" placeholder="Тип объекта"><input v-model="taskForm.entity_id" type="number" placeholder="ID объекта"><input v-model="taskForm.due_at" type="datetime-local"><div><button class="ghost" type="button" @click="modal=null">Отмена</button><button class="primary">Создать</button></div></form></div>
  </div>
</template>

<style scoped>
.modal-backdrop{position:fixed;inset:0;background:rgba(20,22,28,.42);display:grid;place-items:center;z-index:1000;padding:24px}.modal-card{width:min(520px,100%);max-height:90vh;overflow:auto;background:white;border-radius:18px;padding:24px;display:grid;gap:12px;box-shadow:0 24px 70px rgba(0,0,0,.22)}.modal-card h2{margin:0 0 4px}.modal-card input,.modal-card select,.modal-card textarea{border:1px solid #dfe2e7;border-radius:10px;padding:11px 12px;font:inherit}.modal-card textarea{min-height:100px;resize:vertical}.modal-card>div{display:flex;justify-content:flex-end;gap:10px}.task-list label button,.task-list label select{margin-left:8px}.task-list label{display:block}.table-card button{white-space:nowrap}
</style>
