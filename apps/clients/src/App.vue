<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UiAppShell, UiBadge, UiButton, UiDrawer, UiFilterBar, UiPeriodPicker, UiSegmentedControl, UiSearchSelect, UiTreeToggle, UiTooltip, UiViewSwitch } from '@irlix/ui';
import ClientsSidebar from './components/ClientsSidebar.vue';
import { allowedClientSections } from './navigation.js';
import { shortEmployeeName, compactNumber, rateDate, ascendingTerms } from './registryFormat.js';
import ClientsBreadcrumbs from './components/ClientsBreadcrumbs.vue';
import { usePageScrollLock } from './usePageScrollLock';
import ClientCard from './components/ClientCard.vue';
import ClientTransferControl from './components/ClientTransferControl.vue';
import ContactPersonCard from './components/ContactPersonCard.vue';
import ProjectMemberCard from './components/ProjectMemberCard.vue';
import PermissionsView from './components/PermissionsView.vue';
import ReportingPeriodsView from './components/ReportingPeriodsView.vue';
import CashFlowView from './components/CashFlowView.vue';
import RequestsWorkflowView from './components/RequestsWorkflowView.vue';
import RequestClientLogo from './components/RequestClientLogo.vue';
import AttemptFunnelView from './components/AttemptFunnelView.vue';
import LeadsBoard from './components/LeadsBoard.vue';
import LeadCard from './components/LeadCard.vue';

const view = ref('clients');
const loading = ref(false);
const error = ref('');
const query = ref('');
const contactClientFilter = ref('');
const contactCardOpen = ref(false);
const selectedContactId = ref(null);
const connectionScope = ref('active');
const clientFilters = reactive({ account: '', sales: '', technology: '', department: '', activity: 'active' });
const filterDraft = reactive({
  leadResponsible: '', leadStatus: '', requestStatus: '', requestResponsible: '', requestDepartment: '', requestTechnology: '',
  positionTechnology: '', positionDirection: '', positionClient: '', positionStatus: '',
  attemptStatus: '', attemptSpecialist: '', attemptClient: '', attemptResponsible: '',
  memberClient: '', memberProject: '', memberTechnology: '',
});
const overview = reactive({ clients: [], leads: [], contacts: [], requests: [], reportingPeriods: [] });
const employees = ref([]);
const departments = ref([]);
const actorEmployeeId = ref(null);
const accountingEmployeeId = ref(null);
const specialistTechnologies = ref([]);
const expandedClients = reactive(new Set());
const expandedRequests = reactive(new Set());
const expandedPositions = reactive(new Set());
const selectedMember = ref(null);
const selectedLead = ref(null);
const selectedClient = ref(null);
const workflowCardOpen=ref(false);
const requestMode = ref('tree');
const reportMode = ref('kanban');
const attemptMode = ref('kanban');
const cashflowMonth = ref(new Date().toISOString().slice(0, 7));
const formKind = ref('');
const quickLeadOpen=ref(false),quickLeadSaving=ref(false),quickLeadError=ref('');
const quickLeadForm=reactive({name:'',source:'',responsible_employee_id:''});
usePageScrollLock(()=>workflowCardOpen.value||!!selectedClient.value||!!selectedLead.value||!!formKind.value||quickLeadOpen.value);
const form = reactive({});
const formError = ref('');
const saving = ref(false);
const contourAccess = ref({ permissions: {}, platform_admin: false });
const reportCreateOpen = ref(false);

const titles = { clients:'Клиенты', leads:'Лиды', contacts:'Контактные лица', requests:'Запросы', positions:'Запросы по позициям', attempts:'Попытки подключения', 'attempt-funnel':'Воронка попыток', members:'Участники проектов', cashflow:'ДДС', reports:'Отчётные периоды', permissions:'Настройки разрешений' };
const can = key => !!contourAccess.value.permissions?.[key]?.allowed;
const allowedSections = computed(() => allowedClientSections(contourAccess.value));
const attemptModes = computed(() => [
  ...(can('attempts.view') ? ['kanban'] : []),
  ...(can('attempts.analytics.view') ? ['funnel'] : []),
]);
watch(attemptModes, modes => { if (modes.length && !modes.includes(attemptMode.value)) attemptMode.value = modes[0]; }, { immediate:true });
const leadStatuses = ['Новый лид','Первичный контакт','Уточнение потребностей','КП отправлено','Активные переговоры','Клиент в игноре','Сделка закрыта - Успех','Сделка закрыта - Отказ'];
const attemptStatuses = ['Новая','CV отправлено','Интервью','Ожидает подключения','Закрыта: неудача'];
const requestStatuses = ['Открыт','Закрыт'];
const positionStatuses = ['Открыт','В работе','Закрыт'];
const reportStages = ['Новый','ТШ на согласовании','ТШ согласованы','Акт на согласовании','Акт согласован','Счет оплачен'];
const memberLevels = ['Junior','Junior+','Middle','Middle+','Senior','Team Lead','Tech Lead'];
const requestPositionLevels = ['TechLead','TeamLead','Senior','Middle+','Middle','Junior+','Junior'];
const expectedConnectionTimes = ['Неизвестно','Месяц','Квартал','Пол года','Год'];
const acceptableTuFormats = ['Не важно','Штат','Штат / ГПХ'];
const title = computed(() => titles[view.value]);
const employeeMap = computed(() => new Map(employees.value.map(e => [Number(e.id), e.full_name || `#${e.id}`])));
const employeeRecordMap = computed(() => new Map(employees.value.map(e => [Number(e.id), e])));
const employeeName = id => employeeMap.value.get(Number(id)) || (id ? `#${id}` : '—');
const employeeOptions = computed(() => employees.value.map(e => ({ value:String(e.id), label:e.full_name || `#${e.id}` })).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const requestResponsibleOptions=computed(()=>{
  const scope=contourAccess.value.permissions?.['requests.manage']?.scope||'none';
  const ids=scope==='all'?null:new Set((scope==='team'?contourAccess.value.team_employee_ids:[actorEmployeeId.value]).map(Number));
  return employeeOptions.value.filter(option=>ids===null||ids.has(Number(option.value)));
});
const assignableClientServiceIds=computed(()=>new Set((contourAccess.value.assignable_client_service_employee_ids||[actorEmployeeId.value]).map(Number)));
const clientServiceTreeOptions=computed(()=>{
  const allowed=employees.value.filter(employee=>assignableClientServiceIds.value.has(Number(employee.id)));
  const groups=new Map();allowed.forEach(employee=>{const name=employee.department_name||'Без направления';if(!groups.has(name))groups.set(name,[]);groups.get(name).push(employee);});
  return [...groups.entries()].sort(([a],[b])=>a.localeCompare(b,'ru')).flatMap(([name,people])=>[{value:`group:${name}`,label:name,kind:'group'},...people.sort((a,b)=>String(a.full_name).localeCompare(String(b.full_name),'ru')).map(employee=>({value:String(employee.id),label:employee.full_name,depth:1}))]);
});
const responsibleLocked=computed(()=>assignableClientServiceIds.value.size<=1&&!contourAccess.value.platform_admin);
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
const requestTechnologyOptions = computed(() => {
  const technologies = specialistTechnologies.value || [];
  const children = new Map();
  technologies.forEach((technology) => {
    const parentId = technology.parent_id ? Number(technology.parent_id) : null;
    if (!children.has(parentId)) children.set(parentId, []);
    children.get(parentId).push(technology);
  });
  children.forEach(items => items.sort((a,b) => String(a.name).localeCompare(String(b.name), 'ru')));
  const options = [];
  const visited = new Set();
  const walk = (parentId, depth) => (children.get(parentId) || []).forEach((technology) => {
    const id = Number(technology.id);
    if (visited.has(id)) return;
    visited.add(id);
    options.push({ value:technology.name, label:technology.name, depth });
    walk(id, depth + 1);
  });
  walk(null, 0);
  technologies.filter(item => !visited.has(Number(item.id))).forEach(item => options.push({ value:item.name, label:item.name, depth:0 }));
  return options;
});
const productionDirectionOptions = computed(() => departments.value
  .filter(department => department.is_production === true || Number(department.is_production) === 1)
  .map(department => ({ value:String(department.id), label:department.name }))
  .sort((a,b) => a.label.localeCompare(b.label, 'ru')));
const dateRu = value => value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('ru-RU') : '...';
const iso = value => String(value || '').slice(0, 10);
const money = value => `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0))} ₽`;
const latestTerms = member => [...(member.terms || [])].sort((a,b) => String(b.valid_from).localeCompare(String(a.valid_from)))[0] || null;
const memberStartedAt = member => { const dates = (member.terms || []).map(term => iso(term.valid_from)).filter(Boolean).sort(); return dates[0] || null; };

const selectView = (section) => { if (section === 'attempt-funnel') { section = 'attempts'; if (can('attempts.analytics.view')) attemptMode.value = 'funnel'; } if (!titles[section] || !allowedSections.value.includes(section)) return; view.value = section; query.value = ''; };
async function api(url, options = {}) { const response = await fetch(url, { ...options, headers: { Accept:'application/json', 'Content-Type':'application/json', ...(options.headers || {}) } }); const body = await response.json().catch(() => ({})); if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`); return body; }
async function load() {
  loading.value = true; error.value = '';
  try {
    const [directory, data, access] = await Promise.all([api('/api/employees/clients-directory'), api('/api/clients/overview'), api('/api/clients/permissions/me')]);
    contourAccess.value = access.data || { permissions: {} };
    if (!allowedSections.value.includes(view.value)) view.value = allowedSections.value[0] || 'clients';
    if (attemptModes.value.length && !attemptModes.value.includes(attemptMode.value)) attemptMode.value = attemptModes.value[0];
    employees.value = directory.data?.employees || [];
    departments.value = directory.data?.departments || [];
    actorEmployeeId.value = Number(directory.data?.actor?.id) || null;
    accountingEmployeeId.value = directory.data?.accounting ? (Number(directory.data?.actor?.id) || null) : null;
    Object.assign(overview, data.data || {});
    overview.clients = (overview.clients || []).map(c => ({ ...c, id:Number(c.id), projects:(c.projects || []).map(p => ({ ...p, id:Number(p.id), displayName:p.name || 'Основной проект', members:(p.members || []).map(m => ({ ...m, id:Number(m.id), terms:(m.terms || []).map(t => ({ ...t, id:Number(t.id) })) })) })) }));
    overview.leads = overview.leads || []; overview.contacts = overview.contacts || []; overview.requests = overview.requests || []; overview.reportingPeriods = overview.reportingPeriods || [];
    if(selectedLead.value) selectedLead.value=overview.leads.find(item=>Number(item.id)===Number(selectedLead.value.id))||null;
    try { const catalog = await api('/api/specialists/catalog'); specialistTechnologies.value = catalog.data?.technologies || []; } catch { specialistTechnologies.value = []; }
  } catch (e) { error.value = e.message || String(e); } finally { loading.value = false; }
}

const clients = computed(() => overview.clients || []);
const clientOptions = computed(() => clients.value.map(c => ({ value:String(c.id), label:c.name })).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const requestTargetOptions=computed(()=>[
  ...clients.value.map(item=>({value:`client:${item.id}`,label:item.name,meta:'клиент'})),
  ...(overview.leads||[]).filter(item=>!item.converted_client_id).map(item=>({value:`lead:${item.id}`,label:item.name,meta:'лид'})),
  {value:'create-lead',label:'+ Создать нового лида',meta:'действие'},
].sort((a,b)=>a.value==='create-lead'?1:b.value==='create-lead'?-1:a.label.localeCompare(b.label,'ru')));
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
const allVisibleClientsExpanded = computed(() => filteredClients.value.length > 0 && filteredClients.value.every(client => expandedClients.has(client.id)));
function toggleVisibleClients() {
  const collapse = allVisibleClientsExpanded.value;
  filteredClients.value.forEach(client => collapse ? expandedClients.delete(client.id) : expandedClients.add(client.id));
}
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
const allAttempts = computed(() => (overview.requests || []).flatMap(r => (r.positions || []).flatMap(p => (p.attempts || []).map(a => ({ ...a, clientId:Number(r.client_id), request:r.title, positionId:p.id, position:`${p.technology || 'Технология не указана'} ${p.level}`, responsibleId:r.responsible_employee_id })))));
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
function localDateValue(date = new Date()) { const offset = date.getTimezoneOffset(); return new Date(date.getTime() - offset * 60000).toISOString().slice(0,10); }
function emptyRequestPosition() { return { technology:'', direction_department_id:'', level:'', quantity:1, expected_connection_time:'Неизвестно', acceptable_tu_format:'Не важно', description:'', responsible_rn_employee_id:'' }; }
const productionManagerOptions=computed(()=>[...new Set(departments.value.filter(d=>d.is_production===true||Number(d.is_production)===1).map(d=>Number(d.manager_id)).filter(Boolean))].map(id=>({value:String(id),label:employeeName(id)})).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
function assignRequestPositionManager(position){position.responsible_rn_employee_id=String(departments.value.find(d=>Number(d.id)===Number(position.direction_department_id))?.manager_id||'');}
function addRequestPosition() { form.positions.unshift(emptyRequestPosition()); }
function removeRequestPosition(index) { if (form.positions.length > 1) form.positions.splice(index, 1); }
function openForm(kind, initial={}) {
  formKind.value = kind; formError.value = '';
  Object.keys(form).forEach(k => delete form[k]);
  Object.assign(form, { specialist_type:'employee', specialist_name:'' }, initial);
  const accountActor = (contourAccess.value.roles||[]).some(role=>['account-manager','accounting-head','client-service-head'].includes(role));
  if (kind === 'request') Object.assign(form, { responsible_employee_id:String(actorEmployeeId.value || ''), request_date:localDateValue(), lifetime_weeks:'1', description:'', positions:[emptyRequestPosition()] }, initial);
  if (kind === 'lead') Object.assign(form,{responsible_employee_id:String(actorEmployeeId.value||'')},initial);
  if (kind === 'client' && !form.sales_employee_id) form.sales_employee_id=String(actorEmployeeId.value||'');
  if (kind === 'client' && !form.account_employee_id) form.account_employee_id=accountActor?String(actorEmployeeId.value||''):'';
}
function closeForm() { formKind.value = ''; formError.value = ''; }
const formTitle = computed(() => ({ client:'Новый клиент', convertLead:'Создать клиента из лида', lead:'Новый лид', project:'Новый проект', member:'Новое подключение', terms:'Новые условия', request:'Новый запрос', position:'Новая позиция', report:'Новый отчётный период' }[formKind.value] || ''));
const requestFormComplete = computed(() => formKind.value !== 'request' || Boolean(
  String(form.title || '').trim()
  && /^(client|lead):\d+$/.test(String(form.target_id||''))
  && Number(form.responsible_employee_id) > 0
  && form.request_date
  && [1,2,3,4].includes(Number(form.lifetime_weeks))
  && Array.isArray(form.positions)
  && form.positions.length > 0
  && form.positions.every(position => String(position.technology || '').trim() && String(position.direction_department_id || '').trim() && String(position.level || '').trim() && Number.isInteger(Number(position.quantity)) && Number(position.quantity) > 0)
));
async function submit() {
  saving.value = true; formError.value = '';
  try {
    if (formKind.value === 'client') await api('/api/clients/clients', { method:'POST', body:JSON.stringify({ name:form.name, type:form.type||null, sector:form.sector||null, sales_employee_id:Number(form.sales_employee_id)||null, account_employee_id:Number(form.account_employee_id)||null }) });
    if (formKind.value === 'convertLead') await api(`/api/clients/leads/${form.lead_id}/convert`, { method:'POST', body:JSON.stringify({ name:form.name,type:form.type||null,sector:form.sector||null,sales_employee_id:Number(form.sales_employee_id)||null, account_employee_id:Number(form.account_employee_id)||null }) });
    let createdLead=null;
    if (formKind.value === 'lead') createdLead=(await api('/api/clients/leads', { method:'POST', body:JSON.stringify({ name:form.name, source:form.source||null, responsible_employee_id:Number(form.responsible_employee_id) }) })).data;
    if (formKind.value === 'project') await api(`/api/clients/clients/${form.client_id}/projects`, { method:'POST', body:JSON.stringify({ name:form.name }) });
    if (formKind.value === 'member') { const e = employees.value.find(x => Number(x.id) === Number(form.specialist_id)); await api(`/api/clients/projects/${form.project_id}/members`, { method:'POST', body:JSON.stringify({ is_external:form.specialist_type==='partner', specialist_id:form.specialist_type==='partner'?null:Number(form.specialist_id), specialist_name:form.specialist_type==='partner'?String(form.specialist_name||'').trim():e?.full_name || `#${form.specialist_id}`, source_attempt_id:form.source_attempt_id?Number(form.source_attempt_id):null, technology:form.technology, level:form.level, hourly_rate:Number(form.hourly_rate), hours_per_day:Number(form.hours_per_day), valid_from:form.valid_from, valid_to:form.valid_to||null }) }); }
    if (formKind.value === 'terms') await api(`/api/clients/members/${form.member_id}/terms`, { method:'POST', body:JSON.stringify({ technology:form.technology, level:form.level, hourly_rate:Number(form.hourly_rate), hours_per_day:Number(form.hours_per_day), valid_from:form.valid_from, valid_to:form.valid_to||null }) });
    if (formKind.value === 'request') {const [targetType,targetId]=String(form.target_id).split(':');await api('/api/clients/requests', { method:'POST', body:JSON.stringify({ client_id:targetType==='client'?Number(targetId):null,lead_id:targetType==='lead'?Number(targetId):null, title:form.title, description:form.description||null, responsible_employee_id:Number(form.responsible_employee_id), request_date:form.request_date, lifetime_weeks:Number(form.lifetime_weeks), positions:form.positions.map(position => ({ ...position, direction_department_id:Number(position.direction_department_id), direction:departments.value.find(item=>Number(item.id)===Number(position.direction_department_id))?.name || '', quantity:Number(position.quantity), responsible_rn_employee_id:Number(position.responsible_rn_employee_id)||null })) }) });}
    if (formKind.value === 'position') await api(`/api/clients/requests/${form.request_id}/positions`, { method:'POST', body:JSON.stringify({ direction:form.direction||null, technology:form.technology, level:form.level, quantity:Number(form.quantity||1), expected_connection_time:form.expected_connection_time||'Неизвестно', acceptable_tu_format:form.acceptable_tu_format||'Не важно', description:form.description||null }) });

    if (formKind.value === 'report') await api('/api/clients/reporting-periods', { method:'POST', body:JSON.stringify({ client_id:Number(form.client_id), period_start:form.period_start, period_end:form.period_end }) });
    closeForm(); await load(); if(createdLead) selectedLead.value=overview.leads.find(item=>Number(item.id)===Number(createdLead.id))||createdLead;
  } catch (e) { formError.value = e.message || String(e); } finally { saving.value = false; }
}
function handleRequestTarget(value){if(value!=='create-lead')return;form.target_id='';Object.assign(quickLeadForm,{name:'',source:'',responsible_employee_id:String(actorEmployeeId.value||'')});quickLeadError.value='';quickLeadOpen.value=true;}
async function createQuickLead(){quickLeadSaving.value=true;quickLeadError.value='';try{const result=await api('/api/clients/leads',{method:'POST',body:JSON.stringify({name:quickLeadForm.name,source:quickLeadForm.source||null,responsible_employee_id:Number(quickLeadForm.responsible_employee_id)})});await load();form.target_id=`lead:${result.data.id}`;quickLeadOpen.value=false;}catch(e){quickLeadError.value=e.message||String(e);}finally{quickLeadSaving.value=false;}}
async function patch(url, payload) { try { await api(url, { method:'PATCH', body:JSON.stringify(payload) }); await load(); } catch (e) { error.value = e.message; } }
async function moveLead({lead,status}) { try { lead.status=status; await api(`/api/clients/leads/${lead.id}`,{method:'PATCH',body:JSON.stringify({status})}); await load(); } catch(e) { error.value=e.message||String(e); await load(); } }
function createFromAttempt(a) { const client = clients.value.find(c => c.id === Number(a.clientId)); const project = client?.projects?.[0]; openForm('member', { client_id:client?.id, project_id:project?.id, specialist_type:a.is_external?'partner':'employee', specialist_name:a.specialist_name, specialist_id:a.specialist_id, source_attempt_id:a.id, hourly_rate:'', hours_per_day:8 }); }
onMounted(load);
</script>

<template>
<UiAppShell class="clients-app" service="clients" service-name="Клиентский сервис" :breadcrumbs="[{label:title}]" :loading="loading">
  <template #sidebar><ClientsSidebar :section="view" :allowed-sections="allowedSections" @update:section="selectView" /></template>
  <template #breadcrumb-extra><ClientsBreadcrumbs :view="view" :request-mode="requestMode" :report-mode="reportMode" :attempt-mode="attemptMode" :attempt-modes="attemptModes" @update:attempt-mode="attemptMode = $event" @update:request-mode="requestMode = $event" @update:report-mode="reportMode = $event" /></template>
  <template #actions>
        <UiButton v-if="view==='clients' && can('clients.manage')" @click="openForm('client')">＋ Новый клиент</UiButton>
        <UiButton v-if="view==='leads' && can('leads.manage')" @click="openForm('lead')">＋ Новый лид</UiButton>
        <UiButton v-if="view==='contacts' && can('contacts.manage')" @click="openContactCard()">＋ Новый контакт</UiButton>
        <UiButton v-if="view==='requests' && can('requests.manage')" @click="openForm('request')">＋ Новый запрос</UiButton>
        <UiPeriodPicker v-if="view==='cashflow'" v-model="cashflowMonth" mode="month" />
        <UiButton v-if="view==='reports' && can('reports.manage')" @click="reportCreateOpen=true">＋ Новый отчётный период</UiButton>
      </template>
    <main class="content" :class="{'content--clients':view==='clients','content--cashflow':view==='cashflow'}">
      <div v-if="error" class="error-banner">{{error}}<button @click="load">Повторить</button></div>
      <template v-if="view==='clients'">
        <div class="clients-registry">
          <div class="clients-registry-controls">
            <UiFilterBar class="clients-filter-bar"><input v-model="query" class="registry-search" type="search" placeholder="Поиск по клиентам"><UiSearchSelect v-model="clientFilters.account" :options="clientAccountOptions" placeholder="Аккаунты" search-placeholder="Поиск аккаунта"/><UiSearchSelect v-model="clientFilters.sales" :options="clientSalesOptions" placeholder="Сейлзы" search-placeholder="Поиск сейлза"/><UiSearchSelect v-model="clientFilters.technology" :options="clientTechnologyOptions" placeholder="Технологии" search-placeholder="Поиск технологии"/><UiSearchSelect v-model="clientFilters.department" :options="clientDepartmentOptions" placeholder="Подразделения" search-placeholder="Поиск подразделения"/><UiSearchSelect v-model="clientFilters.activity" :options="[{value:'active',label:'Активные'},{value:'inactive',label:'Неактивные'}]" placeholder="Активность" search-placeholder="Поиск"/><UiSearchSelect v-model="connectionScope" :options="[{value:'active',label:'Активные подключения'},{value:'all',label:'Все подключения'}]" placeholder="Подключения" search-placeholder="Поиск" :clearable="false" aria-label="Подключения"/></UiFilterBar>
            <div class="count">{{filteredClients.length}} клиентов</div>
          </div>
          <div class="client-table">
            <div class="client-head client-grid"><div class="client-name-cell client-name-cell--head"><span class="client-logo-spacer" aria-hidden="true"/><UiTreeToggle :expanded="allVisibleClientsExpanded" :disabled="!filteredClients.length" :label="allVisibleClientsExpanded ? 'Свернуть всех видимых клиентов' : 'Развернуть всех видимых клиентов'" @click="toggleVisibleClients"/><span>Клиент</span></div><div class="client-managers">Аккаунт / Сейлз</div></div>
            <div v-for="c in filteredClients" :key="c.id" class="client-tree-group" :class="{'client-tree-group--expanded':expandedClients.has(c.id)&&visibleClientMemberCount(c)>0}">
              <div class="client-row client-grid" @click="selectedClient=c"><div class="client-name-cell"><RequestClientLogo :request="{title:c.name}" :client="c"/><UiTreeToggle :expanded="expandedClients.has(c.id)" :label="expandedClients.has(c.id)?'Свернуть клиента':'Развернуть клиента'" @click.stop="toggle(expandedClients,c.id)" /><strong>{{c.name}}</strong><span class="client-meta-tags"><UiBadge v-if="c.sector" tone="meta">{{c.sector}}</UiBadge><UiBadge v-if="c.type" tone="meta">{{c.type}}</UiBadge></span><button v-if="expandedClients.has(c.id)&&c.projects.length" class="project-member-add" @click.stop="openForm('member',{client_id:c.id,project_id:c.projects.find(p=>p.is_default)?.id||c.projects[0].id,hours_per_day:8})">＋ участник</button></div><div class="client-managers">{{shortEmployeeName(employeeName(c.account_employee_id))}} / {{shortEmployeeName(employeeName(c.sales_employee_id))}}</div></div>
              <template v-if="expandedClients.has(c.id)">
                <div v-if="visibleClientMemberCount(c)>0" class="client-members">
                  <div class="member-header member-grid member-header--client"><div>Специалист</div><div>Проект</div><div>Последняя ставка</div><div>Технология / уровень</div><div>Ставка, руб/ч</div><div>Загрузка, ч/д</div><div>Статус</div></div>
                  <template v-for="p in c.projects" :key="p.id"><div v-for="m in visibleProjectMembers(p)" :key="m.id" class="member-row member-grid member-row--client" @click="selectedMember={...m,client:c.name,project:p.displayName,sales:employeeName(c.sales_employee_id)}"><div class="tree-child tree-child--client-member">{{m.specialist_name}}</div><div>{{p.displayName}}</div><div><UiTooltip>{{dateRu(memberDisplayTerms(m)?.valid_from)}} — {{memberDisplayTerms(m)?.valid_to?dateRu(memberDisplayTerms(m)?.valid_to):'по н.в.'}}<template #content><div class="ui-tooltip-table"><div v-for="term in ascendingTerms(m)" :key="term.id" class="ui-tooltip-table__row"><span>{{rateDate(term.valid_from)}} - {{rateDate(term.valid_to)}}</span><span class="technology-grade ui-grade">{{term.technology||'—'}}<sup v-if="term.level">{{term.level}}</sup><span v-else>—</span></span><span>{{compactNumber(term.hourly_rate)}}р/ч</span></div></div><span v-if="!m.terms?.length">Ставки не указаны</span></template></UiTooltip></div><div class="technology-grade ui-grade"><span>{{memberDisplayTerms(m)?.technology||'—'}}</span><sup v-if="memberDisplayTerms(m)?.level">{{memberDisplayTerms(m).level}}</sup></div><div>{{memberDisplayTerms(m)?.hourly_rate||'—'}}</div><div>{{compactNumber(memberDisplayTerms(m)?.hours_per_day)}}</div><div><UiBadge :tone="memberStatus(m)==='На проекте'?'success':'neutral'">{{memberStatus(m)}}</UiBadge></div></div></template>
                </div>
                <div v-else class="client-members-empty">Нет сотрудников, подходящих под выбранные фильтры</div>
              </template>
            </div>
          </div>
        </div>
      </template>
      <LeadsBoard v-else-if="view==='leads'" :leads="overview.leads" :employees="employees" :statuses="leadStatuses" :can-manage="can('leads.manage')" @open="selectedLead=$event" @move="moveLead"/>
      <template v-else-if="view==='contacts'"><UiFilterBar><input v-model="query" class="registry-search" type="search" placeholder="Поиск по ФИО"><UiSearchSelect v-model="contactClientFilter" :options="clientOptions" placeholder="Клиенты" search-placeholder="Поиск клиента"/></UiFilterBar><table class="irlix-data-table"><thead><tr><th>ФИО</th><th>Клиенты / должности</th></tr></thead><tbody><tr v-for="c in filteredContacts" :key="c.id" @click="openContactCard(c)"><td><strong>{{c.full_name}}</strong></td><td>{{contactBindingsText(c)}}</td></tr></tbody></table></template>
      <RequestsWorkflowView v-else-if="['requests','positions'].includes(view) || (view==='attempts' && attemptMode==='kanban' && can('attempts.view'))" :view="view" :mode="requestMode" :requests="overview.requests" :clients="clients" :leads="overview.leads" :employees="employees" :departments="departments" :access="contourAccess" :background-blocked="!!selectedClient||!!formKind" @card-open="workflowCardOpen=$event" @open-client="id=>selectedClient=clients.find(client=>Number(client.id)===Number(id))||{id}" :technology-options="requestTechnologyOptions" :direction-options="productionDirectionOptions" :level-options="requestPositionLevels" @changed="load" @connect-attempt="createFromAttempt" />
      <AttemptFunnelView v-else-if="view==='attempts' && attemptMode==='funnel' && can('attempts.analytics.view')" />
      <template v-else-if="view==='members'"><UiFilterBar><input class="registry-search" type="search" placeholder="Поиск по сотруднику"><UiSearchSelect v-model="filterDraft.memberClient" :options="clientOptions" placeholder="Клиенты" search-placeholder="Поиск клиента"/><UiSearchSelect v-model="filterDraft.memberProject" :options="projectOptions" placeholder="Проекты" search-placeholder="Поиск проекта"/><UiSearchSelect v-model="filterDraft.memberTechnology" :options="clientTechnologyOptions" placeholder="Технологии" search-placeholder="Поиск технологии"/></UiFilterBar><table class="irlix-data-table"><thead><tr><th>Сотрудник</th><th>Клиент</th><th>Проект</th><th>Условия</th><th>Статус</th></tr></thead><tbody><tr v-for="m in allMembers" :key="m.id" @click="selectedMember=m"><td>{{m.specialist_name}}</td><td>{{m.client}}</td><td>{{m.project}}</td><td>{{memberCurrent(m)?.technology}} / {{memberCurrent(m)?.level}} · {{memberCurrent(m)?.hourly_rate}} ₽/ч · {{memberCurrent(m)?.hours_per_day}} ч/д</td><td>{{memberStatus(m)}}</td></tr></tbody></table></template>
      <template v-else-if="view==='cashflow'"><CashFlowView v-model:month="cashflowMonth" /></template>
      <ReportingPeriodsView v-else-if="view==='reports'" :periods="overview.reportingPeriods" :clients="clients" :employees="employees" :mode="reportMode" :create-open="reportCreateOpen" @update:create-open="reportCreateOpen=$event" @changed="load" />
      <PermissionsView v-else-if="view==='permissions'" />
    </main>
  <ProjectMemberCard :member-id="selectedMember ? Number(selectedMember.id) : null" :employees="employees" :clients="clients" :technology-options="memberTechnologyOptions" :levels="memberLevels" @close="selectedMember=null" @changed="load" />
  <LeadCard :lead="selectedLead" :employees="employees" :contacts="overview.contacts" :requests="overview.requests" :statuses="leadStatuses" :can-manage="can('leads.manage')" @close="selectedLead=null" @changed="load" @create-request="lead=>openForm('request',{target_id:`lead:${lead.id}`})" @convert="lead=>openForm('convertLead',{lead_id:lead.id,name:lead.name,sales_employee_id:String(lead.responsible_employee_id),account_employee_id:''})"/>
  <ClientCard :z-index="workflowCardOpen?1150:500" :client-id="selectedClient ? Number(selectedClient.id) : null" :employees="employees" :account-options="clientServiceTreeOptions" :clients="clients" :technology-options="clientTechnologyOptions" :can-manage="selectedClient?.can_manage!==false&&can('clients.manage')" @close="selectedClient=null" @changed="handleClientChanged" />
  <ClientTransferControl v-if="selectedClient && selectedClient?.can_manage!==false&&can('clients.manage')&&!selectedClient?.account_employee_id" :client-id="Number(selectedClient.id)" :options="clientServiceTreeOptions" @changed="handleClientChanged" />
  <ContactPersonCard :open="contactCardOpen" :contact-id="selectedContactId" :clients="clients" @close="closeContactCard" @changed="handleContactChanged" />
  <UiDrawer :open="!!formKind" :title="formTitle" :width="formKind==='request'?'680px':'520px'" :z-index="1200" @close="closeForm"><form class="entity-form" :class="{'request-create-form':formKind==='request'}" @submit.prevent="submit"><div v-if="formError" class="error-banner">{{formError}}</div>
    <template v-if="['client','convertLead'].includes(formKind)"><label>Название<input v-model="form.name" required></label><label>Тип<input v-model="form.type"></label><label>Сектор<input v-model="form.sector"></label><label>Sales<UiSearchSelect v-model="form.sales_employee_id" :options="clientServiceTreeOptions" :disabled="responsibleLocked" placeholder="Выберите Sales" :clearable="false"/></label><label>Account Manager<UiSearchSelect v-model="form.account_employee_id" :options="clientServiceTreeOptions" :disabled="responsibleLocked" placeholder="Можно назначить позже"/></label></template>
    <template v-else-if="formKind==='lead'"><label>Название<input v-model="form.name" required></label><label>Источник<input v-model="form.source"></label><label>Ответственный<UiSearchSelect v-model="form.responsible_employee_id" :options="clientServiceTreeOptions" :disabled="responsibleLocked" :clearable="false" placeholder="Ответственный"/></label></template>
    <template v-else-if="formKind==='project'"><label>Название проекта<input v-model="form.name" required></label></template>
    <template v-else-if="formKind==='member'"><section class="member-form-section"><div class="member-form-section__head"><strong>Подключение</strong><span>ProjectMember</span></div><label v-if="form.client_id">Проект<UiSearchSelect v-model="form.project_id" :options="(clients.find(c=>c.id===Number(form.client_id))?.projects||[]).map(p=>({value:String(p.id),label:p.displayName}))" placeholder="Выберите проект" search-placeholder="Поиск проекта" :clearable="false"/></label><UiSegmentedControl v-model="form.specialist_type" :items="[{value:'employee',label:'Сотрудник'},{value:'partner',label:'Партнерский специалист'}]" aria-label="Тип специалиста" @update:model-value="form.specialist_id='';form.specialist_name=''"/><label v-if="form.specialist_type==='employee'">Сотрудник<UiSearchSelect v-model="form.specialist_id" :options="specialistTreeOptions" placeholder="Выберите специалиста" search-placeholder="Поиск специалиста или направления" :clearable="false"/></label><label v-else>ФИО партнерского специалиста<input v-model="form.specialist_name" class="form-control" maxlength="255" required placeholder="Введите ФИО"></label></section><section class="member-form-section"><div class="member-form-section__head"><strong>Условия / ставка</strong><span>MemberTerms</span></div><label>Технология<UiSearchSelect v-model="form.technology" :options="memberTechnologyOptions" placeholder="Выберите технологию" search-placeholder="Поиск технологии" :clearable="false"/></label><label>Уровень<UiSearchSelect v-model="form.level" :options="memberLevelOptions" placeholder="Выберите уровень" search-placeholder="Поиск уровня" :clearable="false"/></label><label>Ставка, ₽/ч<input v-model="form.hourly_rate" class="form-control" type="number" min="0" step="0.01" required></label><label>Загрузка, ч/д<input v-model="form.hours_per_day" class="form-control" type="number" min="0" step="0.5" required></label><label>Начало<input v-model="form.valid_from" class="form-control" type="date" required></label><label>Окончание<input v-model="form.valid_to" class="form-control" type="date"></label></section></template>
    <template v-else-if="formKind==='terms'"><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Ставка<input v-model="form.hourly_rate" type="number" required></label><label>Загрузка<input v-model="form.hours_per_day" type="number" required></label><label>Начало<input v-model="form.valid_from" type="date" required></label><label>Окончание<input v-model="form.valid_to" type="date"></label></template>
    <template v-else-if="formKind==='request'">
      <section class="request-main-fields">
        <label>Название<input v-model="form.title" required></label>
        <label>Клиент / лид<UiSearchSelect v-model="form.target_id" :options="requestTargetOptions" placeholder="Выберите клиента или лида" search-placeholder="Поиск клиента или лида" :clearable="false" @change="handleRequestTarget"/></label>
        <label>Ответственный за запрос<UiSearchSelect v-model="form.responsible_employee_id" :options="requestResponsibleOptions" :disabled="requestResponsibleOptions.length===1" placeholder="Ответственный" :clearable="false"/></label>
        <div class="request-date-grid"><label>Дата запроса<input v-model="form.request_date" type="date" required></label><label>Время жизни<UiSearchSelect v-model="form.lifetime_weeks" :options="[1,2,3,4].map(value=>({value:String(value),label:`${value} ${value===1?'неделя':'недели'}`}))" :clearable="false"/></label></div>
        <label>Описание запроса<textarea v-model="form.description" rows="3"></textarea></label>
      </section>
      <section class="request-positions-create">
        <div class="request-positions-head"><div><strong>Позиции</strong><small>Минимум одна позиция в запросе</small></div><button class="text-action" type="button" @click="addRequestPosition">+ позиция</button></div>
        <article v-for="(position,index) in form.positions" :key="index" class="request-position-form">
          <header><strong>Позиция {{index+1}}</strong><button v-if="form.positions.length>1" type="button" aria-label="Удалить позицию" @click="removeRequestPosition(index)">×</button></header>
          <div class="request-position-grid">
            <label>Технология<UiSearchSelect v-model="position.technology" :options="requestTechnologyOptions" placeholder="Не выбрано" search-placeholder="Поиск технологии" :clearable="false"/></label>
            <label>Направление<UiSearchSelect v-model="position.direction_department_id" :options="productionDirectionOptions" @change="assignRequestPositionManager(position)" placeholder="Не выбрано" search-placeholder="Поиск направления" :clearable="false"/></label>
            <label>Ответственный РН<UiSearchSelect v-model="position.responsible_rn_employee_id" :options="productionManagerOptions" placeholder="Руководитель выбранного направления"/></label>
            <label>Уровень<UiSearchSelect v-model="position.level" :options="requestPositionLevels.map(value=>({value,label:value}))" placeholder="Не выбрано" search-placeholder="Поиск уровня" :clearable="false"/></label>
            <label>Количество<input v-model="position.quantity" type="number" min="1" step="1" required></label>
            <label>Ожидаемое время подключения<UiSearchSelect v-model="position.expected_connection_time" :options="expectedConnectionTimes.map(value=>({value,label:value}))" :clearable="false"/></label>
            <label>Допустимый формат ТУ<UiSearchSelect v-model="position.acceptable_tu_format" :options="acceptableTuFormats.map(value=>({value,label:value}))" :clearable="false"/></label>
          </div>
          <label>Описание позиции<textarea v-model="position.description" rows="2"></textarea></label>
        </article>
      </section>
    </template>
    <template v-else-if="formKind==='position'"><label>Направление<input v-model="form.direction"></label><label>Технология<input v-model="form.technology" required></label><label>Уровень<input v-model="form.level" required></label><label>Количество<input v-model="form.quantity" type="number" min="1" required></label><label>Ожидаемое время подключения<select v-model="form.expected_connection_time"><option v-for="value in expectedConnectionTimes" :key="value">{{value}}</option></select></label><label>Допустимый формат ТУ<select v-model="form.acceptable_tu_format"><option v-for="value in acceptableTuFormats" :key="value">{{value}}</option></select></label><label>Описание<textarea v-model="form.description"></textarea></label></template>

    <template v-else-if="formKind==='report'"><label>Клиент<select v-model="form.client_id" required><option v-for="c in clients" :key="c.id" :value="c.id">{{c.name}}</option></select></label><label>Начало<input v-model="form.period_start" type="date" required></label><label>Конец<input v-model="form.period_end" type="date" required></label></template>
    <div class="form-actions"><UiButton type="submit" :disabled="saving||!requestFormComplete">{{saving?'Сохраняю…':'Сохранить'}}</UiButton><UiButton type="button" variant="secondary" @click="closeForm">Отмена</UiButton></div>
  </form></UiDrawer>
  <UiDrawer :open="quickLeadOpen" title="Новый лид" width="480px" :z-index="1250" @close="quickLeadOpen=false"><form class="entity-form lead-create-form" @submit.prevent="createQuickLead"><div v-if="quickLeadError" class="error-banner">{{quickLeadError}}</div><label>Название<input v-model="quickLeadForm.name" required></label><label>Источник<input v-model="quickLeadForm.source"></label><label>Ответственный<UiSearchSelect v-model="quickLeadForm.responsible_employee_id" :options="clientServiceTreeOptions" :disabled="responsibleLocked" :clearable="false"/></label><div class="form-actions"><UiButton type="submit" :disabled="quickLeadSaving">{{quickLeadSaving?'Сохраняю…':'Создать'}}</UiButton><UiButton type="button" variant="secondary" @click="quickLeadOpen=false">Отмена</UiButton></div></form></UiDrawer>
</UiAppShell>
</template>

<style scoped>
.request-create-form{gap:10px}.request-main-fields{display:grid;gap:9px}.request-date-grid,.request-position-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}.request-create-form label{display:grid;gap:4px;color:var(--irlix-color-text);font-size:var(--irlix-font-size-caption);font-weight:600}.request-create-form input,.request-create-form textarea{width:100%;min-height:var(--irlix-control-height,32px);padding:0 var(--irlix-control-padding-x,12px);border:1px solid var(--irlix-control-border,var(--irlix-color-border));border-radius:var(--irlix-control-radius,10px);background:var(--irlix-color-surface);color:var(--irlix-control-text,var(--irlix-color-text));font:inherit;outline:0;box-shadow:none}.request-create-form textarea{padding-top:8px;padding-bottom:8px}.request-create-form input:focus,.request-create-form textarea:focus{border-color:var(--irlix-color-primary);box-shadow:0 0 0 2px color-mix(in srgb, var(--irlix-color-shadow-base) 10%, transparent)}.request-positions-create{display:grid;gap:8px;padding-top:10px;border-top:1px solid var(--irlix-color-border)}.request-positions-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.request-positions-head>div{display:grid;gap:2px}.request-positions-head strong{font-size:var(--irlix-font-size-table);color:var(--irlix-color-text)}.request-positions-head small{font-size:var(--irlix-font-size-caption);color:var(--irlix-color-text-muted)}.request-positions-head .text-action{border:0;background:transparent;color:var(--irlix-color-primary-text);font-size:var(--irlix-font-size-caption);font-weight:600;cursor:pointer}.request-position-form{display:grid;gap:8px;padding:10px;border:1px solid var(--irlix-color-border);border-radius:10px;background:var(--irlix-color-surface-muted)}.request-position-form header{display:flex;align-items:center;justify-content:space-between}.request-position-form header strong{font-size:var(--irlix-font-size-caption);color:var(--irlix-color-text)}.request-position-form header button{width:24px;height:24px;padding:0;border:0;border-radius:6px;background:transparent;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-section-title);cursor:pointer}.request-position-form header button:hover{background:var(--irlix-color-surface-muted);color:var(--irlix-status-danger-text)}.request-position-form textarea{min-height:54px!important;resize:vertical}@media(max-width:720px){.request-date-grid,.request-position-grid{grid-template-columns:1fr}}
</style>
