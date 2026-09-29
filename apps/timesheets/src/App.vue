<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiAppSidebar, UiFilterBar, UiSearchSelect } from '@irlix/ui';
import { auth } from './auth';
import { api } from './api';

const initialParams = new URLSearchParams(window.location.search);
const section = ref(['mine', 'management', 'analytics', 'audit'].includes(initialParams.get('section')) ? initialParams.get('section') : 'mine');
const month = ref(/^\d{4}-(0[1-9]|1[0-2])$/.test(initialParams.get('month') || '') ? initialParams.get('month') : new Date().toISOString().slice(0, 7));
const workspace = ref(null);
const management = ref(null);
const analytics = ref(null);
const auditRows = ref([]);
const loading = ref(false);
const message = ref('');
const error = ref('');
const selectedDate = ref(new Date().toISOString().slice(0, 10));
const editModal = ref(null);
const savingManagerEdit = ref(false);
const analyticsMode = ref('employees');
const search = ref('');
const departmentFilter = ref('');
const projectFilter = ref('');
const accountFilter = ref('');
const clientFilter = ref(initialParams.get('client_id') || '');
const employeeFilter = ref(initialParams.get('employee_id') || '');
const contourPermissions = ref({});

const can = key => !!contourPermissions.value?.[key]?.allowed;
const menuItems = computed(() => [
  { id: 'mine', icon: 'calendar', label: 'Мои таймшиты' },
  { id: 'management', icon: 'manage', label: 'Управление' },
  { id: 'analytics', icon: 'chart', label: 'Коммерческая загрузка' },
].filter(item => item.id === 'mine' ? can('timesheets.mine.view') : can(`timesheets.${item.id}.view`)));
const bottomItems = computed(() => can('timesheets.audit.view') ? [{ id: 'audit', icon: 'audit', label: 'История действий' }] : []);
const absenceLabels = {
  paid_vacation: 'Оплачиваемый отпуск',
  unpaid_vacation: 'Неоплачиваемый отпуск',
  sick_leave: 'Больничный',
  maternity_leave: 'Декрет',
  day_off: 'Отгул',
};
const chartColors = ['#18a77d', '#7a6ee6', '#e5a32f', '#da6f5b', '#569bd5', '#a58bd6', '#d74c4c', '#8d99ae'];

const pad = (n) => String(n).padStart(2, '0');
const monthDate = computed(() => new Date(`${month.value}-01T00:00:00`));
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

const toast = (text, bad = false) => {
  if (bad) { error.value = text; message.value = ''; } else { message.value = text; error.value = ''; }
  window.setTimeout(() => { if (bad) error.value = ''; else message.value = ''; }, 4500);
};

const changeMonth = (delta) => {
  const d = new Date(`${month.value}-01T00:00:00`);
  d.setMonth(d.getMonth() + delta);
  month.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
};

const entriesFor = (date, source = workspace.value) => (source?.entries || []).filter((e) => e.work_date === date);
const activeAssignments = (date, source = workspace.value) => (source?.assignments || []).filter((a) => a.valid_from <= date && (!a.valid_to || a.valid_to >= date));
const dayHours = (date, source = workspace.value) => entriesFor(date, source).reduce((sum, entry) => sum + Number(entry.hours || 0), 0);
const prelimConfirmed = (date, source = workspace.value) => {
  const confirmations = source?.confirmations || {};
  return Array.isArray(confirmations)
    ? confirmations.some((item) => item.work_date === date)
    : Boolean(confirmations?.[date]);
};
const finalProjectIds = (source) => new Set((source?.final_approvals || []).map((item) => Number(item.project_id)));
const dayFinal = (date, source = workspace.value) => {
  const assignments = activeAssignments(date, source);
  if (!assignments.length) return false;
  const approved = finalProjectIds(source);
  return assignments.every((assignment) => approved.has(Number(assignment.project_id)));
};
const absenceFor = (date, employeeId, source) => (source?.absences || []).find((item) =>
  Number(item.employee_id) === Number(employeeId) && item.starts_on <= date && item.ends_on >= date
);
const mineAbsence = (date) => absenceFor(date, workspace.value?.employee?.id, workspace.value);
const dayClass = (date) => {
  const absence = mineAbsence(date);
  const base = dayFinal(date) ? 'final' : prelimConfirmed(date) ? 'prelim' : !activeAssignments(date).length ? 'inactive' : '';
  return [base, absence?.status === 'confirmed' ? 'absence-confirmed' : absence ? 'absence-pending' : ''].filter(Boolean).join(' ');
};
const isLockedProject = (projectId) =>
  finalProjectIds(workspace.value).has(Number(projectId));

const loadMine = async () => {
  const { data } = await api(`/api/timesheets/workspace?month=${month.value}`);
  workspace.value = data;
  if (!selectedDate.value.startsWith(month.value)) selectedDate.value = `${month.value}-01`;
};
const loadManagement = async () => {
  management.value = (await api(`/api/timesheets/management?month=${month.value}`)).data;
};
const loadAnalytics = async () => {
  analytics.value = (await api(`/api/timesheets/analytics?month=${month.value}`)).data;
};
const loadAudit = async () => {
  auditRows.value = (await api('/api/timesheets/audit')).data || [];
};
const refresh = async () => {
  loading.value = true;
  try {
    if (section.value === 'mine') await loadMine();
    else if (section.value === 'management') await loadManagement();
    else if (section.value === 'analytics') await loadAnalytics();
    else await loadAudit();
  } catch (e) {
    toast(e.message, true);
  } finally {
    loading.value = false;
  }
};

watch([section, month], () => {
  const params = new URLSearchParams(window.location.search);
  params.set('section', section.value); params.set('month', month.value);
  window.history.replaceState({}, '', `${window.location.pathname}?${params}`);
  refresh();
});
onMounted(async () => {
  try {
    const response = await api('/api/clients/permissions/me');
    contourPermissions.value = response.data?.permissions || {};
    if (![...menuItems.value, ...bottomItems.value].some(item => item.id === section.value)) section.value = menuItems.value[0]?.id || 'mine';
    else await refresh();
  } catch (e) { toast(e.message, true); }
});

const entryDraft = (assignment) => {
  const entry = entriesFor(selectedDate.value).find((item) => Number(item.project_id) === Number(assignment.project_id));
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
    await api('/api/timesheets/entries', {
      method: 'PUT',
      body: {
        work_date: selectedDate.value,
        project_id: assignment.project_id,
        hours: Number(draft.hours || 0),
        description: draft.description,
      },
    });
    await loadMine();
    toast('Таймшит сохранён');
  } catch (e) {
    toast(e.message, true);
  }
};
const confirmDates = async (dates, confirmed = true) => {
  try {
    await api(`/api/timesheets/${confirmed ? 'confirm' : 'unconfirm'}`, {
      method: 'POST',
      body: { dates },
    });
    await loadMine();
    toast(confirmed ? 'Таймшиты подтверждены' : 'Подтверждение снято');
  } catch (e) {
    toast(e.message, true);
  }
};
const weekDates = computed(() => {
  const selected = new Date(`${selectedDate.value}T00:00:00`);
  const dow = (selected.getDay() + 6) % 7;
  const start = new Date(selected);
  start.setDate(selected.getDate() - dow);
  return Array.from({ length: 7 }, (_, i) => {
    const date = new Date(start);
    date.setDate(start.getDate() + i);
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }).filter((date) => date.startsWith(month.value));
});
const weekTotal = computed(() => weekDates.value.reduce((sum, date) => sum + dayHours(date), 0));
const monthTotal = computed(() => monthDays.value.reduce((sum, date) => sum + dayHours(date), 0));

const visibleAssignmentsForEmployee = (employeeId) =>
  (management.value?.assignments || []).filter((assignment) => Number(assignment.employee_id) === Number(employeeId));
const clientAssignments = (employeeId, clientId) => visibleAssignmentsForEmployee(employeeId)
  .filter((assignment) => Number(assignment.client_id) === Number(clientId));
const mgmtEntriesFor = (employeeId, date) =>
  (management.value?.entries || []).filter((entry) => Number(entry.employee_id) === Number(employeeId) && entry.work_date === date);
const mgmtHours = (employeeId, date, clientId, projectId = null) => {
  const projects = new Set(clientAssignments(employeeId, clientId).filter((assignment) => (!projectId || Number(assignment.project_id) === Number(projectId)) && assignment.valid_from <= date && (!assignment.valid_to || assignment.valid_to >= date)).map((assignment) => Number(assignment.project_id)));
  return mgmtEntriesFor(employeeId, date).filter((entry) => projects.has(Number(entry.project_id))).reduce((sum, entry) => sum + Number(entry.hours || 0), 0);
};
const mgmtPrelim = (employeeId, date) =>
  (management.value?.confirmations || []).some((item) => Number(item.employee_id) === Number(employeeId) && item.work_date === date);
const mgmtFinal = (employeeId, date, clientId, projectId = null) => {
  const projects = clientAssignments(employeeId, clientId)
    .filter((assignment) => (!projectId || Number(assignment.project_id) === Number(projectId)) && assignment.valid_from <= date && (!assignment.valid_to || assignment.valid_to >= date))
    .map((assignment) => Number(assignment.project_id));
  if (!projects.length) return false;
  const approved = new Set(
    (management.value?.final_approvals || [])
      .filter((item) => Number(item.employee_id) === Number(employeeId))
      .map((item) => Number(item.project_id))
  );
  return projects.every((projectId) => approved.has(projectId));
};
const mgmtCellClass = (employee, date, clientId, projectId = null) => {
  const absence = absenceFor(date, employee.id, management.value);
  const projects = clientAssignments(employee.id, clientId)
    .filter((assignment) => (!projectId || Number(assignment.project_id) === Number(projectId)) && assignment.valid_from <= date && (!assignment.valid_to || assignment.valid_to >= date));
  const base = !projects.length ? 'inactive' : mgmtFinal(employee.id, date, clientId, projectId) ? 'final' : mgmtPrelim(employee.id, date) ? 'prelim' : '';
  return [base, absence?.status === 'confirmed' ? 'absence-confirmed' : absence ? 'absence-pending' : ''].filter(Boolean).join(' ');
};
const employeeTotal = (employeeId, clientId, projectId = null) => monthDays.value.reduce((sum, date) => sum + mgmtHours(employeeId, date, clientId, projectId), 0);
const projectMonthApprovalState = (employeeId, clientId, projectId) => {
  if (!projectId) return { all: false, any: false };

  const activeDates = monthDays.value.filter((date) => clientAssignments(employeeId, clientId)
    .some((assignment) => Number(assignment.project_id) === Number(projectId)
      && assignment.valid_from <= date
      && (!assignment.valid_to || assignment.valid_to >= date)));
  const approvedDates = activeDates.filter((date) => mgmtFinal(employeeId, date, clientId, projectId));

  return {
    all: activeDates.length > 0 && approvedDates.length === activeDates.length,
    any: approvedDates.length > 0,
  };
};
const finalApprove = async (employeeId, projectId, approved) => {
  try {
    await api('/api/timesheets/management/final-approval', {
      method: 'POST',
      body: { employee_id: employeeId, project_id: projectId, month: month.value, approved },
    });
    await loadManagement();
    toast(approved ? 'Финальное подтверждение установлено' : 'Финальное подтверждение снято');
  } catch (e) {
    toast(e.message, true);
  }
};
const openManagerEdit = (employee, date, clientId) => {
  const projects = [...new Map(clientAssignments(employee.id, clientId)
    .filter((assignment) => assignment.valid_from <= date && (!assignment.valid_to || assignment.valid_to >= date))
    .map((assignment) => [Number(assignment.project_id), assignment])).values()]
    .map((assignment) => {
      const entry = mgmtEntriesFor(employee.id, date)
        .find((item) => Number(item.project_id) === Number(assignment.project_id));
      return {
        ...assignment,
        hours: Number(entry?.hours || 0),
        description: entry?.description || '',
      };
    });
  if (projects.length) editModal.value = { employee, date, clientId, projects };
};
const saveManagerEdit = async () => {
  if (!editModal.value || savingManagerEdit.value) return;
  savingManagerEdit.value = true;
  try {
    for (const row of editModal.value.projects) {
      await api('/api/timesheets/management/entries', {
        method: 'PUT',
        body: {
          employee_id: editModal.value.employee.id,
          project_id: row.project_id,
          work_date: editModal.value.date,
          hours: Number(row.hours || 0),
          description: row.description,
        },
      });
    }
    await loadManagement();
    editModal.value = null;
    toast('Таймшит успешно отредактирован. Подтверждение нужно выполнить заново.');
  } catch (e) {
    toast(e.message, true);
  } finally {
    savingManagerEdit.value = false;
  }
};
const projectOptions = computed(() => {
  const seen = new Map();
  for (const assignment of management.value?.assignments || []) {
    seen.set(Number(assignment.project_id), assignment.project_name);
  }
  return [...seen].map(([id, name]) => ({ id, name }));
});
const accountOptions = computed(() => {
  const ids = [...new Set(
    (management.value?.assignments || [])
      .map((assignment) => assignment.account_employee_id)
      .filter(Boolean)
      .map(Number)
  )];
  return ids.map((id) => {
    const employee = (management.value?.employees || []).find((item) => Number(item.id) === id);
    const current = Number(management.value?.current_employee?.id) === id ? management.value.current_employee : null;
    return { id, name: employee?.full_name || current?.full_name || `Аккаунт-менеджер #${id}` };
  });
});
const clientOptions = computed(() => [...new Map((management.value?.assignments || []).map((assignment) => [Number(assignment.client_id), { value: String(assignment.client_id), label: assignment.client_name }])).values()].sort((a, b) => a.label.localeCompare(b.label, 'ru')));
const employeeOptions = computed(() => (management.value?.employees || []).map((employee) => ({ value: String(employee.id), label: employee.full_name })).sort((a, b) => a.label.localeCompare(b.label, 'ru')));
const projectFilterOptions = computed(() => projectOptions.value.map((project) => ({ value: String(project.id), label: project.name })));
const accountFilterOptions = computed(() => accountOptions.value.map((account) => ({ value: String(account.id), label: account.name })));
const departmentFilterOptions = computed(() => (management.value?.departments || []).map((department) => ({ value: String(department.id), label: department.name })));
const filteredEmployees = computed(() => (management.value?.employees || []).filter((employee) => {
  const text = `${employee.full_name || ''} ${employee.department_name || ''}`.toLowerCase();
  if (employeeFilter.value && String(employee.id) !== String(employeeFilter.value)) return false;
  if (search.value && !text.includes(search.value.toLowerCase())) return false;
  if (departmentFilter.value && String(employee.department_id) !== String(departmentFilter.value)) return false;
  if (projectFilter.value && !visibleAssignmentsForEmployee(employee.id)
    .some((assignment) => String(assignment.project_id) === String(projectFilter.value))) return false;
  if (accountFilter.value && !visibleAssignmentsForEmployee(employee.id)
    .some((assignment) => String(assignment.account_employee_id) === String(accountFilter.value))) return false;
  return true;
}));
const managementRows = computed(() => filteredEmployees.value.flatMap((employee) => {
  const assignments = visibleAssignmentsForEmployee(employee.id);
  return [...new Map(assignments.map((assignment) => [Number(assignment.project_id), { employee, clientId: Number(assignment.client_id), clientName: assignment.client_name, projectId: Number(assignment.project_id), projectName: assignment.project_name }])).values()]
    .filter((row) => !clientFilter.value || String(row.clientId) === String(clientFilter.value))
    .filter((row) => !projectFilter.value || String(row.projectId) === String(projectFilter.value))
    .filter((row) => !accountFilter.value || String((assignments.find((a) => Number(a.project_id) === row.projectId)?.account_employee_id || '')) === String(accountFilter.value));
}));

const analyticsRows = computed(() => {
  if (analyticsMode.value === 'departments') return analytics.value?.departments || [];
  if (analyticsMode.value === 'company') {
    return analytics.value?.totals
      ? [{ ...analytics.value.totals, employee_name: 'Компания', department_name: 'Компания' }]
      : [];
  }
  return analytics.value?.employees || [];
});
const chartSegments = computed(() => {
  const totals = analytics.value?.totals;
  if (!totals) return [];
  const segments = [{
    key: 'commercial',
    label: 'Коммерческие часы',
    value: Number(totals.commercial_hours || 0),
    color: chartColors[0],
  }];
  Object.entries(totals.absences || {}).forEach(([key, value], index) => {
    segments.push({
      key,
      label: analytics.value?.type_labels?.[key] || absenceLabels[key] || key,
      value: Number(value || 0),
      color: chartColors[index + 1],
    });
  });
  segments.push({
    key: 'idle',
    label: 'Простой',
    value: Number(totals.idle_hours || 0),
    color: chartColors[chartColors.length - 1],
  });
  return segments;
});
const pieStyle = computed(() => {
  const segments = chartSegments.value;
  const sum = segments.reduce((acc, segment) => acc + segment.value, 0) || 1;
  let cursor = 0;
  const stops = segments.map((segment) => {
    const start = (cursor / sum) * 100;
    cursor += segment.value;
    const end = (cursor / sum) * 100;
    return `${segment.color} ${start}% ${end}%`;
  });
  return { background: `conic-gradient(${stops.join(',')})` };
});
const absenceLabel = (type) => analytics.value?.type_labels?.[type] || absenceLabels[type] || type;
const auditActionLabel = (action) => ({
  entry_created: 'Создан таймшит',
  entry_updated: 'Изменён таймшит',
  entry_deleted: 'Удалён таймшит',
  entry_deleted_outside_assignment: 'Удалён вне периода подключения',
  final_approval_deleted_outside_assignment: 'Снято подтверждение вне подключения',
  employee_confirmed: 'Подтверждено сотрудником',
  employee_unconfirmed: 'Снято подтверждение сотрудника',
  manager_entry_changed: 'Изменено руководителем',
  final_approved: 'Финально подтверждено',
  final_unapproved: 'Снято финальное подтверждение',
  period_locked: 'Период закрыт',
  period_unlocked: 'Период открыт',
})[action] || action;
</script>

<template>
  <div class="app-shell irlix-ui">
    <UiAppSidebar
      :section="section"
      :items="menuItems"
      current-service="timesheets"
      :current-user="auth.user"
      :bottom-items="bottomItems"
      aria-label="Навигация сервиса таймшитов"
      @update:section="section = $event"
      @logout="auth.logout"
    />

    <main class="workspace" :class="{ 'workspace-mine': section === 'mine' }">
      <div v-if="error" class="toast error">{{ error }}</div>
      <div v-if="message" class="toast success">{{ message }}</div>

      <header class="page-head">
        <div>
          <div class="eyebrow">TIMESHEETS</div>
          <h1>
            {{ section === 'mine'
              ? 'Мои таймшиты'
              : section === 'management'
                ? 'Управление'
                : section === 'analytics'
                  ? 'Коммерческая загрузка'
                  : 'История действий' }}
          </h1>
        </div>
        <div v-if="section !== 'audit'" class="month-nav">
          <button aria-label="Предыдущий месяц" @click="changeMonth(-1)">‹</button>
          <input v-model="month" type="month" />
          <button aria-label="Следующий месяц" @click="changeMonth(1)">›</button>
        </div>
      </header>

      <div v-if="loading" class="loading">Загрузка…</div>

      <section v-else-if="section === 'mine' && workspace" class="mine-grid">
        <div class="panel calendar-panel">
          <div class="legend">
            <span><i class="swatch prelim"></i>Подтверждено мной</span>
            <span><i class="swatch final"></i>Финально подтверждено</span>
            <span><i class="swatch absence-confirmed"></i>Предоставленное отсутствие</span>
            <span><i class="swatch absence-pending"></i>Неподтверждённое отсутствие</span>
            <span><i class="swatch inactive"></i>Нет подключения</span>
          </div>

          <div class="calendar weekdays">
            <b>Пн</b><b>Вт</b><b>Ср</b><b>Чт</b><b>Пт</b><b>Сб</b><b>Вс</b>
          </div>
          <div class="calendar cells">
            <div
              v-for="(date, index) in calendarCells"
              :key="index"
              class="day"
              :class="date ? [dayClass(date), { selected: selectedDate === date }] : 'blank'"
              @click="date && (selectedDate = date)"
            >
              <template v-if="date">
                <small>{{ Number(date.slice(-2)) }}</small>
                <strong>{{ dayHours(date).toFixed(2) }}</strong>
                <span>часов</span>
              </template>
            </div>
          </div>

          <div class="calendar-summary">
            <span>Выбранная неделя: <b>{{ weekTotal.toFixed(2) }} ч</b></span>
            <span>Месяц: <b>{{ monthTotal.toFixed(2) }} ч</b></span>
          </div>

          <div class="confirm-row">
            <button @click="confirmDates([selectedDate])">Подтвердить день</button>
            <button @click="confirmDates(weekDates)">Подтвердить неделю</button>
            <button @click="confirmDates(monthDays)">Подтвердить месяц</button>
            <button class="secondary" @click="confirmDates([selectedDate], false)">Снять за день</button>
          </div>
        </div>

        <aside class="panel day-editor">
          <div class="editor-title">
            <div>
              <div class="eyebrow">{{ selectedDate }}</div>
              <h2>Коммерческая деятельность</h2>
            </div>
            <strong>{{ dayHours(selectedDate).toFixed(2) }} ч</strong>
          </div>

          <div v-if="mineAbsence(selectedDate)" class="absence-note">
            {{ mineAbsence(selectedDate).status === 'confirmed' ? 'Официальное отсутствие' : 'Неподтверждённое отсутствие' }}
            · {{ absenceLabels[mineAbsence(selectedDate).type] || mineAbsence(selectedDate).type }}
          </div>

          <div v-if="!activeAssignments(selectedDate).length" class="empty">
            На выбранную дату нет активных подключений в Clients.
          </div>

          <article v-for="assignment in activeAssignments(selectedDate)" :key="assignment.project_id" class="project-card">
            <div>
              <strong>{{ assignment.project_name }}</strong>
              <span>{{ assignment.client_name }}</span>
            </div>
            <label>
              Часы
              <input
                v-if="drafts[assignment.project_id]"
                v-model.number="drafts[assignment.project_id].hours"
                type="number"
                min="0"
                max="24"
                step="0.25"
                :disabled="isLockedProject(assignment.project_id)"
              />
            </label>
            <label>
              Описание
              <textarea
                v-if="drafts[assignment.project_id]"
                v-model="drafts[assignment.project_id].description"
                rows="4"
                placeholder="Что было сделано"
                :disabled="isLockedProject(assignment.project_id)"
              ></textarea>
            </label>
            <button :disabled="isLockedProject(assignment.project_id)" @click="saveEntry(assignment)">Сохранить</button>
            <small v-if="isLockedProject(assignment.project_id)" class="locked">
              Финально подтверждено или период закрыт — редактирование заблокировано
            </small>
          </article>

          <div class="day-total">
            <span>Всего за день</span>
            <strong>{{ dayHours(selectedDate).toFixed(2) }} / 24 ч</strong>
          </div>
        </aside>
      </section>

      <section v-else-if="section === 'management' && management" class="management-page">
        <UiFilterBar class="management-filters">
          <input v-model="search" class="management-search" type="search" placeholder="Поиск сотрудника" />
          <UiSearchSelect v-model="employeeFilter" :options="employeeOptions" placeholder="Специалисты" search-placeholder="Поиск специалиста" />
          <UiSearchSelect v-model="clientFilter" :options="clientOptions" placeholder="Клиенты" search-placeholder="Поиск клиента" />
          <UiSearchSelect v-model="projectFilter" :options="projectFilterOptions" placeholder="Проекты" search-placeholder="Поиск проекта" />
          <UiSearchSelect v-model="accountFilter" :options="accountFilterOptions" placeholder="Аккаунты" search-placeholder="Поиск аккаунта" />
          <UiSearchSelect v-model="departmentFilter" :options="departmentFilterOptions" placeholder="Подразделения" search-placeholder="Поиск подразделения" />
        </UiFilterBar>

        <div class="legend">
          <span><i class="swatch final"></i>Финально подтверждено</span>
          <span><i class="swatch prelim"></i>Подтверждено сотрудником</span>
          <span><i class="swatch absence-confirmed"></i>Предоставленное отсутствие</span>
          <span><i class="swatch absence-pending"></i>Неподтверждённое отсутствие</span>
          <span><i class="swatch inactive"></i>Нет подключения</span>
        </div>


        <div class="matrix-wrap">
          <table class="matrix">
            <thead>
              <tr>
                <th class="sticky name">Сотрудник / клиент / проекты</th>
                <th class="sticky action">Статус</th>
                <th class="sticky total">Итого</th>
                <th v-for="date in monthDays" :key="date">{{ Number(date.slice(-2)) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in managementRows" :key="`${row.employee.id}-${row.projectId}`">
                <td class="sticky name">
                  <strong>{{ row.employee.full_name }}</strong>
                  <small>{{ row.clientName }}</small>
                  <small>
                    {{ row.projectName || 'Нет проектов в выбранном месяце' }}
                  </small>
                </td>
                <td class="sticky action">
                  <div class="approval-actions">
                    <button
                      class="icon-btn ok"
                      title="Финально подтвердить проект за месяц"
                      aria-label="Финально подтвердить проект за месяц"
                      :disabled="!row.projectId || projectMonthApprovalState(row.employee.id, row.clientId, row.projectId).all"
                      @click="finalApprove(row.employee.id, row.projectId, true)"
                    >✓</button>
                    <button
                      class="icon-btn danger"
                      title="Снять финальное подтверждение проекта за месяц"
                      aria-label="Снять финальное подтверждение проекта за месяц"
                      :disabled="!row.projectId || !projectMonthApprovalState(row.employee.id, row.clientId, row.projectId).any"
                      @click="finalApprove(row.employee.id, row.projectId, false)"
                    >×</button>
                  </div>
                </td>
                <td class="sticky total"><strong>{{ employeeTotal(row.employee.id, row.clientId, row.projectId).toFixed(2) }}</strong></td>
                <td
                  v-for="date in monthDays"
                  :key="date"
                  class="matrix-cell"
                  :class="mgmtCellClass(row.employee, date, row.clientId, row.projectId)"
                  title="Двойной клик — редактировать"
                  @dblclick="openManagerEdit(row.employee, date, row.clientId)"
                >
                  <b>{{ mgmtHours(row.employee.id, date, row.clientId, row.projectId).toFixed(2) }}</b>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-else-if="section === 'analytics' && analytics" class="analytics-page">
        <div class="toolbar">
          <select v-model="analyticsMode">
            <option value="employees">Сотрудники</option>
            <option value="departments">Подразделения</option>
            <option value="company">Компания</option>
          </select>
          <span class="analytics-note">В расчёте коммерции учитываются только финально подтверждённые часы.</span>
        </div>

        <div class="kpis">
          <div class="kpi">
            <span>Сотрудников</span>
            <strong>{{ analytics.totals.employees }}</strong>
          </div>
          <div class="kpi">
            <span>Коммерческая загрузка</span>
            <strong>{{ Number(analytics.totals.commercial_hours).toFixed(1) }} / {{ Number(analytics.totals.norm_hours).toFixed(1) }} ч</strong>
            <em>{{ analytics.totals.commercial_percent }}%</em>
          </div>
          <div class="chart-card">
            <div class="pie" :style="pieStyle"></div>
            <div class="chart-legend">
              <span v-for="segment in chartSegments" :key="segment.key">
                <i :style="{ background: segment.color }"></i>{{ segment.label }} — {{ segment.value }} ч
              </span>
            </div>
          </div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ analyticsMode === 'employees' ? 'Сотрудник' : analyticsMode === 'departments' ? 'Подразделение' : 'Компания' }}</th>
                <th>Норма, ч</th>
                <th>Ком. загрузка, ч</th>
                <th>%</th>
                <th v-for="(_, type) in analytics.totals.absences" :key="type">{{ absenceLabel(type) }}, ч</th>
                <th>Простой, ч</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in analyticsRows" :key="row.employee_id || row.department_id || 'company'">
                <td>{{ row.employee_name || row.department_name }}</td>
                <td>{{ Number(row.norm_hours).toFixed(1) }}</td>
                <td>{{ Number(row.commercial_hours).toFixed(1) }}</td>
                <td><b>{{ row.commercial_percent }}%</b></td>
                <td v-for="(_, type) in analytics.totals.absences" :key="type">{{ Number(row.absences?.[type] || 0).toFixed(1) }}</td>
                <td>{{ Number(row.idle_hours).toFixed(1) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-else-if="section === 'audit'" class="audit-page">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Дата</th><th>Действие</th><th>Кто</th><th>Сотрудник</th><th>Проект</th><th>День</th></tr></thead>
            <tbody>
              <tr v-for="row in auditRows" :key="row.id">
                <td>{{ new Date(row.created_at).toLocaleString('ru-RU') }}</td>
                <td>{{ auditActionLabel(row.action) }}</td>
                <td>{{ row.actor_employee_id ? `#${row.actor_employee_id}` : 'Система' }}</td>
                <td>{{ row.employee_id ? `#${row.employee_id}` : '—' }}</td>
                <td>{{ row.project_id ? `#${row.project_id}` : '—' }}</td>
                <td>{{ row.work_date || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </main>

    <div v-if="editModal" class="overlay" @click.self="editModal = null">
      <div class="modal">
        <div class="modal-head">
          <div><div class="eyebrow">{{ editModal.date }}</div><h2>{{ editModal.employee.full_name }}</h2><small>{{ editModal.projects[0]?.client_name }}</small></div>
          <button class="close" @click="editModal = null">×</button>
        </div>
        <article v-for="project in editModal.projects" :key="project.project_id" class="project-card">
          <strong>{{ project.project_name }}</strong>
          <label>Часы<input v-model.number="project.hours" type="number" min="0" max="24" step="0.25" /></label>
          <label>Описание<textarea v-model="project.description" rows="3"></textarea></label>
        </article>
        <button class="manager-save" :disabled="savingManagerEdit" @click="saveManagerEdit">{{ savingManagerEdit ? 'Сохранение…' : 'Сохранить' }}</button>
      </div>
    </div>
  </div>
</template>
