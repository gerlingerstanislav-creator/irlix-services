<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from './auth';
import { api } from './api';

const section = ref('mine');
const month = ref(new Date().toISOString().slice(0, 7));
const workspace = ref(null);
const management = ref(null);
const analytics = ref(null);
const auditRows = ref([]);
const loading = ref(false);
const message = ref('');
const error = ref('');
const selectedDate = ref(new Date().toISOString().slice(0, 10));
const editModal = ref(null);
const analyticsMode = ref('employees');
const search = ref('');
const departmentFilter = ref('');
const projectFilter = ref('');

const menuItems = [
  { id: 'mine', icon: 'calendar', label: 'Мои таймшиты' },
  { id: 'management', icon: 'manage', label: 'Управление' },
  { id: 'analytics', icon: 'chart', label: 'Коммерческая загрузка' },
];
const bottomItems = [{ id: 'audit', icon: 'audit', label: 'История действий' }];
const pad = (n) => String(n).padStart(2, '0');
const monthDate = computed(() => new Date(`${month.value}-01T00:00:00`));
const monthLabel = computed(() => new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric' }).format(monthDate.value));
const monthDays = computed(() => {
  const d = monthDate.value;
  const count = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
  return Array.from({ length: count }, (_, i) => `${month.value}-${pad(i + 1)}`);
});
const calendarCells = computed(() => {
  const first = new Date(`${month.value}-01T00:00:00`);
  const lead = (first.getDay() + 6) % 7;
  return [...Array(lead).fill(null), ...monthDays.value];
});
const selectedDay = computed(() => Number(selectedDate.value?.slice(-2) || 1));

const toast = (text, bad = false) => {
  if (bad) { error.value = text; message.value = ''; } else { message.value = text; error.value = ''; }
  setTimeout(() => { if (bad) error.value = ''; else message.value = ''; }, 4500);
};

const changeMonth = (delta) => {
  const d = new Date(`${month.value}-01T00:00:00`);
  d.setMonth(d.getMonth() + delta);
  month.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
};

const entriesFor = (date, source = workspace.value) => (source?.entries || []).filter((e) => e.work_date === date);
const activeAssignments = (date, source = workspace.value) => (source?.assignments || []).filter((a) => a.valid_from <= date && (!a.valid_to || a.valid_to >= date));
const dayHours = (date, source = workspace.value) => entriesFor(date, source).reduce((s, e) => s + Number(e.hours || 0), 0);
const prelimConfirmed = (date, source = workspace.value) => {
  const c = source?.confirmations || {};
  return Array.isArray(c) ? c.some((x) => x.work_date === date) : Boolean(c?.[date]);
};
const finalProjectIds = (source) => new Set((source?.final_approvals || []).map((a) => Number(a.project_id)));
const dayFinal = (date, source = workspace.value) => {
  const assignments = activeAssignments(date, source);
  if (!assignments.length) return false;
  const approved = finalProjectIds(source);
  return assignments.every((a) => approved.has(Number(a.project_id)));
};
const absenceFor = (date, employeeId, source) => (source?.absences || []).find((a) => Number(a.employee_id) === Number(employeeId) && a.starts_on <= date && a.ends_on >= date);
const mineAbsence = (date) => absenceFor(date, workspace.value?.employee?.id, workspace.value);
const dayClass = (date) => {
  const absence = mineAbsence(date);
  if (absence?.status === 'confirmed') return 'absence-confirmed';
  if (absence) return 'absence-pending';
  if (dayFinal(date)) return 'final';
  if (prelimConfirmed(date)) return 'prelim';
  if (!activeAssignments(date).length) return 'inactive';
  return '';
};
const isLockedProject = (projectId) => workspace.value?.period_locked || finalProjectIds(workspace.value).has(Number(projectId));

const loadMine = async () => {
  const { data } = await api(`/api/timesheets/workspace?month=${month.value}`);
  workspace.value = data;
  if (!selectedDate.value.startsWith(month.value)) selectedDate.value = `${month.value}-01`;
};
const loadManagement = async () => { management.value = (await api(`/api/timesheets/management?month=${month.value}`)).data; };
const loadAnalytics = async () => { analytics.value = (await api(`/api/timesheets/analytics?month=${month.value}`)).data; };
const loadAudit = async () => { auditRows.value = (await api('/api/timesheets/audit')).data || []; };
const refresh = async () => {
  loading.value = true;
  try {
    if (section.value === 'mine') await loadMine();
    else if (section.value === 'management') await loadManagement();
    else if (section.value === 'analytics') await loadAnalytics();
    else await loadAudit();
  } catch (e) { toast(e.message, true); } finally { loading.value = false; }
};

watch([section, month], refresh);
onMounted(refresh);

const entryDraft = (assignment) => {
  const entry = entriesFor(selectedDate.value).find((e) => Number(e.project_id) === Number(assignment.project_id));
  return { hours: Number(entry?.hours || 0), description: entry?.description || '' };
};
const drafts = ref({});
watch([selectedDate, workspace], () => {
  const next = {};
  for (const assignment of activeAssignments(selectedDate.value)) next[assignment.project_id] = entryDraft(assignment);
  drafts.value = next;
}, { immediate: true });

const saveEntry = async (assignment) => {
  try {
    const draft = drafts.value[assignment.project_id] || { hours: 0, description: '' };
    await api('/api/timesheets/entries', { method: 'PUT', body: { work_date: selectedDate.value, project_id: assignment.project_id, hours: Number(draft.hours || 0), description: draft.description } });
    await loadMine(); toast('Таймшит сохранён');
  } catch (e) { toast(e.message, true); }
};
const confirmDates = async (dates, confirmed = true) => {
  try {
    await api(`/api/timesheets/${confirmed ? 'confirm' : 'unconfirm'}`, { method: 'POST', body: { dates } });
    await loadMine(); toast(confirmed ? 'Таймшиты подтверждены' : 'Подтверждение снято');
  } catch (e) { toast(e.message, true); }
};
const weekDates = computed(() => {
  const d = new Date(`${selectedDate.value}T00:00:00`); const dow = (d.getDay() + 6) % 7; const start = new Date(d); start.setDate(d.getDate() - dow);
  return Array.from({ length: 7 }, (_, i) => { const x = new Date(start); x.setDate(start.getDate() + i); return `${x.getFullYear()}-${pad(x.getMonth()+1)}-${pad(x.getDate())}`; }).filter((x) => x.startsWith(month.value));
});

const visibleAssignmentsForEmployee = (employeeId) => (management.value?.assignments || []).filter((a) => Number(a.employee_id) === Number(employeeId));
const mgmtEntriesFor = (employeeId, date) => (management.value?.entries || []).filter((e) => Number(e.employee_id) === Number(employeeId) && e.work_date === date);
const mgmtHours = (employeeId, date) => mgmtEntriesFor(employeeId, date).reduce((s,e)=>s+Number(e.hours||0),0);
const mgmtPrelim = (employeeId, date) => (management.value?.confirmations || []).some((c) => Number(c.employee_id) === Number(employeeId) && c.work_date === date);
const mgmtFinal = (employeeId, date) => {
  const projects = visibleAssignmentsForEmployee(employeeId).filter((a) => a.valid_from <= date && (!a.valid_to || a.valid_to >= date)).map((a) => Number(a.project_id));
  if (!projects.length) return false;
  const approved = new Set((management.value?.final_approvals || []).filter((a)=>Number(a.employee_id)===Number(employeeId)).map((a)=>Number(a.project_id)));
  return projects.every((p)=>approved.has(p));
};
const mgmtCellClass = (employee, date) => {
  const absence = absenceFor(date, employee.id, management.value);
  if (absence?.status === 'confirmed') return 'absence-confirmed';
  if (absence) return 'absence-pending';
  const projects = visibleAssignmentsForEmployee(employee.id).filter((a)=>a.valid_from<=date&&(!a.valid_to||a.valid_to>=date));
  if (!projects.length) return 'inactive';
  if (mgmtFinal(employee.id,date)) return 'final';
  if (mgmtPrelim(employee.id,date)) return 'prelim';
  return '';
};
const employeeTotal = (employeeId) => monthDays.value.reduce((s,d)=>s+mgmtHours(employeeId,d),0);
const employeeFinal = (employeeId) => {
  const projects = [...new Set(visibleAssignmentsForEmployee(employeeId).map((a)=>Number(a.project_id)))];
  const approved = new Set((management.value?.final_approvals || []).filter((a)=>Number(a.employee_id)===Number(employeeId)).map((a)=>Number(a.project_id)));
  return projects.length > 0 && projects.every((p)=>approved.has(p));
};
const finalApprove = async (employeeId, approved) => {
  try { await api('/api/timesheets/management/final-approval',{method:'POST',body:{employee_id:employeeId,month:month.value,approved}}); await loadManagement(); toast(approved?'Финальное подтверждение установлено':'Финальное подтверждение снято'); } catch(e){ toast(e.message,true); }
};
const openManagerEdit = (employee, date) => {
  const projects = visibleAssignmentsForEmployee(employee.id).filter((a)=>a.valid_from<=date&&(!a.valid_to||a.valid_to>=date)).map((a)=>{
    const entry=mgmtEntriesFor(employee.id,date).find((e)=>Number(e.project_id)===Number(a.project_id)); return {...a,hours:Number(entry?.hours||0),description:entry?.description||''};
  });
  if (projects.length) editModal.value={employee,date,projects};
};
const saveManagerProject = async (row) => {
  try { await api('/api/timesheets/management/entries',{method:'PUT',body:{employee_id:editModal.value.employee.id,project_id:row.project_id,work_date:editModal.value.date,hours:Number(row.hours||0),description:row.description}}); await loadManagement(); toast('Изменения сохранены'); } catch(e){ toast(e.message,true); }
};
const lockPeriod = async (locked) => {
  try { await api('/api/timesheets/period-lock',{method:'POST',body:{month:month.value,locked}}); await loadManagement(); toast(locked?'Период закрыт':'Период открыт'); } catch(e){ toast(e.message,true); }
};

const filteredEmployees = computed(() => (management.value?.employees || []).filter((e) => {
  const text = `${e.full_name || ''} ${e.department_name || ''}`.toLowerCase();
  if (search.value && !text.includes(search.value.toLowerCase())) return false;
  if (departmentFilter.value && String(e.department_id) !== String(departmentFilter.value)) return false;
  if (projectFilter.value && !visibleAssignmentsForEmployee(e.id).some((a)=>String(a.project_id)===String(projectFilter.value))) return false;
  return true;
}));
const projectOptions = computed(() => {
  const seen=new Map(); for(const a of management.value?.assignments||[]) seen.set(a.project_id,a.project_name); return [...seen].map(([id,name])=>({id,name}));
});

const analyticsRows = computed(() => analyticsMode.value==='departments' ? (analytics.value?.departments||[]) : analyticsMode.value==='company' ? (analytics.value?.totals ? [{...analytics.value.totals,employee_name:'Компания',department_name:'Компания'}] : []) : (analytics.value?.employees||[]));
const pieStyle = computed(() => {
  const t=analytics.value?.totals; if(!t) return {};
  const parts=[Number(t.commercial_hours||0),...Object.values(t.absences||{}).map(Number),Number(t.idle_hours||0)]; const sum=parts.reduce((a,b)=>a+b,0)||1;
  const colors=['#18a77d','#7a6ee6','#e5a32f','#da6f5b','#569bd5','#a58bd6','#d74c4c']; let acc=0; const stops=[];
  parts.forEach((v,i)=>{const start=acc/sum*100;acc+=v;const end=acc/sum*100;stops.push(`${colors[i%colors.length]} ${start}% ${end}%`);});
  return { background:`conic-gradient(${stops.join(',')})` };
});
const absenceLabel = (type) => analytics.value?.type_labels?.[type] || type;
const auditActionLabel = (action) => ({entry_created:'Создан ТШ',entry_updated:'Изменён ТШ',entry_deleted:'Удалён ТШ',entry_deleted_outside_assignment:'Удалён вне подключения',employee_confirmed:'Подтверждено сотрудником',employee_unconfirmed:'Снято подтверждение сотрудника',manager_entry_changed:'Изменено руководителем',final_approved:'Финально подтверждено',final_unapproved:'Снято финальное подтверждение',period_locked:'Период закрыт',period_unlocked:'Период открыт'})[action]||action;
</script>

<template>
  <div class="app-shell irlix-ui">
    <UiAppSidebar :section="section" :items="menuItems" current-service="timesheets" :current-user="auth.user" :bottom-items="bottomItems" aria-label="Навигация сервиса таймшитов" @update:section="section=$event" @logout="auth.logout" />
    <main class="workspace">
      <div v-if="error" class="toast error">{{error}}</div><div v-if="message" class="toast success">{{message}}</div>
      <header class="page-head"><div><div class="eyebrow">TIMESHEETS</div><h1>{{ section==='mine'?'Мои таймшиты':section==='management'?'Управление':section==='analytics'?'Коммерческая загрузка':'История действий' }}</h1></div><div v-if="section!=='audit'" class="month-nav"><button @click="changeMonth(-1)">‹</button><input v-model="month" type="month"/><button @click="changeMonth(1)">›</button></div></header>
      <div v-if="loading" class="loading">Загрузка…</div>

      <section v-else-if="section==='mine' && workspace" class="mine-grid">
        <div class="panel calendar-panel">
          <div class="legend"><span><i class="swatch prelim"></i>Подтверждено мной</span><span><i class="swatch final"></i>Финально подтверждено</span><span><i class="swatch absence-confirmed"></i>Отсутствие</span></div>
          <div class="calendar weekdays"><b>Пн</b><b>Вт</b><b>Ср</b><b>Чт</b><b>Пт</b><b>Сб</b><b>Вс</b></div>
          <div class="calendar cells">
            <div v-for="(date,i) in calendarCells" :key="i" class="day" :class="date ? [dayClass(date),{selected:selectedDate===date}] : 'blank'" @click="date&&(selectedDate=date)">
              <template v-if="date"><small>{{Number(date.slice(-2))}}</small><strong>{{dayHours(date).toFixed(2)}}</strong><span>часов</span><em v-if="prelimConfirmed(date)">✓</em></template>
            </div>
          </div>
          <div class="confirm-row"><button @click="confirmDates([selectedDate])">Подтвердить день</button><button @click="confirmDates(weekDates)">Подтвердить неделю</button><button @click="confirmDates(monthDays)">Подтвердить месяц</button><button class="secondary" @click="confirmDates([selectedDate],false)">Снять за день</button></div>
        </div>
        <aside class="panel day-editor">
          <div class="editor-title"><div><div class="eyebrow">{{selectedDate}}</div><h2>Коммерческая деятельность</h2></div><strong>{{dayHours(selectedDate).toFixed(2)}} ч</strong></div>
          <div v-if="mineAbsence(selectedDate)" class="absence-note">{{ mineAbsence(selectedDate).status==='confirmed'?'Официальное отсутствие':'Неподтверждённое отсутствие' }} · {{ mineAbsence(selectedDate).type }}</div>
          <div v-if="!activeAssignments(selectedDate).length" class="empty">На выбранную дату нет активных подключений в Clients.</div>
          <article v-for="a in activeAssignments(selectedDate)" :key="a.project_id" class="project-card">
            <div><strong>{{a.project_name}}</strong><span>{{a.client_name}}</span></div>
            <label>Часы<input v-model.number="drafts[a.project_id].hours" type="number" min="0" max="24" step="0.25" :disabled="isLockedProject(a.project_id)"/></label>
            <label>Описание<textarea v-model="drafts[a.project_id].description" rows="4" placeholder="Что было сделано" :disabled="isLockedProject(a.project_id)"></textarea></label>
            <button :disabled="isLockedProject(a.project_id)" @click="saveEntry(a)">Сохранить</button>
            <small v-if="isLockedProject(a.project_id)" class="locked">Финально подтверждено — редактирование заблокировано</small>
          </article>
          <div class="day-total"><span>Всего за день</span><strong>{{dayHours(selectedDate).toFixed(2)}} / 24 ч</strong></div>
        </aside>
      </section>

      <section v-else-if="section==='management' && management" class="management-page">
        <div class="toolbar"><input v-model="search" placeholder="Поиск сотрудника"/><select v-model="projectFilter"><option value="">Все проекты</option><option v-for="p in projectOptions" :key="p.id" :value="p.id">{{p.name}}</option></select><select v-model="departmentFilter"><option value="">Все подразделения</option><option v-for="d in management.departments" :key="d.id" :value="d.id">{{d.name}}</option></select><span class="spacer"></span><button v-if="management.access.canLock" :class="management.period_locked?'danger':'secondary'" @click="lockPeriod(!management.period_locked)">{{management.period_locked?'Открыть период':'Закрыть период'}}</button></div>
        <div class="legend"><span><i class="swatch final"></i>Финально подтверждено</span><span><i class="swatch prelim"></i>Подтверждено сотрудником</span><span><i class="swatch absence-confirmed"></i>Предоставленное отсутствие</span><span><i class="swatch absence-pending"></i>Неподтверждённое отсутствие</span><span><i class="swatch inactive"></i>Нет подключения</span></div>
        <div class="matrix-wrap"><table class="matrix"><thead><tr><th class="sticky name">Сотрудник / проекты</th><th class="sticky action">Статус</th><th class="sticky total">Итого</th><th v-for="d in monthDays" :key="d">{{Number(d.slice(-2))}}</th></tr></thead><tbody>
          <tr v-for="e in filteredEmployees" :key="e.id"><td class="sticky name"><strong>{{e.full_name}}</strong><small>{{visibleAssignmentsForEmployee(e.id).map(a=>a.project_name).filter((v,i,a)=>a.indexOf(v)===i).join(', ')}}</small></td><td class="sticky action"><button v-if="!employeeFinal(e.id)" class="icon-btn ok" title="Финально подтвердить" @click="finalApprove(e.id,true)">✓</button><button v-else class="icon-btn danger" title="Снять финальное подтверждение" @click="finalApprove(e.id,false)">×</button></td><td class="sticky total"><strong>{{employeeTotal(e.id).toFixed(2)}}</strong></td><td v-for="d in monthDays" :key="d" class="matrix-cell" :class="mgmtCellClass(e,d)" @dblclick="openManagerEdit(e,d)"><b>{{mgmtHours(e.id,d).toFixed(2)}}</b></td></tr>
        </tbody></table></div>
      </section>

      <section v-else-if="section==='analytics' && analytics" class="analytics-page">
        <div class="toolbar"><select v-model="analyticsMode"><option value="employees">Сотрудники</option><option value="departments">Подразделения</option><option value="company">Компания</option></select></div>
        <div class="kpis"><div class="kpi"><span>Сотрудников</span><strong>{{analytics.totals.employees}}</strong></div><div class="kpi"><span>Коммерческая загрузка</span><strong>{{Number(analytics.totals.commercial_hours).toFixed(1)}} / {{Number(analytics.totals.norm_hours).toFixed(1)}} ч</strong><em>{{analytics.totals.commercial_percent}}%</em></div><div class="chart-card"><div class="pie" :style="pieStyle"></div><div class="chart-legend"><span><i style="background:#18a77d"></i>Коммерция {{analytics.totals.commercial_hours}} ч</span><span v-for="(v,k) in analytics.totals.absences" :key="k"><i></i>{{absenceLabel(k)}} {{v}} ч</span><span><i style="background:#d74c4c"></i>Простой {{analytics.totals.idle_hours}} ч</span></div></div></div>
        <div class="table-wrap"><table><thead><tr><th>{{analyticsMode==='employees'?'Сотрудник':analyticsMode==='departments'?'Подразделение':'Компания'}}</th><th>Норма, ч</th><th>Ком. загрузка, ч</th><th>%</th><th v-for="(_,k) in analytics.totals.absences" :key="k">{{absenceLabel(k)}}, ч</th><th>Простой, ч</th></tr></thead><tbody><tr v-for="r in analyticsRows" :key="r.employee_id||r.department_id||'company'"><td>{{r.employee_name||r.department_name}}</td><td>{{Number(r.norm_hours).toFixed(1)}}</td><td>{{Number(r.commercial_hours).toFixed(1)}}</td><td><b>{{r.commercial_percent}}%</b></td><td v-for="(_,k) in analytics.totals.absences" :key="k">{{Number(r.absences?.[k]||0).toFixed(1)}}</td><td>{{Number(r.idle_hours).toFixed(1)}}</td></tr></tbody></table></div>
      </section>

      <section v-else-if="section==='audit'" class="audit-page"><div class="table-wrap"><table><thead><tr><th>Дата</th><th>Действие</th><th>Кто</th><th>Сотрудник</th><th>Проект</th><th>День</th></tr></thead><tbody><tr v-for="r in auditRows" :key="r.id"><td>{{new Date(r.created_at).toLocaleString('ru-RU')}}</td><td>{{auditActionLabel(r.action)}}</td><td>#{{r.actor_employee_id||'system'}}</td><td>#{{r.employee_id||'—'}}</td><td>#{{r.project_id||'—'}}</td><td>{{r.work_date||'—'}}</td></tr></tbody></table></div></section>
    </main>

    <div v-if="editModal" class="overlay" @click.self="editModal=null"><div class="modal"><div class="modal-head"><div><div class="eyebrow">{{editModal.date}}</div><h2>{{editModal.employee.full_name}}</h2></div><button class="close" @click="editModal=null">×</button></div><article v-for="p in editModal.projects" :key="p.project_id" class="project-card"><strong>{{p.project_name}}</strong><label>Часы<input v-model.number="p.hours" type="number" min="0" max="24" step="0.25"/></label><label>Описание<textarea v-model="p.description" rows="3"></textarea></label><button @click="saveManagerProject(p)">Сохранить</button></article></div></div>
  </div>
</template>
