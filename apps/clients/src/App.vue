<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiFilterBar, UiViewSwitch } from '@irlix/ui';

const view = ref('clients');
const loading = ref(false);
const error = ref('');
const query = ref('');
const overview = reactive({ clients: [], leads: [], contacts: [], requests: [], reportingPeriods: [] });
const employees = ref([]);
const expandedClients = reactive(new Set());
const expandedRequests = reactive(new Set());
const expandedPositions = reactive(new Set());
const selectedMember = ref(null);
const selectedLead = ref(null);
const selectedClient = ref(null);
const requestMode = ref('tree');
const reportMode = ref('kanban');
const formKind = ref('');
const form = reactive({});
const formError = ref('');
const saving = ref(false);
const cashMonth = ref(new Date().toISOString().slice(0, 7));
const absences = ref([]);

const nav = [
  ['clients', 'Клиенты', '▣'], ['leads', 'Лиды', '♛'], ['contacts', 'Контакты', '◉'],
  ['requests', 'Запросы', '◎'], ['positions', 'Позиции', '≡'], ['attempts', 'Попытки', '↗'],
  ['members', 'Участники', '♙'], ['cashflow', 'ДДС', '◫'], ['reports', 'Отчётные периоды', '▦'],
];
const titles = { clients:'Клиенты', leads:'Лиды', contacts:'Контактные лица', requests:'Запросы', positions:'Позиции', attempts:'Попытки подключения', members:'Участники проектов', cashflow:'ДДС', reports:'Отчётные периоды' };
const leadStatuses = ['Новый лид','Первичный контакт','Уточнение потребностей','КП отправлено','Активные переговоры','Клиент в игноре','Сделка закрыта - Успех','Сделка закрыта - Отказ'];
const attemptStatuses = ['Новая','CV отправлено','Интервью','Ожидает подключения','Закрыта: неудача'];
const reportStages = ['Новый','ТШ на согласовании','ТШ согласованы','Акт на согласовании','Акт согласован','Счет оплачен'];
const title = computed(() => titles[view.value]);
const employeeMap = computed(() => new Map(employees.value.map(e => [Number(e.id), e.full_name || `#${e.id}`])));
const employeeName = id => employeeMap.value.get(Number(id)) || (id ? `#${id}` : '—');
const dateRu = value => value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('ru-RU') : '...';
const iso = value => String(value || '').slice(0, 10);
const money = value => `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0))} ₽`;
const latestTerms = member => [...(member.terms || [])].sort((a,b) => String(b.valid_from).localeCompare(String(a.valid_from)))[0] || null;

async function api(url, options = {}) {
  const response = await fetch(url, { ...options, headers: { Accept:'application/json', 'Content-Type':'application/json', ...(options.headers || {}) } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

async function load() {
  loading.value = true; error.value = '';
  try {
    const [people, data] = await Promise.all([api('/api/employees/employees'), api('/api/clients/overview')]);
    employees.value = people.data || [];
    Object.assign(overview, data.data || {});
    overview.clients = (overview.clients || []).map(c => ({ ...c, id:Number(c.id), projects:(c.projects || []).map(p => ({ ...p, id:Number(p.id), displayName:p.name || 'Основной проект', members:(p.members || []).map(m => ({ ...m, id:Number(m.id), terms:(m.terms || []).map(t => ({ ...t, id:Number(t.id) })) })) })) }));
    overview.leads = overview.leads || [];
    overview.contacts = overview.contacts || [];
    overview.requests = overview.requests || [];
    overview.reportingPeriods = overview.reportingPeriods || [];
    overview.clients.slice(0, 2).forEach(c => expandedClients.add(c.id));
    await loadAbsences();
  } catch (e) { error.value = e.message || String(e); }
  finally { loading.value = false; }
}

const clients = computed(() => overview.clients || []);
const filteredClients = computed(() => {
  const needle = query.value.trim().toLowerCase();
  return clients.value.filter(c => !needle || [c.name,c.type,c.sector,employeeName(c.account_employee_id),employeeName(c.sales_employee_id)].join(' ').toLowerCase().includes(needle));
});
const allMembers = computed(() => clients.value.flatMap(c =>
  c.projects.flatMap(p =>
    p.members.map(m => ({ ...m, clientId:c.id, client:c.name, projectId:p.id, project:p.displayName, sales:employeeName(c.sales_employee_id) }))
  )
));
const allPositions = computed(() => (overview.requests || []).flatMap(r =>
  (r.positions || []).map(p => ({ ...p, requestId:r.id, requestTitle:r.title, clientId:Number(r.client_id), responsibleId:r.responsible_employee_id, deadline:r.deadline }))
));
const allAttempts = computed(() => (overview.requests || []).flatMap(r =>
  (r.positions || []).flatMap(p =>
    (p.attempts || []).map(a => ({ ...a, clientId:Number(r.client_id), request:r.title, positionId:p.id, position:`${p.technology} ${p.level}`, responsibleId:r.responsible_employee_id }))
  )
));

function memberCurrent(member) {
  const today = new Date().toISOString().slice(0,10);
  return (member.terms || []).find(t => iso(t.valid_from) <= today && (!t.valid_to || iso(t.valid_to) >= today)) || latestTerms(member);
}
function memberStatus(member) {
  const today = new Date().toISOString().slice(0,10);
  return (member.terms || []).some(t => iso(t.valid_from) <= today && (!t.valid_to || iso(t.valid_to) >= today)) ? 'На проекте' : 'Работал ранее';
}
function toggle(set, id) { set.has(Number(id)) ? set.delete(Number(id)) : set.add(Number(id)); }
function tone(status='') { return status.includes('Отказ') || status.includes('неудач') ? 'danger' : status.includes('Успех') || status.includes('успех') || status === 'Счет оплачен' ? 'success' : 'info'; }
function openForm(kind, initial={}) { formKind.value = kind; formError.value = ''; Object.keys(form).forEach(k => delete form[k]); Object.assign(form, initial); }
function closeForm() { formKind.value = ''; formError.value = ''; }

const formTitle = computed(() => ({ client:'Новый клиент', convertLead:'Создать клиента из лида', lead:'Новый лид', contact:'Новый контакт', project:'Новый проект', member:'Новое подключение', terms:'Новые условия', request:'Новый запрос', position:'Новая позиция', attempt:'Новая попытка', report:'Новый отчётный период' }[formKind.value] || ''));

async function submit() {
  saving.value = true; formError.value = '';
  try {
    if (formKind.value === 'client') await api('/api/clients/clients', { method:'POST', body:JSON.stringify({ name:form.name, type:form.type||null, sector:form.sector||null, sales_employee_id:Number(form.sales_employee_id)||null, account_employee_id:Number(form.account_employee_id) }) });
    if (formKind.value === 'convertLead') await api(`/api/clients/leads/${form.lead_id}/convert`, { method:'POST', body:JSON.stringify({ name:form.name, account_employee_id:Number(form.account_employee_id) }) });
    if (formKind.value === 'lead') await api('/api/clients/leads', { method:'POST', body:JSON.stringify({ name:form.name, source:form.source||null, responsible_employee_id:Number(form.responsible_employee_id), status:form.status||'Новый лид' }) });
    if (formKind.value === 'contact') await api('/api/clients/contacts', { method:'POST', body:JSON.stringify({ full_name:form.full_name, position:form.position||null, phone:form.phone||null, email:form.email||null }) });
    if (formKind.value === 'project') await api(`/api/clients/clients/${form.client_id}/projects`, { method:'POST', body:JSON.stringify({ name:form.name }) });
    if (formKind.value === 'member') {
      const e = employees.value.find(x => Number(x.id) === Number(form.specialist_id));
      await api(`/api/clients/projects/${form.project_id}/members`, { method:'POST', body:JSON.stringify({ specialist_id:Number(form.specialist_id), specialist_name:e?.full_name || `#${form.specialist_id}`, source_attempt_id:form.source_attempt_id?Number(form.source_attempt_id):null, technology:form.technology, level:form.level, hourly_rate:Number(form.hourly_rate), hours_per_day:Number(form.hours_per_day), valid_from:form.valid_from, valid_to:form.valid_to||null }) });
    }
    if (formKind.value === 'terms') await api(`/api/clients/members/${form.member_id}/terms`, { method:'POST', body:JSON.stringify({ technology:form.technology, level:form.level, hourly_rate:Number(form.hourly_rate), hours_per_day:Number(form.hours_per_day), valid_from:form.valid_from, valid_to:form.valid_to||null }) });
    if (formKind.value === 'request') await api('/api/clients/requests', { method:'POST', body:JSON.stringify({ client_id:Number(form.client_id), title:form.title, description:form.description||null, responsible_employee_id:Number(form.responsible_employee_id), deadline:form.deadline||null }) });
    if (formKind.value === 'position') await api(`/api/clients/requests/${form.request_id}/positions`, { method:'POST', body:JSON.stringify({ direction:form.direction||null, technology:form.technology, level:form.level, quantity:Number(form.quantity||1), description:form.description||null }) });
    if (formKind.value === 'attempt') {
      const e = employees.value.find(x => Number(x.id) === Number(form.specialist_id));
      await api(`/api/clients/positions/${form.position_id}/attempts`, { method:'POST', body:JSON.stringify({ specialist_id:Number(form.specialist_id), specialist_name:e?.full_name || `#${form.specialist_id}`, responsible_employee_id:Number(form.responsible_employee_id)||null, control_date:form.control_date||null, proposed_rate:form.proposed_rate?Number(form.proposed_rate):null }) });
    }
    if (formKind.value === 'report') await api('/api/clients/reporting-periods', { method:'POST', body:JSON.stringify({ client_id:Number(form.client_id), period_start:form.period_start, period_end:form.period_end }) });
    closeForm(); await load();
  } catch (e) { formError.value = e.message || String(e); }
  finally { saving.value = false; }
}

async function patch(url, payload) { try { await api(url, { method:'PATCH', body:JSON.stringify(payload) }); await load(); } catch (e) { error.value = e.message; } }
function createFromAttempt(a) {
  const client = clients.value.find(c => c.id === Number(a.clientId));
  const project = client?.projects?.[0];
  openForm('member', { client_id:client?.id, project_id:project?.id, specialist_id:a.specialist_id, source_attempt_id:a.id, hourly_rate:Number(a.proposed_rate||0), hours_per_day:8 });
}

function monthRange() {
  const [year, month] = cashMonth.value.split('-').map(Number);
  const days = new Date(year, month, 0).getDate();
  return { from:`${year}-${String(month).padStart(2,'0')}-01`, to:`${year}-${String(month).padStart(2,'0')}-${String(days).padStart(2,'0')}` };
}
async function loadAbsences() {
  if (!allMembers.value.length) { absences.value = []; return; }
  const { from, to } = monthRange();
  const p = new URLSearchParams({ from, to });
  [...new Set(allMembers.value.map(m => Number(m.specialist_id)).filter(Boolean))].forEach(id => p.append('employee_ids[]', String(id)));
  try { absences.value = (await api(`/api/vacations/calendar-absences?${p}`)).data || []; } catch { absences.value = []; }
}
watch(cashMonth, loadAbsences);
const holidays2026 = new Set(['2026-01-01','2026-01-02','2026-01-05','2026-01-06','2026-01-07','2026-01-08','2026-01-09','2026-02-23','2026-03-09','2026-05-01','2026-05-11','2026-06-12','2026-11-04']);
function dates(from,to){ const out=[]; const d=new Date(`${from}T00:00:00`), end=new Date(`${to}T00:00:00`); while(d<=end){ out.push(`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`); d.setDate(d.getDate()+1); } return out; }
function workday(x){ const d=new Date(`${x}T00:00:00`); return d.getDay()!==0 && d.getDay()!==6 && !holidays2026.has(x); }
function absent(id,x){ return absences.value.some(a => Number(a.employee_id)===Number(id) && iso(a.starts_on)<=x && iso(a.ends_on)>=x); }
const cashRows = computed(() => {
  const {from,to}=monthRange(); const result=[];
  for(const m of allMembers.value) for(const t of m.terms||[]){
    const start=iso(t.valid_from)>from?iso(t.valid_from):from;
    const finish=!t.valid_to||iso(t.valid_to)>to?to:iso(t.valid_to);
    if(start>finish) continue;
    const h=dates(start,finish).filter(d=>workday(d)&&!absent(m.specialist_id,d)).length*Number(t.hours_per_day||0);
    result.push({...m,term:t,start,finish,hours:h,amount:h*Number(t.hourly_rate||0)});
  }
  return result.sort((a,b)=>a.client.localeCompare(b.client)||a.project.localeCompare(b.project)||String(a.specialist_name).localeCompare(String(b.specialist_name))||a.start.localeCompare(b.start));
});

onMounted(load);
</script>

<template>
<div class="clients-app irlix-ui">
  <aside class="rail"><div class="brand">X</div><a class="home" href="/">⌂</a><div class="rail-chip">ГС</div><nav><button v-for="n in nav" :key="n[0]" :class="{active:view===n[0]}" :title="n[1]" @click="view=n[0];query=''">{{n[2]}}</button></nav></aside>
  <section class="workspace">
    <header class="topbar"><span class="crumb">▣ {{title}}</span><span v-if="loading" class="loading-inline">Обновление…</span></header>
    <main class="content">
      <div v-if="error" class="error-banner">{{error}}<button @click="load">Повторить</button></div>
      <div class="page-head"><div><h1>{{title}}</h1><p>Clients · первая рабочая версия</p></div><UiButton v-if="view==='clients'" @click="openForm('client')">＋ Новый клиент</UiButton><UiButton v-if="view==='leads'" @click="openForm('lead',{status:'Новый лид'})">＋ Новый лид</UiButton><UiButton v-if="view==='contacts'" @click="openForm('contact')">＋ Новый контакт</UiButton><UiButton v-if="view==='requests'" @click="openForm('request')">＋ Новый запрос</UiButton><UiButton v-if="view==='reports'" @click="openForm('report')">＋ Новый отчётный период</UiButton></div>

      <template v-if="view==='clients'">
        <UiFilterBar><input v-model="query" class="irlix-search" placeholder="Поиск по клиентам"><select><option>Аккаунты</option></select><select><option>Сейлзы</option></select><select><option>Технологии</option></select><select><option>Подразделения</option></select><select><option>Активность</option></select></UiFilterBar>
        <div class="count">{{filteredClients.length}} клиентов</div>
        <div class="client-table"><div class="client-head client-grid"><div>Клиент</div><div>Тип</div><div>Сектор</div><div>Проекты</div><div>Участники</div><div>Аккаунт</div><div>Sales</div></div>
          <template v-for="c in filteredClients" :key="c.id"><div class="client-row client-grid"><div><button class="chev" @click="toggle(expandedClients,c.id)">{{expandedClients.has(c.id)?'⌄':'›'}}</button><strong @click="selectedClient=c">{{c.name}}</strong></div><div>{{c.type||'—'}}</div><div>{{c.sector||'—'}}</div><div>{{c.projects.length}}</div><div>{{c.projects.reduce((s,p)=>s+p.members.length,0)}}</div><div>{{employeeName(c.account_employee_id)}}</div><div>{{employeeName(c.sales_employee_id)}}</div></div>
            <template v-if="expandedClients.has(c.id)" v-for="p in c.projects" :key="p.id"><div class="project-strip"><strong>{{p.displayName}}</strong><button @click="openForm('member',{project_id:p.id,hours_per_day:8})">＋ участник</button></div><div v-for="m in p.members" :key="m.id" class="member-row member-grid" @click="selectedMember={...m,client:c.name,project:p.displayName,sales:employeeName(c.sales_employee_id)}"><div>{{m.specialist_name}}</div><div>{{p.displayName}}</div><div>{{dateRu(memberCurrent(m)?.valid_from)}} - {{dateRu(memberCurrent(m)?.valid_to)}}</div><div>{{memberCurrent(m)?.technology||'—'}}</div><div>{{memberCurrent(m)?.level||'—'}}</div><div>{{memberCurrent(m)?.hourly_rate||'—'}}</div><div>{{memberCurrent(m)?.hours_per_day||'—'}}</div><div><UiBadge :tone="memberStatus(m)==='На проекте'?'success':'neutral'">{{memberStatus(m)}}</UiBadge></div></div></template>
          </template>
        </div>
      </template>

      <template v-else-if="view==='leads'">
        <UiFilterBar><input v-model="query" class="irlix-search" placeholder="Поиск"><select><option>Ответственные</option></select><select><option>Статус</option></select></UiFilterBar>
        <table class="irlix-data-table"><thead><tr><th>Название</th><th>Статус</th><th>Источник</th><th>Ответственный</th><th>Добавлен</th><th>Контакты</th></tr></thead><tbody><tr v-for="l in overview.leads.filter(x=>!query||x.name.toLowerCase().includes(query.toLowerCase()))" :key="l.id" @click="selectedLead=l"><td>{{l.name}}</td><td><UiBadge :tone="tone(l.status)">{{l.status}}</UiBadge></td><td>{{l.source||'—'}}</td><td>{{employeeName(l.responsible_employee_id)}}</td><td>{{dateRu(l.created_at)}}</td><td>{{overview.contacts.filter(c=>(c.relations||[]).some(r=>r.entity_type==='lead'&&Number(r.entity_id)===Number(l.id))).length}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='contacts'">
        <UiFilterBar><input v-model="query" class="irlix-search" placeholder="Поиск по контактам"></UiFilterBar><table class="irlix-data-table"><thead><tr><th>ФИО</th><th>Должность</th><th>Телефон</th><th>Email</th><th>Связей</th></tr></thead><tbody><tr v-for="c in overview.contacts" :key="c.id"><td>{{c.full_name}}</td><td>{{c.position||'—'}}</td><td>{{c.phone||'—'}}</td><td>{{c.email||'—'}}</td><td>{{c.relations?.length||0}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='requests'">
        <div class="toolbar"><UiViewSwitch v-model="requestMode" :items="[{value:'tree',label:'Общий экран'},{value:'list',label:'Список'}]"/><UiFilterBar><input class="irlix-search" placeholder="Поиск"><select><option>Статусы</option></select><select><option>Ответственные</option></select><select><option>Подразделение</option></select><select><option>Технологии</option></select></UiFilterBar></div>
        <div v-if="requestMode==='tree'" class="request-tree"><div class="request-head request-grid"><div>Запрос</div><div>Клиент</div><div>Кол-во</div><div>Статус</div><div>Активность</div><div>Ответственный</div></div><template v-for="r in overview.requests" :key="r.id"><div class="request-row request-grid"><div><button class="chev" @click="toggle(expandedRequests,r.id)">{{expandedRequests.has(Number(r.id))?'⌄':'›'}}</button><strong>{{r.title}}</strong><button class="inline-action" @click="openForm('position',{request_id:r.id,quantity:1})">＋ позиция</button></div><div>{{clients.find(c=>c.id===Number(r.client_id))?.name||'#'+r.client_id}}</div><div>{{(r.positions||[]).reduce((s,p)=>s+Number(p.quantity),0)}}</div><div><UiBadge tone="info">{{r.status}}</UiBadge></div><div>{{dateRu(r.deadline)}}</div><div>{{employeeName(r.responsible_employee_id)}}</div></div><template v-if="expandedRequests.has(Number(r.id))" v-for="p in r.positions||[]" :key="p.id"><div class="position-row request-grid"><div><button class="chev" @click="toggle(expandedPositions,p.id)">{{expandedPositions.has(Number(p.id))?'⌄':'›'}}</button>{{p.technology}} {{p.level}}<button class="inline-action" @click="openForm('attempt',{position_id:p.id,responsible_employee_id:r.responsible_employee_id})">＋ попытка</button></div><div>{{p.direction||'—'}}</div><div>{{p.quantity}}</div><div><UiBadge tone="success">{{p.status}}</UiBadge></div><div>{{dateRu(r.deadline)}}</div><div>{{employeeName(r.responsible_employee_id)}}</div></div><div v-if="expandedPositions.has(Number(p.id))" class="attempt-stack"><div v-for="a in p.attempts||[]" :key="a.id" class="attempt-row"><span>{{a.specialist_name}}</span><UiBadge :tone="tone(a.status)">{{a.status}}</UiBadge></div></div></template></template></div>
        <table v-else class="irlix-data-table"><thead><tr><th>Запрос</th><th>Клиент</th><th>Активен до</th><th>Позиции</th><th>Статус</th><th>Ответственный</th></tr></thead><tbody><tr v-for="r in overview.requests" :key="r.id"><td>{{r.title}}</td><td>{{clients.find(c=>c.id===Number(r.client_id))?.name}}</td><td>{{dateRu(r.deadline)}}</td><td>{{r.positions?.length||0}}</td><td>{{r.status}}</td><td>{{employeeName(r.responsible_employee_id)}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='positions'">
        <UiFilterBar><input class="irlix-search" placeholder="Поиск"><select><option>Технологии</option></select><select><option>Направления</option></select><select><option>Клиенты</option></select><select><option>Статусы</option></select></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Позиция</th><th>Количество</th><th>Направление</th><th>Статус</th><th>Попытки</th><th></th></tr></thead><tbody><tr v-for="p in allPositions" :key="p.id"><td>{{p.technology}} {{p.level}}</td><td>{{p.quantity}}</td><td>{{p.direction||'—'}}</td><td>{{p.status}}</td><td>{{p.attempts?.length||0}}</td><td><button class="inline-action" @click="openForm('attempt',{position_id:p.id,responsible_employee_id:p.responsibleId})">＋ попытка</button></td></tr></tbody></table>
      </template>

      <template v-else-if="view==='attempts'">
        <UiFilterBar><select><option>Статусы</option></select><select><option>Специалисты</option></select><select><option>Клиенты</option></select><select><option>Ответственные</option></select></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Специалист</th><th>Позиция</th><th>Статус</th><th>Контроль</th><th>Ставка</th><th></th></tr></thead><tbody><tr v-for="a in allAttempts" :key="a.id"><td>{{a.specialist_name}}</td><td>{{a.position}}</td><td><select :value="a.status" @change="patch(`/api/clients/attempts/${a.id}`,{status:$event.target.value})"><option v-for="s in attemptStatuses" :key="s">{{s}}</option><option v-if="a.status==='Закрыта: успех'">Закрыта: успех</option></select></td><td>{{dateRu(a.control_date)}}</td><td>{{a.proposed_rate||'—'}}</td><td><UiButton v-if="a.status==='Ожидает подключения'" compact @click="createFromAttempt(a)">Создать подключение</UiButton></td></tr></tbody></table><section class="funnel"><h2>Воронка попыток</h2><div v-for="(s,i) in ['Новая','CV отправлено','Интервью','Ожидает подключения','Закрыта: успех']" :key="s" class="funnel-stage" :style="{width:(100-i*12)+'%'}"><strong>{{s}}</strong><span>{{allAttempts.filter(a=>a.status===s).length}}</span></div><div class="funnel-fail">Закрыта: неудача — {{allAttempts.filter(a=>a.status?.includes('неудач')).length}}</div></section>
      </template>

      <template v-else-if="view==='members'">
        <UiFilterBar><input class="irlix-search" placeholder="Поиск по сотруднику"><select><option>Клиенты</option></select><select><option>Проекты</option></select><select><option>Технологии</option></select></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Сотрудник</th><th>Клиент</th><th>Проект</th><th>Условия</th><th>Статус</th></tr></thead><tbody><tr v-for="m in allMembers" :key="m.id" @click="selectedMember=m"><td>{{m.specialist_name}}</td><td>{{m.client}}</td><td>{{m.project}}</td><td>{{memberCurrent(m)?.technology}} / {{memberCurrent(m)?.level}} · {{memberCurrent(m)?.hourly_rate}} ₽/ч · {{memberCurrent(m)?.hours_per_day}} ч/д</td><td>{{memberStatus(m)}}</td></tr></tbody></table>
      </template>

      <template v-else-if="view==='cashflow'">
        <UiFilterBar><input class="irlix-search" placeholder="Поиск по сотрудникам"><select><option>Клиенты</option></select><select><option>Сейлзы</option></select><select><option>Аккаунты</option></select><select><option>Подразделения</option></select><select><option>Технологии</option></select><input v-model="cashMonth" type="month"></UiFilterBar><table class="irlix-data-table cash"><thead><tr><th>Сотрудник</th><th>Технология / уровень</th><th>Клиент / проект</th><th>Загрузка</th><th>Период условий</th><th>Ставка</th><th>Часы: Календарь / ТШ / Подтверждено</th><th>ДС: Календарь / ТШ / Подтверждено</th></tr></thead><tbody><tr v-for="r in cashRows" :key="`${r.id}-${r.term.id}`"><td>{{r.specialist_name}}</td><td>{{r.term.technology}} / {{r.term.level}}</td><td>{{r.client}}<small>{{r.project}}</small></td><td>{{r.term.hours_per_day}}</td><td>{{dateRu(r.start)}} - {{dateRu(r.finish)}}</td><td>{{r.term.hourly_rate}}</td><td><strong>{{r.hours}}</strong> / TODO / TODO</td><td><strong>{{money(r.amount)}}</strong> / TODO / TODO</td></tr></tbody></table><p class="todo">Календарь учитывает рабочие дни и все созданные отсутствия Vacations. ТШ и подтверждённые значения — TODO интеграций.</p>
      </template>

      <template v-else-if="view==='reports'">
        <UiViewSwitch v-model="reportMode" :items="[{value:'kanban',label:'Канбан'},{value:'gantt',label:'Гант'}]"/><div v-if="reportMode==='kanban'" class="kanban"><section v-for="s in reportStages" :key="s"><h3>{{s}} <span>{{overview.reportingPeriods.filter(r=>r.status===s).length}}</span></h3><article v-for="r in overview.reportingPeriods.filter(x=>x.status===s)" :key="r.id"><strong>{{clients.find(c=>c.id===Number(r.client_id))?.name||'#'+r.client_id}}</strong><small>{{dateRu(r.period_start)}} - {{dateRu(r.period_end)}}</small><select :value="r.status" @change="patch(`/api/clients/reporting-periods/${r.id}`,{status:$event.target.value})"><option v-for="x in reportStages" :key="x">{{x}}</option></select></article></section></div><div v-else class="gantt"><div class="gantt-grid"><div class="g-head">Клиент</div><div v-for="m in ['Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь']" :key="m" class="g-head">{{m}}</div><template v-for="r in overview.reportingPeriods" :key="r.id"><div class="g-client">{{clients.find(c=>c.id===Number(r.client_id))?.name}}</div><div v-for="i in 9" :key="i" class="g-cell"><span v-if="i===5" class="period">{{dateRu(r.period_start)}} - {{dateRu(r.period_end)}}<br>{{r.status}}</span></div></template></div></div>
      </template>
    </main>
  </section>

  <UiDrawer :open="!!selectedMember" :title="selectedMember?.specialist_name||''" @close="selectedMember=null"><template v-if="selectedMember"><h2>{{selectedMember.client}} · {{selectedMember.project}}</h2><div class="drawer-actions"><UiButton @click="openForm('terms',{member_id:selectedMember.id,technology:memberCurrent(selectedMember)?.technology,level:memberCurrent(selectedMember)?.level,hourly_rate:memberCurrent(selectedMember)?.hourly_rate,hours_per_day:memberCurrent(selectedMember)?.hours_per_day||8})">＋ Новые условия</UiButton></div><div class="terms-head"><span>Период</span><span>Ставка</span><span>Нагрузка</span><span>Технология</span></div><div v-for="t in selectedMember.terms||[]" :key="t.id" class="term-row"><span>{{dateRu(t.valid_from)}} - {{dateRu(t.valid_to)}}</span><span>{{t.hourly_rate}}</span><span>{{t.hours_per_day}}</span><span>{{t.technology}} ({{t.level}})</span></div></template></UiDrawer>
  <UiDrawer :open="!!selectedLead" :title="selectedLead?`Лид ${selectedLead.name}`:''" @close="selectedLead=null"><template v-if="selectedLead"><div class="info-grid"><b>Название</b><span>{{selectedLead.name}}</span><b>Источник</b><span>{{selectedLead.source||'—'}}</span><b>Ответственный</b><span>{{employeeName(selectedLead.responsible_employee_id)}}</span><b>Статус</b><select :value="selectedLead.status" @change="patch(`/api/clients/leads/${selectedLead.id}`,{status:$event.target.value});selectedLead=null"><option v-for="s in leadStatuses" :key="s">{{s}}</option></select></div><UiButton v-if="selectedLead.status==='Сделка закрыта - Успех'&&!selectedLead.converted_client_id" @click="openForm('convertLead',{lead_id:selectedLead.id,name:selectedLead.name})">Создать клиента</UiButton></template></UiDrawer>
  <UiDrawer :open="!!selectedClient" :title="selectedClient?.name||''" width="78vw" @close="selectedClient=null"><template v-if="selectedClient"><div class="client-card-head"><div><strong>{{employeeName(selectedClient.account_employee_id)}}</strong><small>Аккаунт-менеджер</small></div><div><strong>{{employeeName(selectedClient.sales_employee_id)}}</strong><small>Sales-менеджер</small></div></div><div class="drawer-actions"><UiButton variant="secondary" @click="openForm('project',{client_id:selectedClient.id})">＋ Новый проект</UiButton></div><div v-for="p in selectedClient.projects" :key="p.id" class="project-card"><strong>{{p.displayName}}</strong><div v-for="m in p.members" :key="m.id" class="project-member" @click="selectedMember={...m,client:selectedClient.name,project:p.displayName}"><span>{{m.specialist_name}}</span><span>{{memberCurrent(m)?.technology}} {{memberCurrent(m)?.level}}</span><span>{{memberCurrent(m)?.hourly_rate}} ₽/ч</span><span>{{memberCurrent(m)?.hours_per_day}} ч/д</span><UiBadge :tone="memberStatus(m)==='На проекте'?'success':'neutral'">{{memberStatus(m)}}</UiBadge></div></div></template></UiDrawer>

  <UiDrawer :open="!!formKind" :title="formTitle" width="520px" @close="closeForm"><form class="entity-form" @submit.prevent="submit"><div v-if="formError" class="error-banner">{{formError}}</div>
    <template v-if="['client','convertLead'].includes(formKind)"><label>Название<input v-model="form.name" required></label><label v-if="formKind==='client'">Тип<input v-model="form.type"></label><label v-if="formKind==='client'">Сектор<input v-model="form.sector"></label><label v-if="formKind==='client'">Sales<select v-model="form.sales_employee_id"><option value="">—</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Account Manager<select v-model="form.account_employee_id" required><option value="">Выберите</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label></template>
    <template v-else-if="formKind==='lead'"><label>Название<input v-model="form.name" required></label><label>Источник<input v-model="form.source"></label><label>Ответственный<select v-model="form.responsible_employee_id" required><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Статус<select v-model="form.status"><option v-for="s in leadStatuses" :key="s">{{s}}</option></select></label></template>
    <template v-else-if="formKind==='contact'"><label>ФИО<input v-model="form.full_name" required></label><label>Должность<input v-model="form.position"></label><label>Телефон<input v-model="form.phone"></label><label>Email<input v-model="form.email" type="email"></label></template>
    <template v-else-if="formKind==='project'"><label>Название проекта<input v-model="form.name" required></label></template>
    <template v-else-if="formKind==='member'"><label v-if="form.client_id">Проект<select v-model="form.project_id" required><option v-for="p in clients.find(c=>c.id===Number(form.client_id))?.projects||[]" :key="p.id" :value="p.id">{{p.displayName}}</option></select></label><label>Специалист<select v-model="form.specialist_id" required><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Ставка, ₽/ч<input v-model="form.hourly_rate" type="number" required></label><label>Загрузка, ч/д<input v-model="form.hours_per_day" type="number" step="0.5" required></label><label>Начало<input v-model="form.valid_from" type="date" required></label><label>Окончание<input v-model="form.valid_to" type="date"></label></template>
    <template v-else-if="formKind==='terms'"><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Ставка<input v-model="form.hourly_rate" type="number" required></label><label>Загрузка<input v-model="form.hours_per_day" type="number" required></label><label>Начало<input v-model="form.valid_from" type="date" required></label><label>Окончание<input v-model="form.valid_to" type="date"></label></template>
    <template v-else-if="formKind==='request'"><label>Клиент<select v-model="form.client_id" required><option v-for="c in clients" :key="c.id" :value="c.id">{{c.name}}</option></select></label><label>Название<input v-model="form.title" required></label><label>Ответственный<select v-model="form.responsible_employee_id" required><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Срок<input v-model="form.deadline" type="date"></label><label>Описание<textarea v-model="form.description"></textarea></label></template>
    <template v-else-if="formKind==='position'"><label>Направление<input v-model="form.direction"></label><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Количество<input v-model="form.quantity" type="number" min="1" required></label><label>Описание<textarea v-model="form.description"></textarea></label></template>
    <template v-else-if="formKind==='attempt'"><label>Специалист<select v-model="form.specialist_id" required><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Ответственный<select v-model="form.responsible_employee_id"><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Контрольная дата<input v-model="form.control_date" type="date"></label><label>Предлагаемая ставка<input v-model="form.proposed_rate" type="number"></label></template>
    <template v-else-if="formKind==='report'"><label>Клиент<select v-model="form.client_id" required><option v-for="c in clients" :key="c.id" :value="c.id">{{c.name}}</option></select></label><label>Начало<input v-model="form.period_start" type="date" required></label><label>Конец<input v-model="form.period_end" type="date" required></label></template>
    <div class="form-actions"><UiButton type="submit" :disabled="saving">{{saving?'Сохраняю…':'Сохранить'}}</UiButton><UiButton type="button" variant="secondary" @click="closeForm">Отмена</UiButton></div>
  </form></UiDrawer>
</div>
</template>
