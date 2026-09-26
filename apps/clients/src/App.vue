<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiFilterBar, UiTabs, UiViewSwitch } from '@irlix/ui';

const view = ref('clients');
const query = ref('');
const loading = ref(true);
const error = ref('');
const employees = ref([]);
const clients = ref([]);
const leads = ref([]);
const contacts = ref([]);
const requests = ref([]);
const reports = ref([]);
const selectedClientId = ref(null);
const selectedMember = ref(null);
const selectedLead = ref(null);
const reportMode = ref('kanban');
const requestMode = ref('tree');
const expandedClients = reactive(new Set());
const expandedRequests = reactive(new Set());
const expandedPositions = reactive(new Set());
const formKind = ref('');
const formError = ref('');
const saving = ref(false);
const form = reactive({});
const cashMonth = ref(new Date().toISOString().slice(0, 7));
const absences = ref([]);

const nav = [
  ['clients','Клиенты','▣'],['leads','Лиды','♛'],['contacts','Контакты','◉'],
  ['requests','Запросы','◎'],['positions','Позиции','≡'],['attempts','Попытки','↗'],
  ['members','Участники','♙'],['cashflow','ДДС','◫'],['reports','Отчётные периоды','▦'],
];
const leadStatuses = ['Новый лид','Первичный контакт','Уточнение потребностей','КП отправлено','Активные переговоры','Клиент в игноре','Сделка закрыта - Успех','Сделка закрыта - Отказ'];
const attemptStatuses = ['Новая','CV отправлено','Интервью','Ожидает подключения','Закрыта: неудача'];
const reportStages = ['Новый','ТШ на согласовании','ТШ согласованы','Акт на согласовании','Акт согласован','Счет оплачен'];
const title = computed(() => ({clients:'Клиенты',leads:'Лиды',contacts:'Контактные лица',requests:'Запросы',positions:'Позиции',attempts:'Попытки подключения',members:'Участники проектов',cashflow:'ДДС',reports:'Отчётные периоды'}[view.value]));
const employeeMap = computed(() => new Map(employees.value.map(e => [Number(e.id), e.full_name || [e.last_name,e.first_name,e.middle_name].filter(Boolean).join(' ')])));
const employeeName = id => employeeMap.value.get(Number(id)) || (id ? `#${id}` : '—');
const selectedClient = computed(() => clients.value.find(c => c.id === selectedClientId.value) || null);
const money = value => `${new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 2}).format(Number(value || 0))} ₽`;
const dateRu = value => value ? new Date(`${String(value).slice(0,10)}T00:00:00`).toLocaleDateString('ru-RU') : '...';
const toIso = value => String(value || '').slice(0,10);
const latestTerms = member => [...(member.terms || [])].sort((a,b) => String(b.valid_from).localeCompare(String(a.valid_from)))[0] || null;
const memberStatus = member => {
  const today = new Date().toISOString().slice(0,10);
  return (member.terms || []).some(t => toIso(t.valid_from) <= today && (!t.valid_to || toIso(t.valid_to) >= today)) ? 'На проекте' : 'Работал ранее';
};

function normalizeOverview(payload) {
  const rawClients = payload.clients || [];
  clients.value = rawClients.map(c => ({
    ...c,
    id:Number(c.id),
    account:employeeName(c.account_employee_id),
    sales:employeeName(c.sales_employee_id),
    projects:(c.projects || []).map(p => ({...p,id:Number(p.id),displayName:p.name || 'Основной проект',members:(p.members || []).map(m => {
      const terms = (m.terms || []).map(t => ({...t,id:Number(t.id)}));
      const current = latestTerms({terms});
      return {...m,id:Number(m.id),name:m.specialist_name,terms,technology:current?.technology || '—',level:current?.level || '—',rate:Number(current?.hourly_rate || 0),hours:Number(current?.hours_per_day || 0),from:current?.valid_from || null,to:current?.valid_to || null,status:memberStatus({terms})};
    })})),
  })).map(c => ({...c,technologies:[...new Set(c.projects.flatMap(p => p.members.flatMap(m => m.terms.map(t => t.technology))))]}));
  leads.value = (payload.leads || []).map(l => ({...l,id:Number(l.id),responsible:employeeName(l.responsible_employee_id),created:dateRu(l.created_at),updated:dateRu(l.updated_at),contacts:(payload.contacts || []).filter(c => (c.relations || []).some(r => r.entity_type === 'lead' && Number(r.entity_id) === Number(l.id) && r.active)).length}));
  contacts.value = (payload.contacts || []).map(c => ({...c,id:Number(c.id)}));
  const clientById = new Map(clients.value.map(c => [c.id,c]));
  requests.value = (payload.requests || []).map(r => ({...r,id:Number(r.id),clientId:Number(r.client_id),title:r.title,client:clientById.get(Number(r.client_id))?.name || `#${r.client_id}`,responsible:employeeName(r.responsible_employee_id),until:dateRu(r.deadline),positions:(r.positions || []).map(p => ({...p,id:Number(p.id),title:p.technology,department:p.direction || '—',attempts:(p.attempts || []).map(a => ({...a,id:Number(a.id),specialist:a.specialist_name,responsible:employeeName(a.responsible_employee_id)}))}))}));
  reports.value = (payload.reportingPeriods || []).map(r => ({...r,id:Number(r.id),clientId:Number(r.client_id),client:clientById.get(Number(r.client_id))?.name || `#${r.client_id}`,period:`${dateRu(r.period_start)} - ${dateRu(r.period_end)}`,stage:r.status}));
  if (!expandedClients.size) clients.value.slice(0,2).forEach(c => expandedClients.add(c.id));
}

async function api(url, options = {}) {
  const response = await fetch(url, {headers:{'Content-Type':'application/json','Accept':'application/json',...(options.headers || {})},...options});
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

async function loadAll() {
  loading.value = true; error.value = '';
  try {
    const [employeeBody, overviewBody] = await Promise.all([api('/api/employees/employees'), api('/api/clients/overview')]);
    employees.value = employeeBody.data || [];
    normalizeOverview(overviewBody.data || {});
    await loadAbsences();
  } catch (e) { error.value = e.message || String(e); }
  finally { loading.value = false; }
}

const filteredClients = computed(() => {
  const needle = query.value.trim().toLowerCase();
  return clients.value.filter(c => !needle || [c.name,c.type,c.sector,c.account,c.sales,...c.technologies].join(' ').toLowerCase().includes(needle));
});
const allMembers = computed(() => clients.value.flatMap(c => c.projects.flatMap(p => p.members.map(m => ({...m,client:c.name,clientId:c.id,project:p.displayName,projectId:p.id,responsible:c.sales}))));
const allPositions = computed(() => requests.value.flatMap(r => r.positions.map(p => ({...p,requestId:r.id,request:r.title,clientId:r.clientId,client:r.client,responsible:r.responsible,until:r.until}))));
const allAttempts = computed(() => requests.value.flatMap(r => r.positions.flatMap(p => p.attempts.map(a => ({...a,positionId:p.id,position:`${p.title} ${p.level}`,clientId:r.clientId,client:r.client,responsible:r.responsible,control:a.control_date ? dateRu(a.control_date) : r.until}))));

function monthRange() {
  const [year, month] = cashMonth.value.split('-').map(Number);
  const dayCount = new Date(year, month, 0).getDate();
  return {from:`${year}-${String(month).padStart(2,'0')}-01`,to:`${year}-${String(month).padStart(2,'0')}-${String(dayCount).padStart(2,'0')}`};
}
async function loadAbsences() {
  if (!allMembers.value.length) { absences.value = []; return; }
  const {from,to} = monthRange();
  const params = new URLSearchParams({from,to});
  [...new Set(allMembers.value.map(m => Number(m.specialist_id)).filter(Boolean))].forEach(id => params.append('employee_ids[]', String(id)));
  try { absences.value = (await api(`/api/vacations/calendar-absences?${params.toString()}`)).data || []; }
  catch (_) { absences.value = []; }
}
watch(cashMonth, loadAbsences);

const ruHolidays2026 = new Set(['2026-01-01','2026-01-02','2026-01-03','2026-01-04','2026-01-05','2026-01-06','2026-01-07','2026-01-08','2026-01-09','2026-01-10','2026-01-11','2026-02-23','2026-03-09','2026-05-01','2026-05-11','2026-06-12','2026-11-04']);
function eachDate(from,to) { const out=[]; const d=new Date(`${from}T00:00:00`); const end=new Date(`${to}T00:00:00`); while(d<=end){out.push(`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`);d.setDate(d.getDate()+1);} return out; }
function isWorkingDay(date) { const d=new Date(`${date}T00:00:00`); return d.getDay()!==0 && d.getDay()!==6 && !ruHolidays2026.has(date); }
function absentOn(employeeId,date) { return absences.value.some(a => Number(a.employee_id)===Number(employeeId) && toIso(a.starts_on)<=date && toIso(a.ends_on)>=date); }
const cashRows = computed(() => {
  const {from,to}=monthRange(); const rows=[];
  for (const member of allMembers.value) for (const term of member.terms || []) {
    const start=toIso(term.valid_from)>from?toIso(term.valid_from):from;
    const finish=!term.valid_to||toIso(term.valid_to)>to?to:toIso(term.valid_to);
    if(start>finish) continue;
    const days=eachDate(start,finish).filter(d=>isWorkingDay(d)&&!absentOn(member.specialist_id,d));
    const hours=days.length*Number(term.hours_per_day||0);
    rows.push({...member,term,start,finish,calendarHours:hours,calendarMoney:hours*Number(term.hourly_rate||0)});
  }
  return rows.sort((a,b)=>a.client.localeCompare(b.client)||a.project.localeCompare(b.project)||a.name.localeCompare(b.name)||a.start.localeCompare(b.start));
});

function toggle(set,id){ set.has(id)?set.delete(id):set.add(id); }
function leadTone(s){ return s?.includes('Отказ')?'danger':s?.includes('Успех')?'success':s?.includes('контакт')?'info':'neutral'; }
function attemptTone(s){ return s?.includes('неудача')?'danger':s?.includes('успех')?'success':'info'; }
function openForm(kind, initial={}) { formKind.value=kind; formError.value=''; Object.keys(form).forEach(k=>delete form[k]); Object.assign(form,initial); }
function closeForm(){ formKind.value=''; formError.value=''; }
function openMemberFromAttempt(attempt){
  const client=clients.value.find(c=>c.id===Number(attempt.clientId));
  const project=client?.projects?.[0];
  openForm('member',{client_id:client?.id,project_id:project?.id,specialist_id:attempt.specialist_id,source_attempt_id:attempt.id,technology:'',level:'',hourly_rate:Number(attempt.proposed_rate||0),hours_per_day:8});
}
const formTitle = computed(() => ({client:'Новый клиент',convertLead:'Создать клиента из лида',lead:'Новый лид',contact:'Новый контакт',project:'Новый проект',member:'Новое подключение',terms:'Новые условия',request:'Новый запрос',position:'Новая позиция',attempt:'Новая попытка',report:'Новый отчётный период'}[formKind.value] || '');

async function submitForm() {
  saving.value=true; formError.value='';
  try {
    if (formKind.value==='client') await api('/api/clients/clients',{method:'POST',body:JSON.stringify({name:form.name,type:form.type||null,sector:form.sector||null,sales_employee_id:Number(form.sales_employee_id)||null,account_employee_id:Number(form.account_employee_id)})});
    if (formKind.value==='convertLead') await api(`/api/clients/leads/${form.lead_id}/convert`,{method:'POST',body:JSON.stringify({name:form.name,type:form.type||null,sector:form.sector||null,account_employee_id:Number(form.account_employee_id)})});
    if (formKind.value==='lead') await api('/api/clients/leads',{method:'POST',body:JSON.stringify({name:form.name,source:form.source||null,responsible_employee_id:Number(form.responsible_employee_id),status:form.status||'Новый лид'})});
    if (formKind.value==='contact') await api('/api/clients/contacts',{method:'POST',body:JSON.stringify({full_name:form.full_name,position:form.position||null,phone:form.phone||null,email:form.email||null})});
    if (formKind.value==='project') await api(`/api/clients/clients/${form.client_id}/projects`,{method:'POST',body:JSON.stringify({name:form.name})});
    if (formKind.value==='member') {
      const emp=employees.value.find(e=>Number(e.id)===Number(form.specialist_id));
      await api(`/api/clients/projects/${form.project_id}/members`,{method:'POST',body:JSON.stringify({specialist_id:Number(form.specialist_id),specialist_name:emp?.full_name||`#${form.specialist_id}`,technology:form.technology,level:form.level,hourly_rate:Number(form.hourly_rate),hours_per_day:Number(form.hours_per_day),valid_from:form.valid_from,valid_to:form.valid_to||null,source_attempt_id:form.source_attempt_id?Number(form.source_attempt_id):null})});
    }
    if (formKind.value==='terms') await api(`/api/clients/members/${form.member_id}/terms`,{method:'POST',body:JSON.stringify({technology:form.technology,level:form.level,hourly_rate:Number(form.hourly_rate),hours_per_day:Number(form.hours_per_day),valid_from:form.valid_from,valid_to:form.valid_to||null})});
    if (formKind.value==='request') await api('/api/clients/requests',{method:'POST',body:JSON.stringify({client_id:Number(form.client_id),title:form.title,description:form.description||null,responsible_employee_id:Number(form.responsible_employee_id),deadline:form.deadline||null,status:'Новый'})});
    if (formKind.value==='position') await api(`/api/clients/requests/${form.client_request_id}/positions`,{method:'POST',body:JSON.stringify({direction:form.direction||null,technology:form.technology,level:form.level,quantity:Number(form.quantity||1),description:form.description||null})});
    if (formKind.value==='attempt') {
      const emp=employees.value.find(e=>Number(e.id)===Number(form.specialist_id));
      await api(`/api/clients/positions/${form.position_id}/attempts`,{method:'POST',body:JSON.stringify({specialist_id:Number(form.specialist_id),specialist_name:emp?.full_name||`#${form.specialist_id}`,responsible_employee_id:Number(form.responsible_employee_id)||null,control_date:form.control_date||null,proposed_rate:form.proposed_rate?Number(form.proposed_rate):null,status:'Новая'})});
    }
    if (formKind.value==='report') await api('/api/clients/reporting-periods',{method:'POST',body:JSON.stringify({client_id:Number(form.client_id),period_start:form.period_start,period_end:form.period_end,status:'Новый'})});
    closeForm(); await loadAll();
  } catch(e){ formError.value=e.message||String(e); }
  finally{ saving.value=false; }
}
async function changeLeadStatus(lead,status){ try{await api(`/api/clients/leads/${lead.id}`,{method:'PATCH',body:JSON.stringify({status})});await loadAll();}catch(e){error.value=e.message;} }
async function changeAttemptStatus(attempt,status){ try{await api(`/api/clients/attempts/${attempt.id}`,{method:'PATCH',body:JSON.stringify({status})});await loadAll();}catch(e){error.value=e.message;} }
async function changeReportStatus(report,status){ try{await api(`/api/clients/reporting-periods/${report.id}`,{method:'PATCH',body:JSON.stringify({status})});await loadAll();}catch(e){error.value=e.message;} }

onMounted(loadAll);
</script>

<template>
<div class="clients-app irlix-ui">
  <aside class="rail"><div class="brand">X</div><a class="home" href="/">⌂</a><div class="rail-chip">ГС</div><nav><button v-for="item in nav" :key="item[0]" :class="{active:view===item[0]}" :title="item[1]" @click="view=item[0];query=''">{{item[2]}}</button></nav></aside>
  <section class="workspace">
    <header class="topbar"><span class="crumb">▣ {{ title }}</span><span v-if="loading" class="loading-inline">Обновление…</span></header>
    <main class="content">
      <div v-if="error" class="error-banner">{{error}} <button @click="loadAll">Повторить</button></div>
      <div class="page-head"><div><h1>{{ title }}</h1><p v-if="view==='clients'">Список клиентов компании, проектов и участников</p><p v-else-if="view==='leads'">Клиенты, которые проявили интерес к услугам компании</p><p v-else-if="view==='reports'">Управление отчётными периодами</p></div><UiButton v-if="view==='clients'" @click="openForm('client')">＋ Новый клиент</UiButton><UiButton v-else-if="view==='leads'" @click="openForm('lead',{status:'Новый лид'})">＋ Новый лид</UiButton><UiButton v-else-if="view==='contacts'" @click="openForm('contact')">＋ Новый контакт</UiButton><UiButton v-else-if="view==='requests'" @click="openForm('request')">＋ Новый запрос</UiButton><UiButton v-else-if="view==='reports'" @click="openForm('report')">＋ Новый отчётный период</UiButton></div>

      <template v-if="view==='clients'">
        <UiFilterBar><input v-model="query" class="irlix-search" placeholder="Поиск по клиентам"><select><option>Аккаунты</option><option v-for="e in employees" :key="e.id">{{e.full_name}}</option></select><select><option>Сейлзы</option><option v-for="e in employees" :key="e.id">{{e.full_name}}</option></select><select><option>Технологии</option></select><select><option>Подразделения</option></select><select><option>Активность</option></select></UiFilterBar>
        <div class="count">{{ filteredClients.length }} клиентов</div>
        <div class="client-table"><div class="client-head client-grid"><div>Клиент</div><div>Тип</div><div>Сектор</div><div>Технологии</div><div>Участники</div><div>Аккаунт-менеджер</div><div>Sales-менеджер</div></div>
          <template v-for="client in filteredClients" :key="client.id">
            <div class="client-row client-grid"><div><button class="chev" @click="toggle(expandedClients,client.id)">{{expandedClients.has(client.id)?'⌄':'›'}}</button><strong @click="selectedClientId=client.id">{{client.name}}</strong></div><div>{{client.type||'—'}}</div><div>{{client.sector||'—'}}</div><div class="link">{{client.technologies.length}} технологий</div><div>{{client.projects.reduce((s,p)=>s+p.members.length,0)}}</div><div>{{client.account}}</div><div>{{client.sales}}</div></div>
            <template v-if="expandedClients.has(client.id)" v-for="project in client.projects" :key="project.id"><div class="project-strip"><strong>{{project.displayName}}</strong><button @click="openForm('member',{project_id:project.id,hours_per_day:8})">＋ участник</button></div><div v-if="project.members.length" class="member-subhead member-grid"><div>Сотрудник</div><div>Проект</div><div>Период работы</div><div>Технология</div><div>Уровень</div><div>Ставка, руб/ч</div><div>Загрузка, ч/д</div><div>Статус</div></div><div v-for="member in project.members" :key="member.id" class="member-row member-grid" @click="selectedMember={...member,project:project.displayName,projectId:project.id,client:client.name,responsible:client.sales}"><div>{{member.name}}</div><div>{{project.displayName}}</div><div>{{dateRu(member.from)}} - {{dateRu(member.to)}}</div><div>{{member.technology}}</div><div>{{member.level}}</div><div>{{member.rate||'—'}}</div><div>{{member.hours||'—'}}</div><div><UiBadge :tone="member.status==='На проекте'?'success':'neutral'">{{member.status}}</UiBadge></div></div></template>
          </template><div v-if="!filteredClients.length" class="empty-row">Клиентов пока нет. Создай первого клиента.</div>
        </div>
      </template>

      <template v-else-if="view==='leads'">
        <UiFilterBar><input v-model="query" class="irlix-search" placeholder="Поиск"><select><option>Ответственные</option></select><select><option>Статус</option></select><input type="date"></UiFilterBar>
        <table class="irlix-data-table"><thead><tr><th>Название</th><th>Статус</th><th>Источник</th><th>Ответственный</th><th>Добавлен</th><th>Изменён</th><th>Контакты</th></tr></thead><tbody><tr v-for="lead in leads.filter(l=>!query||l.name.toLowerCase().includes(query.toLowerCase()))" :key="lead.id" @click="selectedLead=lead"><td>{{lead.name}}</td><td><UiBadge :tone="leadTone(lead.status)">{{lead.status}}</UiBadge></td><td>{{lead.source||'—'}}</td><td>{{lead.responsible}}</td><td>{{lead.created}}</td><td>{{lead.updated}}</td><td>{{lead.contacts}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='contacts'">
        <UiFilterBar><input v-model="query" class="irlix-search" placeholder="Поиск по контактам"></UiFilterBar><table class="irlix-data-table"><thead><tr><th>ФИО</th><th>Должность</th><th>Телефон</th><th>Email</th><th>Связи</th></tr></thead><tbody><tr v-for="c in contacts.filter(c=>!query||[c.full_name,c.position,c.phone,c.email].join(' ').toLowerCase().includes(query.toLowerCase()))" :key="c.id"><td>{{c.full_name}}</td><td>{{c.position||'—'}}</td><td>{{c.phone||'—'}}</td><td>{{c.email||'—'}}</td><td>{{c.relations?.length||0}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='requests'">
        <div class="toolbar"><UiViewSwitch v-model="requestMode" :items="[{value:'tree',label:'Общий экран'},{value:'list',label:'Список'}]"/><UiFilterBar><input v-model="query" class="irlix-search" placeholder="Поиск по запросу, технологии или специалисту"><select><option>Статусы</option></select><select><option>Ответственные</option></select><select><option>Подразделение</option></select><select><option>Технологии</option></select></UiFilterBar></div>
        <div v-if="requestMode==='tree'" class="request-tree"><div class="request-head request-grid"><div>Запрос</div><div>Клиент</div><div>Кол-во</div><div>Статус</div><div>Активность</div><div>Ответственный</div></div><template v-for="r in requests" :key="r.id"><div class="request-row request-grid"><div><button class="chev" @click="toggle(expandedRequests,r.id)">{{expandedRequests.has(r.id)?'⌄':'›'}}</button><strong>{{r.title}}</strong><button class="inline-action" @click="openForm('position',{client_request_id:r.id,quantity:1})">＋ позиция</button></div><div>{{r.client}}</div><div>{{r.positions.reduce((s,p)=>s+Number(p.quantity),0)}}</div><div><UiBadge tone="info">{{r.status}}</UiBadge></div><div>{{r.until}}</div><div>{{r.responsible}}</div></div><template v-if="expandedRequests.has(r.id)" v-for="p in r.positions" :key="p.id"><div class="position-row request-grid"><div class="indent"><button class="chev" @click="toggle(expandedPositions,p.id)">{{p.attempts.length?(expandedPositions.has(p.id)?'⌄':'›'):''}}</button>{{p.title}} <em>{{p.level}}</em><button class="inline-action" @click="openForm('attempt',{position_id:p.id,responsible_employee_id:r.responsible_employee_id})">＋ попытка</button></div><div>{{p.department}}</div><div>{{p.quantity}}</div><div><UiBadge tone="success">{{p.status}}</UiBadge></div><div>{{r.until}}</div><div>{{r.responsible}}</div></div><div v-if="expandedPositions.has(p.id)" class="attempt-stack"><div v-for="a in p.attempts" :key="a.id" class="attempt-row"><span>{{a.specialist}}</span><UiBadge :tone="attemptTone(a.status)">{{a.status}}</UiBadge></div></div></template></template></div>
        <table v-else class="irlix-data-table"><thead><tr><th>Запрос</th><th>Клиент</th><th>Активен до</th><th>Позиции</th><th>Статус</th><th>Ответственный</th></tr></thead><tbody><tr v-for="r in requests" :key="r.id"><td>{{r.title}}</td><td>{{r.client}}</td><td>{{r.until}}</td><td>{{r.positions.length}}</td><td>{{r.status}}</td><td>{{r.responsible}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='positions'">
        <UiFilterBar><input class="irlix-search" placeholder="Поиск"><select><option>Технологии</option></select><select><option>Направления</option></select><select><option>Клиенты</option></select><select><option>Ответственные</option></select><select><option>Статусы</option></select></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Позиция</th><th>Клиент</th><th>Ответственный</th><th>Направление</th><th>Срок</th><th>Статус</th><th>Рассмотрения</th><th></th></tr></thead><tbody><tr v-for="p in allPositions" :key="p.id"><td>{{p.request}} — {{p.title}} {{p.level}}</td><td>{{p.client}}</td><td>{{p.responsible}}</td><td>{{p.department}}</td><td>{{p.until}}</td><td><UiBadge tone="info">{{p.status}}</UiBadge></td><td>{{p.attempts.length}} попыток</td><td><button class="inline-action" @click="openForm('attempt',{position_id:p.id})">＋ попытка</button></td></tr></tbody></table>
      </template>

      <template v-else-if="view==='attempts'">
        <UiFilterBar><select><option>Статусы</option></select><select><option>Специалисты</option></select><select><option>Клиенты</option></select><select><option>Технологии</option></select><select><option>Подразделения</option></select><select><option>Ответственные</option></select></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Специалист</th><th>Позиция</th><th>Клиент</th><th>Ответственный</th><th>Статус</th><th>Контроль</th><th></th></tr></thead><tbody><tr v-for="a in allAttempts" :key="a.id"><td>{{a.specialist}}</td><td>{{a.position}}</td><td>{{a.client}}</td><td>{{a.responsible}}</td><td><select :value="a.status" @change="changeAttemptStatus(a,$event.target.value)"><option v-for="s in attemptStatuses" :key="s">{{s}}</option><option v-if="a.status==='Закрыта: успех'">Закрыта: успех</option></select></td><td>{{a.control}}</td><td><UiButton v-if="a.status==='Ожидает подключения'" compact @click="openMemberFromAttempt(a)">Создать подключение</UiButton></td></tr></tbody></table><section class="funnel"><h2>Воронка попыток</h2><div class="funnel-stage" v-for="(s,i) in ['Новая','CV отправлено','Интервью','Ожидает подключения','Закрыта: успех']" :key="s" :style="{width:(100-i*12)+'%'}"><strong>{{s}}</strong><span>{{allAttempts.filter(a=>a.status===s).length}}</span></div><div class="funnel-fail">Закрыта: неудача — {{allAttempts.filter(a=>a.status.includes('неудача')).length}}</div></section>
      </template>

      <template v-else-if="view==='members'">
        <UiFilterBar><input class="irlix-search" placeholder="Поиск по сотруднику"><select><option>Клиенты</option></select><select><option>Проекты</option></select><select><option>Технологии</option></select></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Сотрудник</th><th>Клиент</th><th>Проект</th><th>Технология / уровень</th><th>Ставка</th><th>Загрузка</th><th>Период</th><th>Статус</th></tr></thead><tbody><tr v-for="m in allMembers" :key="m.id" @click="selectedMember=m"><td>{{m.name}}</td><td>{{m.client}}</td><td>{{m.project}}</td><td>{{m.technology}} / {{m.level}}</td><td>{{m.rate||'—'}}</td><td>{{m.hours||'—'}}</td><td>{{dateRu(m.from)}} - {{dateRu(m.to)}}</td><td>{{m.status}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='cashflow'">
        <UiFilterBar><input class="irlix-search" placeholder="Поиск по сотрудникам"><select><option>Клиенты</option></select><select><option>Сейлзы</option></select><select><option>Аккаунты</option></select><select><option>Подразделения</option></select><select><option>Технологии</option></select><input v-model="cashMonth" type="month"></UiFilterBar><table class="irlix-data-table cash"><thead><tr><th>Сотрудник</th><th>Технология / уровень</th><th>Клиент / Проект</th><th>Загрузка, ч/д</th><th>Период условий</th><th>Ставка</th><th>Часы: Календарь / ТШ / Подтверждено</th><th>ДС: Календарь / ТШ / Подтверждено</th></tr></thead><tbody><tr v-for="(m,index) in cashRows" :key="`${m.id}-${m.term.id}-${index}`"><td>{{m.name}}</td><td>{{m.term.technology}} / {{m.term.level}}</td><td>{{m.client}}<small>{{m.project}}</small></td><td>{{m.term.hours_per_day}}</td><td>{{dateRu(m.start)}} - {{dateRu(m.finish)}}</td><td>{{m.term.hourly_rate}}</td><td><strong>{{m.calendarHours}}</strong> / TODO / TODO</td><td><strong>{{money(m.calendarMoney)}}</strong> / TODO / TODO</td></tr></tbody></table><p class="todo">Календарь учитывает рабочие дни и созданные отсутствия из Vacations независимо от статуса. ТШ — TODO интеграции с Timesheets. Подтверждено — TODO после завершения Reporting Periods.</p>
      </template>

      <template v-else-if="view==='reports'">
        <UiViewSwitch v-model="reportMode" :items="[{value:'kanban',label:'Канбан'},{value:'gantt',label:'Гант'}]"/><div v-if="reportMode==='kanban'" class="kanban"><section v-for="stage in reportStages" :key="stage"><h3>{{stage}} <span>{{reports.filter(r=>r.stage===stage).length}}</span></h3><article v-for="r in reports.filter(r=>r.stage===stage)" :key="r.id"><strong>{{r.client}}</strong><small>{{r.period}}</small><select :value="r.stage" @change="changeReportStatus(r,$event.target.value)"><option v-for="s in reportStages" :key="s">{{s}}</option></select></article></section></div><div v-else class="gantt"><div class="gantt-grid"><div class="g-head">Клиент</div><div v-for="m in ['Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь']" :key="m" class="g-head">{{m}}</div><template v-for="r in reports" :key="r.id"><div class="g-client"><strong>{{r.client}}</strong></div><div v-for="i in 9" :key="i" class="g-cell"><span v-if="i===5" :class="['period',r.stage==='Счет оплачен'?'paid':r.stage.includes('согласован')?'approved':'progress']">{{r.period}}<br>{{r.stage}}</span></div></template></div></div>
      </template>
    </main>
  </section>

  <UiDrawer :open="!!selectedMember" :title="selectedMember?`${selectedMember.name} (${selectedMember.project||''})`:''" @close="selectedMember=null"><template v-if="selectedMember"><div class="drawer-meta"><b>Клиент</b><span>{{selectedMember.client}}</span><b>Ответственный</b><span>{{selectedMember.responsible||'—'}}</span></div><UiTabs :items="['Условия','Заметки']" model-value="Условия"/><div class="drawer-actions"><UiButton @click="openForm('terms',{member_id:selectedMember.id,technology:selectedMember.technology,level:selectedMember.level,hourly_rate:selectedMember.rate,hours_per_day:selectedMember.hours||8})">＋ Новые условия</UiButton></div><div class="terms-head"><span>Период</span><span>Ставка, руб/ч</span><span>Нагрузка</span><span>Технология</span></div><div v-for="t in selectedMember.terms||[]" :key="t.id" class="term-row"><span>{{dateRu(t.valid_from)}} - {{dateRu(t.valid_to)}}</span><span>{{t.hourly_rate}}</span><span>{{t.hours_per_day}}</span><span>{{t.technology}} ({{t.level}})</span></div></template></UiDrawer>

  <UiDrawer :open="!!selectedLead" :title="selectedLead?`Лид ${selectedLead.name}`:''" @close="selectedLead=null"><template v-if="selectedLead"><h2>Информация</h2><div class="info-grid"><b>Название</b><span>{{selectedLead.name}}</span><b>Дата создания</b><span>{{selectedLead.created}}</span><b>Источник</b><span>{{selectedLead.source||'—'}}</span><b>Ответственный</b><span>{{selectedLead.responsible}}</span><b>Статус / Итог</b><select :value="selectedLead.status" @change="changeLeadStatus(selectedLead,$event.target.value)"><option v-for="s in leadStatuses" :key="s">{{s}}</option></select></div><div class="drawer-actions"><UiButton v-if="selectedLead.status==='Сделка закрыта - Успех' && !selectedLead.converted_client_id" @click="openForm('convertLead',{lead_id:selectedLead.id,name:selectedLead.name})">Создать клиента</UiButton></div><UiTabs :items="['Заметки','Контакты']" model-value="Заметки"/><textarea class="note" placeholder="Заметки будут подключены следующим этапом"></textarea></template></UiDrawer>

  <UiDrawer :open="!!selectedClient" :title="selectedClient?.name||''" width="78vw" @close="selectedClientId=null"><template v-if="selectedClient"><div class="client-card-head"><div><strong>{{selectedClient.account}}</strong><small>Аккаунт-менеджер</small></div><div><strong>{{selectedClient.sales}}</strong><small>Sales-менеджер</small></div></div><UiTabs :items="['О клиенте','Контакты','Проекты','Подключения','Отчётные периоды','Заметки','История']" model-value="Подключения"/><div class="drawer-actions"><UiButton variant="secondary" @click="openForm('project',{client_id:selectedClient.id})">＋ Новый проект</UiButton></div><div v-for="p in selectedClient.projects" :key="p.id" class="project-card"><strong>{{p.displayName}}</strong><div v-for="m in p.members" :key="m.id" class="project-member" @click="selectedMember={...m,project:p.displayName,projectId:p.id,client:selectedClient.name,responsible:selectedClient.sales}"><span>{{m.name}}</span><span>{{m.technology}} {{m.level}}</span><span>{{m.rate||'—'}} ₽/ч</span><span>{{m.hours||'—'}} ч/д</span><UiBadge :tone="m.status==='На проекте'?'success':'neutral'">{{m.status}}</UiBadge></div></div></template></UiDrawer>

  <UiDrawer :open="!!formKind" :title="formTitle" width="520px" @close="closeForm"><form class="entity-form" @submit.prevent="submitForm"><div v-if="formError" class="error-banner">{{formError}}</div>
    <template v-if="['client','convertLead'].includes(formKind)"><label>Название<input v-model="form.name" required></label><label>Тип<input v-model="form.type"></label><label>Сектор<input v-model="form.sector"></label><label v-if="formKind==='client'">Sales<select v-model="form.sales_employee_id"><option value="">—</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Account Manager<select v-model="form.account_employee_id" required><option value="">Выберите</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label></template>
    <template v-else-if="formKind==='lead'"><label>Название<input v-model="form.name" required></label><label>Источник<input v-model="form.source"></label><label>Ответственный<select v-model="form.responsible_employee_id" required><option value="">Выберите</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Статус<select v-model="form.status"><option v-for="s in leadStatuses" :key="s">{{s}}</option></select></label></template>
    <template v-else-if="formKind==='contact'"><label>ФИО<input v-model="form.full_name" required></label><label>Должность<input v-model="form.position"></label><label>Телефон<input v-model="form.phone"></label><label>Email<input v-model="form.email" type="email"></label></template>
    <template v-else-if="formKind==='project'"><label>Название проекта<input v-model="form.name" required></label></template>
    <template v-else-if="formKind==='member'"><label v-if="form.client_id">Проект<select v-model="form.project_id" required><option v-for="p in clients.find(c=>c.id===Number(form.client_id))?.projects||[]" :key="p.id" :value="p.id">{{p.displayName}}</option></select></label><label>Специалист<select v-model="form.specialist_id" required><option value="">Выберите</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Ставка, ₽/ч<input v-model="form.hourly_rate" type="number" min="0" step="0.01" required></label><label>Загрузка, ч/д<input v-model="form.hours_per_day" type="number" min="0" max="24" step="0.5" required></label><label>Начало<input v-model="form.valid_from" type="date" required></label><label>Окончание<input v-model="form.valid_to" type="date"></label></template>
    <template v-else-if="formKind==='terms'"><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Ставка, ₽/ч<input v-model="form.hourly_rate" type="number" min="0" step="0.01" required></label><label>Загрузка, ч/д<input v-model="form.hours_per_day" type="number" min="0" max="24" step="0.5" required></label><label>Начало<input v-model="form.valid_from" type="date" required></label><label>Окончание<input v-model="form.valid_to" type="date"></label></template>
    <template v-else-if="formKind==='request'"><label>Клиент<select v-model="form.client_id" required><option value="">Выберите</option><option v-for="c in clients" :key="c.id" :value="c.id">{{c.name}}</option></select></label><label>Название<input v-model="form.title" required></label><label>Ответственный<select v-model="form.responsible_employee_id" required><option value="">Выберите</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Срок<input v-model="form.deadline" type="date"></label><label>Описание<textarea v-model="form.description"></textarea></label></template>
    <template v-else-if="formKind==='position'"><label>Направление<input v-model="form.direction"></label><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Количество<input v-model="form.quantity" type="number" min="1" required></label><label>Описание<textarea v-model="form.description"></textarea></label></template>
    <template v-else-if="formKind==='attempt'"><label>Специалист<select v-model="form.specialist_id" required><option value="">Выберите</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Ответственный<select v-model="form.responsible_employee_id"><option value="">—</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Контрольная дата<input v-model="form.control_date" type="date"></label><label>Предлагаемая ставка<input v-model="form.proposed_rate" type="number" min="0" step="0.01"></label></template>
    <template v-else-if="formKind==='report'"><label>Клиент<select v-model="form.client_id" required><option value="">Выберите</option><option v-for="c in clients" :key="c.id" :value="c.id">{{c.name}}</option></select></label><label>Начало периода<input v-model="form.period_start" type="date" required></label><label>Конец периода<input v-model="form.period_end" type="date" required></label></template>
    <div class="form-actions"><UiButton type="submit" :disabled="saving">{{saving?'Сохраняю…':'Сохранить'}}</UiButton><UiButton type="button" variant="secondary" @click="closeForm">Отмена</UiButton></div>
  </form></UiDrawer>
</div>
</template>
