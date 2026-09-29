<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiFilterBar, UiSearchSelect, UiViewSwitch } from '@irlix/ui';
import ClientsSidebar from './components/ClientsSidebar.vue';
import ClientsBreadcrumbs from './components/ClientsBreadcrumbs.vue';
import ClientCard from './components/ClientCard.vue';
import ContactPersonCard from './components/ContactPersonCard.vue';
import ProjectMemberCard from './components/ProjectMemberCard.vue';
import PermissionsView from './components/PermissionsView.vue';
import ReportingPeriodsView from './components/ReportingPeriodsView.vue';
import CashFlowView from './components/CashFlowView.vue';

const view = ref('clients');
const loading = ref(false);
const error = ref('');
const query = ref('');
const contactClientFilter = ref('');
const contactCardOpen = ref(false);
const selectedContactId = ref(null);
const connectionScope = ref('active');
const clientFilters = reactive({ account: '', sales: '', technology: '', department: '', activity: '' });
const filterDraft = reactive({
  leadResponsible: '', leadStatus: '', requestStatus: '', requestResponsible: '', requestDepartment: '', requestTechnology: '',
  positionTechnology: '', positionDirection: '', positionClient: '', positionStatus: '',
  attemptStatus: '', attemptSpecialist: '', attemptClient: '', attemptResponsible: '',
  memberClient: '', memberProject: '', memberTechnology: '',
});
const overview = reactive({ clients: [], leads: [], contacts: [], requests: [], reportingPeriods: [] });
const employees = ref([]);
const accountingEmployeeId = ref(null);
const specialistTechnologies = ref([]);
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
const contourAccess = ref({ permissions: {}, platform_admin: false });
const reportCreateOpen = ref(false);

const titles = { clients:'Клиенты', leads:'Лиды', contacts:'Контактные лица', requests:'Запросы', positions:'Позиции', attempts:'Попытки подключения', members:'Участники проектов', cashflow:'ДДС', reports:'Отчётные периоды', permissions:'Настройки разрешений' };
const sectionPermissions = { clients:'clients.view', leads:'leads.view', contacts:'contacts.view', requests:'requests.view', positions:'positions.view', attempts:'attempts.view', members:'members.view', cashflow:'cashflow.view', reports:'reports.view', permissions:'permissions.view' };
const can = key => !!contourAccess.value.permissions?.[key]?.allowed;
const allowedSections = computed(() => Object.entries(sectionPermissions).filter(([,permission]) => can(permission)).map(([section]) => section));
const leadStatuses = ['Новый лид','Первичный контакт','Уточнение потребностей','КП отправлено','Активные переговоры','Клиент в игноре','Сделка закрыта - Успех','Сделка закрыта - Отказ'];
const attemptStatuses = ['Новая','CV отправлено','Интервью','Ожидает подключения','Закрыта: неудача'];
const requestStatuses = ['Новый','В работе','Закрыт: успех','Закрыт: неудача'];
const positionStatuses = ['Ждёт кандидатов','На рассмотрении','Закрыта: успех','Закрыта: неудача'];
const reportStages = ['Новый','ТШ на согласовании','ТШ согласованы','Акт на согласовании','Акт согласован','Счет оплачен'];
const memberLevels = ['Junior','Junior+','Middle','Middle+','Senior','Team Lead','Tech Lead'];
const title = computed(() => titles[view.value]);
const employeeMap = computed(() => new Map(employees.value.map(e => [Number(e.id), e.full_name || `#${e.id}`])));
const employeeRecordMap = computed(() => new Map(employees.value.map(e => [Number(e.id), e])));
const employeeName = id => employeeMap.value.get(Number(id)) || (id ? `#${id}` : '—');
const employeeOptions = computed(() => employees.value.map(e => ({ value:String(e.id), label:e.full_name || `#${e.id}` })).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const specialistTreeOptions = computed(() => {
  const groups = new Map();
  employees.value.forEach((employee) => {
    const direction = employee.department_name || 'Без направления';
    if (!groups.has(direction)) groups.set(direction, []);
    groups.get(direction).push(employee);
  });
  return [...groups.entries()]
    .sort(([a],[b]) => a.localeCompare(b, 'ru'))
    .flatMap(([direction, people]) => [
      { value:`direction:${direction}`, label:direction, kind:'group' },
      ...people.sort((a,b)=>String(a.full_name||'').localeCompare(String(b.full_name||''),'ru')).map(employee => ({ value:String(employee.id), label:employee.full_name || `#${employee.id}`, depth:1 })),
    ]);
});
const memberTechnologyOptions = computed(() => specialistTechnologies.value.map(t => ({ value:t.name, label:t.name })));
const memberLevelOptions = memberLevels.map(level => ({ value:level, label:level }));
const dateRu = value => value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('ru-RU') : '...';
const iso = value => String(value || '').slice(0, 10);
const money = value => `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0))} ₽`;
const latestTerms = member => [...(member.terms || [])].sort((a,b) => String(b.valid_from).localeCompare(String(a.valid_from)))[0] || null;
const memberStartedAt = member => { const dates = (member.terms || []).map(term => iso(term.valid_from)).filter(Boolean).sort(); return dates[0] || null; };

const selectView = (section) => { if (!titles[section] || (section !== 'permissions' && !allowedSections.value.includes(section))) return; view.value = section; query.value = ''; };
async function api(url, options = {}) { const response = await fetch(url, { ...options, headers: { Accept:'application/json', 'Content-Type':'application/json', ...(options.headers || {}) } }); const body = await response.json().catch(() => ({})); if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`); return body; }
async function load() {
  loading.value = true; error.value = '';
  try {
    const [directory, data, access] = await Promise.all([api('/api/employees/clients-directory'), api('/api/clients/overview'), api('/api/clients/permissions/me')]);
    contourAccess.value = access.data || { permissions: {} };
    if (!allowedSections.value.includes(view.value)) view.value = allowedSections.value[0] || 'clients';
    employees.value = directory.data?.employees || [];
    accountingEmployeeId.value = directory.data?.accounting ? (Number(directory.data?.actor?.id) || null) : null;
    Object.assign(overview, data.data || {});
    overview.clients = (overview.clients || []).map(c => ({ ...c, id:Number(c.id), projects:(c.projects || []).map(p => ({ ...p, id:Number(p.id), displayName:p.name || 'Основной проект', members:(p.members || []).map(m => ({ ...m, id:Number(m.id), terms:(m.terms || []).map(t => ({ ...t, id:Number(t.id) })) })) })) }));
    overview.leads = overview.leads || []; overview.contacts = overview.contacts || []; overview.requests = overview.requests || []; overview.reportingPeriods = overview.reportingPeriods || [];
    overview.clients.slice(0, 2).forEach(c => expandedClients.add(c.id));
    try { const catalog = await api('/api/specialists/catalog'); specialistTechnologies.value = catalog.data?.technologies || []; } catch { specialistTechnologies.value = []; }
  } catch (e) { error.value = e.message || String(e); } finally { loading.value = false; }
}

const clients = computed(() => overview.clients || []);
const clientOptions = computed(() => clients.value.map(c => ({ value:String(c.id), label:c.name })).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const projectOptions = computed(() => clients.value.flatMap(c => c.projects.map(p => ({ value:String(p.id), label:`${c.name} · ${p.displayName}` }))));
const clientAccountOptions = computed(() => [...new Set(clients.value.map(c => Number(c.account_employee_id)).filter(Boolean))].map(id => ({ value: String(id), label: employeeName(id) })).sort((a,b) => a.label.localeCompare(b.label, 'ru')));
const clientSalesOptions = computed(() => [...new Set(clients.value.map(c => Number(c.sales_employee_id)).filter(Boolean))].map(id => ({ value: String(id), label: employeeName(id) })).sort((a,b) => a.label.localeCompare(b.label, 'ru')));
const clientTechnologyOptions = computed(() => [...new Set(clients.value.flatMap(c => c.projects.flatMap(p => p.members.flatMap(m => (m.terms || []).map(t => t.technology).filter(Boolean)))))] .sort((a,b) => String(a).localeCompare(String(b), 'ru')));
const clientDepartmentOptions = computed(() => { const values = new Map(); employees.value.forEach((employee) => { if (employee?.department_id) values.set(String(employee.department_id), employee.department_name || `#${employee.department_id}`); }); return [...values].map(([value, label]) => ({ value, label })).sort((a,b) => a.label.localeCompare(b.label, 'ru')); });
const clientIsActive = (client) => client.projects.some(project => project.members.some(member => memberStatus(member) === 'На проекте'));
const filteredClients = computed(() => {
  const needle = query.value.trim().toLowerCase();
  return clients.value.filter((client) => {
    if (needle && ![client.name,client.type,client.sector,employeeName(client.account_employee_id),employeeName(client.sales_employee_id)].join(' ').toLowerCase().includes(needle)) return false;
    if (clientFilters.account && String(client.account_employee_id || '') !== clientFilters.account) return false;
    if (clientFilters.sales && String(client.sales_employee_id || '') !== clientFilters.sales) return false;
    if (clientFilters.technology && !client.projects.some(project => project.members.some(member => (member.terms || []).some(term => term.technology === clientFilters.technology)))) return false;
    if (clientFilters.department) { const accountDepartment = employeeRecordMap.value.get(Number(client.account_employee_id))?.department_id; const salesDepartment = employeeRecordMap.value.get(Number(client.sales_employee_id))?.department_id; if (![accountDepartment, salesDepartment].some(id => String(id || '') === clientFilters.department)) return false; }
    if (clientFilters.activity === 'active' && !clientIsActive(client)) return false;
    if (clientFilters.activity === 'inactive' && clientIsActive(client)) return false;
    return true;
  });
});
const contactClientRelations = contact => (contact.relations || []).filter(relation => relation.entity_type === 'client' && relation.active !== false && Number(relation.active) !== 0);
const contactBindingsText = contact => {
  const labels = contactClientRelations(contact).map(relation => {
    const client = clients.value.find(item => item.id === Number(relation.entity_id));
    const name = client?.name || `#${relation.entity_id}`;
    return relation.relation_role ? `${name} — ${relation.relation_role}` : name;
  });
  return labels.length ? labels.join(', ') : '—';
};
const filteredContacts = computed(() => {
  const needle = query.value.trim().toLowerCase();
  return (overview.contacts || []).filter(contact => {
    if (needle && !String(contact.full_name || '').toLowerCase().includes(needle)) return false;
    if (contactClientFilter.value && !contactClientRelations(contact).some(relation => String(relation.entity_id) === String(contactClientFilter.value))) return false;
    return true;
  });
});
function overviewContact(contact) {
  return {
    id: Number(contact.id),
    full_name: contact.full_name,
    relations: (contact.client_relations || []).map(relation => ({
      id: relation.id,
      contact_person_id: Number(contact.id),
      entity_type: 'client',
      entity_id: Number(relation.client_id),
      relation_role: relation.position || null,
      active: relation.active,
    })),
  };
}
function handleContactChanged(contact) {
  if (!contact?.id) return;
  const next = overviewContact(contact);
  const index = overview.contacts.findIndex(item => Number(item.id) === Number(next.id));
  if (index >= 0) overview.contacts.splice(index, 1, { ...overview.contacts[index], ...next });
  else overview.contacts.push(next);
}
function handleClientChanged(payload) {
  if (payload?.id && Array.isArray(payload.client_relations)) handleContactChanged(payload);
  else load();
}
function openContactCard(contact = null) { selectedContactId.value = contact ? Number(contact.id) : null; contactCardOpen.value = true; }
function closeContactCard() { contactCardOpen.value = false; selectedContactId.value = null; }

const allMembers = computed(() => clients.value.flatMap(c => c.projects.flatMap(p => p.members.map(m => ({ ...m, clientId:c.id, client:c.name, projectId:p.id, project:p.displayName, sales:employeeName(c.sales_employee_id) })))));
const allPositions = computed(() => (overview.requests || []).flatMap(r => (r.positions || []).map(p => ({ ...p, requestId:r.id, requestTitle:r.title, clientId:Number(r.client_id), responsibleId:r.responsible_employee_id, deadline:r.deadline }))));
const allAttempts = computed(() => (overview.requests || []).flatMap(r => (r.positions || []).flatMap(p => (p.attempts || []).map(a => ({ ...a, clientId:Number(r.client_id), request:r.title, positionId:p.id, position:`${p.technology} ${p.level}`, responsibleId:r.responsible_employee_id })))));
const directionOptions = computed(() => [...new Set(allPositions.value.map(p=>p.direction).filter(Boolean))].sort((a,b)=>String(a).localeCompare(String(b),'ru')));
const specialistOptions = computed(() => [...new Map(allAttempts.value.map(a=>[String(a.specialist_id), { value:String(a.specialist_id), label:a.specialist_name }])).values()]);
function memberActiveTerms(member) { const today = new Date().toISOString().slice(0,10); return (member.terms || []).find(t => iso(t.valid_from) <= today && (!t.valid_to || iso(t.valid_to) >= today)) || null; }
function memberCurrent(member) { return memberActiveTerms(member) || latestTerms(member); }
function memberDisplayTerms(member) { return connectionScope.value === 'active' ? memberActiveTerms(member) : latestTerms(member); }
function memberStatus(member) { return memberActiveTerms(member) ? 'На проекте' : 'Работал ранее'; }
function visibleProjectMembers(project) { return connectionScope.value === 'all' ? (project.members || []) : (project.members || []).filter(member => !!memberActiveTerms(member)); }
function visibleClientMemberCount(client) { return (client.projects || []).reduce((sum, project) => sum + visibleProjectMembers(project).length, 0); }
function toggle(set, id) { set.has(Number(id)) ? set.delete(Number(id)) : set.add(Number(id)); }
function tone(status='') { return status.includes('Отказ') || status.includes('неудач') ? 'danger' : status.includes('Успех') || status.includes('успех') || status === 'Счет оплачен' ? 'success' : 'info'; }
function openForm(kind, initial={}) { formKind.value = kind; formError.value = ''; Object.keys(form).forEach(k => delete form[k]); Object.assign(form, initial); if (accountingEmployeeId.value && kind === 'client') form.account_employee_id = accountingEmployeeId.value; if (accountingEmployeeId.value && kind === 'request') form.responsible_employee_id = accountingEmployeeId.value; }
function closeForm() { formKind.value = ''; formError.value = ''; }
const formTitle = computed(() => ({ client:'Новый клиент', convertLead:'Создать клиента из лида', lead:'Новый лид', project:'Новый проект', member:'Новое подключение', terms:'Новые условия', request:'Новый запрос', position:'Новая позиция', attempt:'Новая попытка', report:'Новый отчётный период' }[formKind.value] || ''));
async function submit() {
  saving.value = true; formError.value = '';
  try {
    if (formKind.value === 'client') await api('/api/clients/clients', { method:'POST', body:JSON.stringify({ name:form.name, type:form.type||null, sector:form.sector||null, sales_employee_id:Number(form.sales_employee_id)||null, account_employee_id:Number(form.account_employee_id) }) });
    if (formKind.value === 'convertLead') await api(`/api/clients/leads/${form.lead_id}/convert`, { method:'POST', body:JSON.stringify({ name:form.name, account_employee_id:Number(form.account_employee_id) }) });
    if (formKind.value === 'lead') await api('/api/clients/leads', { method:'POST', body:JSON.stringify({ name:form.name, source:form.source||null, responsible_employee_id:Number(form.responsible_employee_id), status:form.status||'Новый лид' }) });
    if (formKind.value === 'project') await api(`/api/clients/clients/${form.client_id}/projects`, { method:'POST', body:JSON.stringify({ name:form.name }) });
    if (formKind.value === 'member') { const e = employees.value.find(x => Number(x.id) === Number(form.specialist_id)); await api(`/api/clients/projects/${form.project_id}/members`, { method:'POST', body:JSON.stringify({ specialist_id:Number(form.specialist_id), specialist_name:e?.full_name || `#${form.specialist_id}`, source_attempt_id:form.source_attempt_id?Number(form.source_attempt_id):null, technology:form.technology, level:form.level, hourly_rate:Number(form.hourly_rate), hours_per_day:Number(form.hours_per_day), valid_from:form.valid_from, valid_to:form.valid_to||null }) }); }
    if (formKind.value === 'terms') await api(`/api/clients/members/${form.member_id}/terms`, { method:'POST', body:JSON.stringify({ technology:form.technology, level:form.level, hourly_rate:Number(form.hourly_rate), hours_per_day:Number(form.hours_per_day), valid_from:form.valid_from, valid_to:form.valid_to||null }) });
    if (formKind.value === 'request') await api('/api/clients/requests', { method:'POST', body:JSON.stringify({ client_id:Number(form.client_id), title:form.title, description:form.description||null, responsible_employee_id:Number(form.responsible_employee_id), deadline:form.deadline||null }) });
    if (formKind.value === 'position') await api(`/api/clients/requests/${form.request_id}/positions`, { method:'POST', body:JSON.stringify({ direction:form.direction||null, technology:form.technology, level:form.level, quantity:Number(form.quantity||1), description:form.description||null }) });
    if (formKind.value === 'attempt') { const e = employees.value.find(x => Number(x.id) === Number(form.specialist_id)); await api(`/api/clients/positions/${form.position_id}/attempts`, { method:'POST', body:JSON.stringify({ specialist_id:Number(form.specialist_id), specialist_name:e?.full_name || `#${form.specialist_id}`, responsible_employee_id:Number(form.responsible_employee_id)||null, control_date:form.control_date||null, proposed_rate:form.proposed_rate?Number(form.proposed_rate):null }) }); }
    if (formKind.value === 'report') await api('/api/clients/reporting-periods', { method:'POST', body:JSON.stringify({ client_id:Number(form.client_id), period_start:form.period_start, period_end:form.period_end }) });
    closeForm(); await load();
  } catch (e) { formError.value = e.message || String(e); } finally { saving.value = false; }
}
async function patch(url, payload) { try { await api(url, { method:'PATCH', body:JSON.stringify(payload) }); await load(); } catch (e) { error.value = e.message; } }
function createFromAttempt(a) { const client = clients.value.find(c => c.id === Number(a.clientId)); const project = client?.projects?.[0]; openForm('member', { client_id:client?.id, project_id:project?.id, specialist_id:a.specialist_id, source_attempt_id:a.id, hourly_rate:Number(a.proposed_rate||0), hours_per_day:8 }); }
onMounted(load);
</script>

<template>
<div class="clients-app irlix-ui">
  <ClientsSidebar :section="view" :allowed-sections="allowedSections" @update:section="selectView" />
  <section class="workspace">
    <header class="topbar">
      <ClientsBreadcrumbs :view="view" :title="title" :loading="loading" :request-mode="requestMode" :report-mode="reportMode" @update:request-mode="requestMode = $event" @update:report-mode="reportMode = $event" />
      <div class="topbar-actions">
        <UiButton v-if="view==='clients' && can('clients.manage')" @click="openForm('client')">＋ Новый клиент</UiButton>
        <UiButton v-if="view==='leads' && can('leads.manage')" @click="openForm('lead',{status:'Новый лид'})">＋ Новый лид</UiButton>
        <UiButton v-if="view==='contacts' && can('contacts.manage')" @click="openContactCard()">＋ Новый контакт</UiButton>
        <UiButton v-if="view==='requests' && can('requests.manage')" @click="openForm('request')">＋ Новый запрос</UiButton>
        <UiButton v-if="view==='reports' && can('reports.manage')" @click="reportCreateOpen=true">＋ Новый отчётный период</UiButton>
      </div>
    </header>
    <main class="content">
      <div v-if="error" class="error-banner">{{error}}<button @click="load">Повторить</button></div>
      <template v-if="view==='clients'">
        <UiFilterBar><input v-model="query" class="registry-search" type="search" placeholder="Поиск по клиентам"><UiSearchSelect v-model="clientFilters.account" :options="clientAccountOptions" placeholder="Аккаунты" search-placeholder="Поиск аккаунта"/><UiSearchSelect v-model="clientFilters.sales" :options="clientSalesOptions" placeholder="Сейлзы" search-placeholder="Поиск сейлза"/><UiSearchSelect v-model="clientFilters.technology" :options="clientTechnologyOptions" placeholder="Технологии" search-placeholder="Поиск технологии"/><UiSearchSelect v-model="clientFilters.department" :options="clientDepartmentOptions" placeholder="Подразделения" search-placeholder="Поиск подразделения"/><UiSearchSelect v-model="clientFilters.activity" :options="[{value:'active',label:'Активные'},{value:'inactive',label:'Неактивные'}]" placeholder="Активность" search-placeholder="Поиск"/><UiSearchSelect v-model="connectionScope" :options="[{value:'active',label:'Активные подключения'},{value:'all',label:'Все подключения'}]" placeholder="Подключения" search-placeholder="Поиск" :clearable="false" aria-label="Подключения"/></UiFilterBar>
        <div class="count">{{filteredClients.length}} клиентов</div><div class="client-table"><div class="client-head client-grid"><div>Клиент</div><div>Тип</div><div>Сектор</div><div>Участники</div><div>Аккаунт</div><div>Sales</div></div><template v-for="c in filteredClients" :key="c.id"><div class="client-row client-grid" @click="selectedClient=c"><div class="client-name-cell"><button class="chev client-toggle" :class="{open:expandedClients.has(c.id)}" :aria-label="expandedClients.has(c.id)?'Свернуть клиента':'Развернуть клиента'" @click.stop="toggle(expandedClients,c.id)"><span></span></button><strong>{{c.name}}</strong><button v-if="expandedClients.has(c.id)&&c.projects.length" class="project-member-add" @click.stop="openForm('member',{client_id:c.id,project_id:c.projects.find(p=>p.is_default)?.id||c.projects[0].id,hours_per_day:8})">＋ участник</button></div><div>{{c.type||'—'}}</div><div>{{c.sector||'—'}}</div><div>{{visibleClientMemberCount(c)}}</div><div>{{employeeName(c.account_employee_id)}}</div><div>{{employeeName(c.sales_employee_id)}}</div></div><template v-if="expandedClients.has(c.id)"><div class="member-header member-grid member-header--client"><div>Сотрудник</div><div>Проект</div><div>Работает с</div><div>Последняя ставка</div><div>Технология / уровень</div><div>Ставка, руб/ч</div><div>Загрузка, ч/д</div><div>Статус</div></div><template v-for="p in c.projects" :key="p.id"><div v-for="m in visibleProjectMembers(p)" :key="m.id" class="member-row member-grid member-row--client" @click="selectedMember={...m,client:c.name,project:p.displayName,sales:employeeName(c.sales_employee_id)}"><div class="tree-child tree-child--client-member">{{m.specialist_name}}</div><div>{{p.displayName}}</div><div>{{dateRu(memberStartedAt(m))}}</div><div>{{dateRu(memberDisplayTerms(m)?.valid_from)}} — {{memberDisplayTerms(m)?.valid_to?dateRu(memberDisplayTerms(m)?.valid_to):'по н.в.'}}</div><div class="technology-grade"><span>{{memberDisplayTerms(m)?.technology||'—'}}</span><sup v-if="memberDisplayTerms(m)?.level">{{memberDisplayTerms(m).level}}</sup></div><div>{{memberDisplayTerms(m)?.hourly_rate||'—'}}</div><div>{{memberDisplayTerms(m)?.hours_per_day||'—'}}</div><div><UiBadge :tone="memberStatus(m)==='На проекте'?'success':'neutral'">{{memberStatus(m)}}</UiBadge></div></div></template></template></template></div>
      </template>
      <template v-else-if="view==='leads'"><UiFilterBar><input v-model="query" class="registry-search" type="search" placeholder="Поиск"><UiSearchSelect v-model="filterDraft.leadResponsible" :options="employeeOptions" placeholder="Ответственные" search-placeholder="Поиск ответственного"/><UiSearchSelect v-model="filterDraft.leadStatus" :options="leadStatuses" placeholder="Статус" search-placeholder="Поиск статуса"/></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Название</th><th>Статус</th><th>Источник</th><th>Ответственный</th><th>Добавлен</th><th>Контакты</th></tr></thead><tbody><tr v-for="l in overview.leads.filter(x=>(!query||x.name.toLowerCase().includes(query.toLowerCase()))&&(!filterDraft.leadResponsible||String(x.responsible_employee_id)===String(filterDraft.leadResponsible))&&(!filterDraft.leadStatus||x.status===filterDraft.leadStatus))" :key="l.id" @click="selectedLead=l"><td>{{l.name}}</td><td><UiBadge :tone="tone(l.status)">{{l.status}}</UiBadge></td><td>{{l.source||'—'}}</td><td>{{employeeName(l.responsible_employee_id)}}</td><td>{{dateRu(l.created_at)}}</td><td>{{overview.contacts.filter(c=>(c.relations||[]).some(r=>r.entity_type==='lead'&&Number(r.entity_id)===Number(l.id))).length}}</td></tr></tbody></table></template>
      <template v-else-if="view==='contacts'"><UiFilterBar><input v-model="query" class="registry-search" type="search" placeholder="Поиск по ФИО"><UiSearchSelect v-model="contactClientFilter" :options="clientOptions" placeholder="Клиенты" search-placeholder="Поиск клиента"/></UiFilterBar><table class="irlix-data-table"><thead><tr><th>ФИО</th><th>Клиенты / должности</th></tr></thead><tbody><tr v-for="c in filteredContacts" :key="c.id" @click="openContactCard(c)"><td><strong>{{c.full_name}}</strong></td><td>{{contactBindingsText(c)}}</td></tr></tbody></table></template>
      <template v-else-if="view==='requests'"><div class="toolbar"><UiViewSwitch v-model="requestMode" :items="[{value:'tree',label:'Общий экран'},{value:'list',label:'Список'}]"/><UiFilterBar><input class="registry-search" type="search" placeholder="Поиск"><UiSearchSelect v-model="filterDraft.requestStatus" :options="requestStatuses" placeholder="Статусы" search-placeholder="Поиск статуса"/><UiSearchSelect v-model="filterDraft.requestResponsible" :options="employeeOptions" placeholder="Ответственные" search-placeholder="Поиск ответственного"/><UiSearchSelect v-model="filterDraft.requestDepartment" :options="clientDepartmentOptions" placeholder="Подразделение" search-placeholder="Поиск подразделения"/><UiSearchSelect v-model="filterDraft.requestTechnology" :options="clientTechnologyOptions" placeholder="Технологии" search-placeholder="Поиск технологии"/></UiFilterBar></div><div v-if="requestMode==='tree'" class="request-tree"><div class="request-head request-grid"><div>Запрос</div><div>Клиент</div><div>Кол-во</div><div>Статус</div><div>Активность</div><div>Ответственный</div></div><template v-for="r in overview.requests" :key="r.id"><div class="request-row request-grid"><div><button class="chev" @click="toggle(expandedRequests,r.id)">{{expandedRequests.has(Number(r.id))?'⌄':'›'}}</button><strong>{{r.title}}</strong><button class="inline-action" @click="openForm('position',{request_id:r.id,quantity:1})">＋ позиция</button></div><div>{{clients.find(c=>c.id===Number(r.client_id))?.name||'#'+r.client_id}}</div><div>{{(r.positions||[]).reduce((s,p)=>s+Number(p.quantity),0)}}</div><div><UiBadge tone="info">{{r.status}}</UiBadge></div><div>{{dateRu(r.deadline)}}</div><div>{{employeeName(r.responsible_employee_id)}}</div></div><template v-if="expandedRequests.has(Number(r.id))" v-for="p in r.positions||[]" :key="p.id"><div class="position-row request-grid"><div><button class="chev" @click="toggle(expandedPositions,p.id)">{{expandedPositions.has(Number(p.id))?'⌄':'›'}}</button>{{p.technology}} {{p.level}}<button class="inline-action" @click="openForm('attempt',{position_id:p.id,responsible_employee_id:r.responsible_employee_id})">＋ попытка</button></div><div>{{p.direction||'—'}}</div><div>{{p.quantity}}</div><div><UiBadge tone="success">{{p.status}}</UiBadge></div><div>{{dateRu(r.deadline)}}</div><div>{{employeeName(r.responsible_employee_id)}}</div></div><div v-if="expandedPositions.has(Number(p.id))" class="attempt-stack"><div v-for="a in p.attempts||[]" :key="a.id" class="attempt-row"><span>{{a.specialist_name}}</span><UiBadge :tone="tone(a.status)">{{a.status}}</UiBadge></div></div></template></template></div><table v-else class="irlix-data-table"><thead><tr><th>Запрос</th><th>Клиент</th><th>Активен до</th><th>Позиции</th><th>Статус</th><th>Ответственный</th></tr></thead><tbody><tr v-for="r in overview.requests" :key="r.id"><td>{{r.title}}</td><td>{{clients.find(c=>c.id===Number(r.client_id))?.name}}</td><td>{{dateRu(r.deadline)}}</td><td>{{r.positions?.length||0}}</td><td>{{r.status}}</td><td>{{employeeName(r.responsible_employee_id)}}</td></tr></tbody></table></template>
      <template v-else-if="view==='positions'"><UiFilterBar><input class="registry-search" type="search" placeholder="Поиск"><UiSearchSelect v-model="filterDraft.positionTechnology" :options="clientTechnologyOptions" placeholder="Технологии" search-placeholder="Поиск технологии"/><UiSearchSelect v-model="filterDraft.positionDirection" :options="directionOptions" placeholder="Направления" search-placeholder="Поиск направления"/><UiSearchSelect v-model="filterDraft.positionClient" :options="clientOptions" placeholder="Клиенты" search-placeholder="Поиск клиента"/><UiSearchSelect v-model="filterDraft.positionStatus" :options="positionStatuses" placeholder="Статусы" search-placeholder="Поиск статуса"/></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Позиция</th><th>Количество</th><th>Направление</th><th>Статус</th><th>Попытки</th><th></th></tr></thead><tbody><tr v-for="p in allPositions" :key="p.id"><td>{{p.technology}} {{p.level}}</td><td>{{p.quantity}}</td><td>{{p.direction||'—'}}</td><td>{{p.status}}</td><td>{{p.attempts?.length||0}}</td><td><button class="inline-action" @click="openForm('attempt',{position_id:p.id,responsible_employee_id:p.responsibleId})">＋ попытка</button></td></tr></tbody></table></template>
      <template v-else-if="view==='attempts'"><UiFilterBar><UiSearchSelect v-model="filterDraft.attemptStatus" :options="attemptStatuses" placeholder="Статусы" search-placeholder="Поиск статуса"/><UiSearchSelect v-model="filterDraft.attemptSpecialist" :options="specialistOptions" placeholder="Специалисты" search-placeholder="Поиск специалиста"/><UiSearchSelect v-model="filterDraft.attemptClient" :options="clientOptions" placeholder="Клиенты" search-placeholder="Поиск клиента"/><UiSearchSelect v-model="filterDraft.attemptResponsible" :options="employeeOptions" placeholder="Ответственные" search-placeholder="Поиск ответственного"/></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Специалист</th><th>Позиция</th><th>Статус</th><th>Контроль</th><th>Ставка</th><th></th></tr></thead><tbody><tr v-for="a in allAttempts" :key="a.id"><td>{{a.specialist_name}}</td><td>{{a.position}}</td><td><select :value="a.status" @change="patch(`/api/clients/attempts/${a.id}`,{status:$event.target.value})"><option v-for="s in attemptStatuses" :key="s">{{s}}</option><option v-if="a.status==='Закрыта: успех'">Закрыта: успех</option></select></td><td>{{dateRu(a.control_date)}}</td><td>{{a.proposed_rate||'—'}}</td><td><UiButton v-if="a.status==='Ожидает подключения'" compact @click="createFromAttempt(a)">Создать подключение</UiButton></td></tr></tbody></table><section class="funnel"><h2>Воронка попыток</h2><div v-for="(s,i) in ['Новая','CV отправлено','Интервью','Ожидает подключения','Закрыта: успех']" :key="s" class="funnel-stage" :style="{width:(100-i*12)+'%'}"><strong>{{s}}</strong><span>{{allAttempts.filter(a=>a.status===s).length}}</span></div><div class="funnel-fail">Закрыта: неудача — {{allAttempts.filter(a=>a.status?.includes('неудач')).length}}</div></section></template>
      <template v-else-if="view==='members'"><UiFilterBar><input class="registry-search" type="search" placeholder="Поиск по сотруднику"><UiSearchSelect v-model="filterDraft.memberClient" :options="clientOptions" placeholder="Клиенты" search-placeholder="Поиск клиента"/><UiSearchSelect v-model="filterDraft.memberProject" :options="projectOptions" placeholder="Проекты" search-placeholder="Поиск проекта"/><UiSearchSelect v-model="filterDraft.memberTechnology" :options="clientTechnologyOptions" placeholder="Технологии" search-placeholder="Поиск технологии"/></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Сотрудник</th><th>Клиент</th><th>Проект</th><th>Условия</th><th>Статус</th></tr></thead><tbody><tr v-for="m in allMembers" :key="m.id" @click="selectedMember=m"><td>{{m.specialist_name}}</td><td>{{m.client}}</td><td>{{m.project}}</td><td>{{memberCurrent(m)?.technology}} / {{memberCurrent(m)?.level}} · {{memberCurrent(m)?.hourly_rate}} ₽/ч · {{memberCurrent(m)?.hours_per_day}} ч/д</td><td>{{memberStatus(m)}}</td></tr></tbody></table></template>
      <template v-else-if="view==='cashflow'"><CashFlowView /></template>
      <ReportingPeriodsView v-else-if="view==='reports'" :periods="overview.reportingPeriods" :clients="clients" :employees="employees" :mode="reportMode" :create-open="reportCreateOpen" @update:create-open="reportCreateOpen=$event" @changed="load" />
      <PermissionsView v-else-if="view==='permissions'" />
    </main>
  </section>
  <ProjectMemberCard :member-id="selectedMember ? Number(selectedMember.id) : null" :employees="employees" :clients="clients" :technology-options="memberTechnologyOptions" :levels="memberLevels" @close="selectedMember=null" @changed="load" />
  <UiDrawer :open="!!selectedLead" :title="selectedLead?`Лид ${selectedLead.name}`:''" @close="selectedLead=null"><template v-if="selectedLead"><div class="info-grid"><b>Название</b><span>{{selectedLead.name}}</span><b>Источник</b><span>{{selectedLead.source||'—'}}</span><b>Ответственный</b><span>{{employeeName(selectedLead.responsible_employee_id)}}</span><b>Статус</b><select :value="selectedLead.status" @change="patch(`/api/clients/leads/${selectedLead.id}`,{status:$event.target.value});selectedLead=null"><option v-for="s in leadStatuses" :key="s">{{s}}</option></select></div><UiButton v-if="selectedLead.status==='Сделка закрыта - Успех'&&!selectedLead.converted_client_id" @click="openForm('convertLead',{lead_id:selectedLead.id,name:selectedLead.name})">Создать клиента</UiButton></template></UiDrawer>
  <ClientCard :client-id="selectedClient ? Number(selectedClient.id) : null" :employees="employees" :clients="clients" :technology-options="clientTechnologyOptions" :accounting="!!accountingEmployeeId" @close="selectedClient=null" @changed="handleClientChanged" />
  <ContactPersonCard :open="contactCardOpen" :contact-id="selectedContactId" :clients="clients" @close="closeContactCard" @changed="handleContactChanged" />
  <UiDrawer :open="!!formKind" :title="formTitle" width="520px" @close="closeForm"><form class="entity-form" @submit.prevent="submit"><div v-if="formError" class="error-banner">{{formError}}</div>
    <template v-if="['client','convertLead'].includes(formKind)"><label>Название<input v-model="form.name" required></label><label v-if="formKind==='client'">Тип<input v-model="form.type"></label><label v-if="formKind==='client'">Сектор<input v-model="form.sector"></label><label v-if="formKind==='client'">Sales<select v-model="form.sales_employee_id"><option value="">—</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Account Manager<select v-model="form.account_employee_id" :disabled="!!accountingEmployeeId" required><option value="">Выберите</option><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label></template>
    <template v-else-if="formKind==='lead'"><label>Название<input v-model="form.name" required></label><label>Источник<input v-model="form.source"></label><label>Ответственный<select v-model="form.responsible_employee_id" required><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Статус<select v-model="form.status"><option v-for="s in leadStatuses" :key="s">{{s}}</option></select></label></template>
    <template v-else-if="formKind==='project'"><label>Название проекта<input v-model="form.name" required></label></template>
    <template v-else-if="formKind==='member'"><section class="member-form-section"><div class="member-form-section__head"><strong>Подключение</strong><span>ProjectMember</span></div><label v-if="form.client_id">Проект<UiSearchSelect v-model="form.project_id" :options="(clients.find(c=>c.id===Number(form.client_id))?.projects||[]).map(p=>({value:String(p.id),label:p.displayName}))" placeholder="Выберите проект" search-placeholder="Поиск проекта" :clearable="false"/></label><label>Специалист<UiSearchSelect v-model="form.specialist_id" :options="specialistTreeOptions" placeholder="Выберите специалиста" search-placeholder="Поиск специалиста или направления" :clearable="false"/></label></section><section class="member-form-section"><div class="member-form-section__head"><strong>Условия / ставка</strong><span>MemberTerms</span></div><label>Технология<UiSearchSelect v-model="form.technology" :options="memberTechnologyOptions" placeholder="Выберите технологию" search-placeholder="Поиск технологии" :clearable="false"/></label><label>Уровень<UiSearchSelect v-model="form.level" :options="memberLevelOptions" placeholder="Выберите уровень" search-placeholder="Поиск уровня" :clearable="false"/></label><label>Ставка, ₽/ч<input v-model="form.hourly_rate" class="form-control" type="number" min="0" step="0.01" required></label><label>Загрузка, ч/д<input v-model="form.hours_per_day" class="form-control" type="number" min="0" step="0.5" required></label><label>Начало<input v-model="form.valid_from" class="form-control" type="date" required></label><label>Окончание<input v-model="form.valid_to" class="form-control" type="date"></label></section></template>
    <template v-else-if="formKind==='terms'"><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Ставка<input v-model="form.hourly_rate" type="number" required></label><label>Загрузка<input v-model="form.hours_per_day" type="number" required></label><label>Начало<input v-model="form.valid_from" type="date" required></label><label>Окончание<input v-model="form.valid_to" type="date"></label></template>
    <template v-else-if="formKind==='request'"><label>Клиент<select v-model="form.client_id" required><option v-for="c in clients" :key="c.id" :value="c.id">{{c.name}}</option></select></label><label>Название<input v-model="form.title" required></label><label>Ответственный<select v-model="form.responsible_employee_id" :disabled="!!accountingEmployeeId" required><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Срок<input v-model="form.deadline" type="date"></label><label>Описание<textarea v-model="form.description"></textarea></label></template>
    <template v-else-if="formKind==='position'"><label>Направление<input v-model="form.direction"></label><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Количество<input v-model="form.quantity" type="number" min="1" required></label><label>Описание<textarea v-model="form.description"></textarea></label></template>
    <template v-else-if="formKind==='attempt'"><label>Специалист<select v-model="form.specialist_id" required><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Ответственный<select v-model="form.responsible_employee_id"><option v-for="e in employees" :key="e.id" :value="e.id">{{e.full_name}}</option></select></label><label>Контрольная дата<input v-model="form.control_date" type="date"></label><label>Предлагаемая ставка<input v-model="form.proposed_rate" type="number"></label></template>
    <template v-else-if="formKind==='report'"><label>Клиент<select v-model="form.client_id" required><option v-for="c in clients" :key="c.id" :value="c.id">{{c.name}}</option></select></label><label>Начало<input v-model="form.period_start" type="date" required></label><label>Конец<input v-model="form.period_end" type="date" required></label></template>
    <div class="form-actions"><UiButton type="submit" :disabled="saving">{{saving?'Сохраняю…':'Сохранить'}}</UiButton><UiButton type="button" variant="secondary" @click="closeForm">Отмена</UiButton></div>
  </form></UiDrawer>
</div>
</template>
