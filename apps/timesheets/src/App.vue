<script setup>
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { UiAppTopbar, UiAppSidebar, UiButton, UiFilterBar, UiSearchSelect, UiPeriodPicker, UiViewSelect } from '@irlix/ui';
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
const managerEditor = ref(null);
const productionCalendar = ref({});
const calendarYear = ref('');
const dragSelection = ref(null);
const bulkModal = ref(null);
const bulkHoursInput = ref(null);
const savingBulk = ref(false);
const bulkError = ref('');
const cellTooltip = ref(null);
const tooltipElement = ref(null);
let tooltipHideTimer;
let tooltipSequence = 0;
const keepCellTooltip = () => window.clearTimeout(tooltipHideTimer);
const leaveCell = () => {
  keepCellTooltip();
  tooltipHideTimer = window.setTimeout(() => { cellTooltip.value = null; }, 80);
};
const analyticsMode = ref('employees');
const analyticsViewOptions = [
  { value: 'employees', label: 'Сотрудники' },
  { value: 'departments', label: 'Подразделения' },
  { value: 'company', label: 'Компания' },
];
const search = ref('');
const departmentFilter = ref('');
const projectFilter = ref('');
const accountFilter = ref('');
const clientFilter = ref(initialParams.get('client_id') || '');
const employeeFilter = ref(initialParams.get('employee_id') || '');
const contourPermissions = ref({});
const dayEditor = ref(null);
const drafts = ref({});
const draftSignatures = ref({});
const savingProjects = ref(new Set());
const savePromises = new Map();

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
  const cells = [...Array(lead).fill(null), ...monthDays.value];
  while (cells.length % 7) cells.push(null);
  return cells;
});
const calendarWeeks = computed(() => {
  const weeks = [];
  for (let index = 0; index < calendarCells.value.length; index += 7) {
    weeks.push(calendarCells.value.slice(index, index + 7));
  }
  return weeks;
});

const toast = (text, bad = false) => {
  if (bad) { error.value = text; message.value = ''; } else { message.value = text; error.value = ''; }
  window.setTimeout(() => { if (bad) error.value = ''; else message.value = ''; }, 4500);
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
const dayHasFinalApproval = (date, source = workspace.value) => {
  const approved = finalProjectIds(source);
  return activeAssignments(date, source).some((assignment) => approved.has(Number(assignment.project_id)));
};
const absenceFor = (date, employeeId, source) => (source?.absences || []).find((item) =>
  Number(item.employee_id) === Number(employeeId) && item.starts_on <= date && item.ends_on >= date
);
const vacationFor = (date, employeeId, source) => {
  const absence = absenceFor(date, employeeId, source);
  return ['paid_vacation', 'unpaid_vacation'].includes(absence?.type) ? absence : null;
};
const mineAbsence = (date) => absenceFor(date, workspace.value?.employee?.id, workspace.value);
const dayClass = (date) => {
  const absence = mineAbsence(date);
  const base = !activeAssignments(date).length ? 'inactive' : dayFinal(date) ? 'final' : prelimConfirmed(date) ? 'prelim' : '';
  return [base, absence?.status === 'confirmed' ? 'absence-confirmed' : absence ? 'absence-pending' : ''].filter(Boolean).join(' ');
};
const isLockedProject = (projectId) => finalProjectIds(workspace.value).has(Number(projectId));
const datesForWeek = (week) => week.filter(Boolean);
const weekTotalFor = (week) => datesForWeek(week).reduce((sum, date) => sum + dayHours(date), 0);
const monthTotal = computed(() => monthDays.value.reduce((sum, date) => sum + dayHours(date), 0));
const hasPrelimConfirmation = (dates) => dates.some((date) => prelimConfirmed(date));
const allConfirmableDatesConfirmed = (dates) => {
  const confirmable = dates.filter((date) => activeAssignments(date).length > 0);
  return confirmable.length > 0 && confirmable.every((date) => prelimConfirmed(date) || dayFinal(date));
};

const loadMine = async () => {
  const { data } = await api(`/api/timesheets/workspace?month=${month.value}`);
  workspace.value = data;
  if (!selectedDate.value.startsWith(month.value)) selectedDate.value = `${month.value}-01`;
};
const loadManagement = async () => {
  const year = month.value.slice(0, 4);
  if (calendarYear.value !== year) {
    productionCalendar.value = {};
    try {
      const response = await api(`/api/vacations/production-calendar?year=${year}`);
      productionCalendar.value = response.data?.days || {};
      calendarYear.value = year;
    } catch (e) { toast(`Не удалось загрузить производственный календарь: ${e.message}`, true); }
  }
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
  cancelSelection();
  bulkModal.value = null;
  editModal.value = null;
  cellTooltip.value = null;
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
const draftSignature = (draft) => JSON.stringify({
  hours: Number(draft?.hours || 0),
  description: draft?.description || '',
});
watch([selectedDate, workspace], () => {
  const next = {};
  const signatures = {};
  for (const assignment of activeAssignments(selectedDate.value)) {
    next[assignment.project_id] = entryDraft(assignment);
    signatures[assignment.project_id] = draftSignature(next[assignment.project_id]);
  }
  drafts.value = next;
  draftSignatures.value = signatures;
}, { immediate: true });

const selectDate = async (date) => {
  if (!date) return;
  selectedDate.value = date;
  await nextTick();
  window.requestAnimationFrame(() => {
    dayEditor.value?.querySelector('.project-card input[type="number"]:not(:disabled)')?.focus();
  });
};

const saveEntry = async (assignment) => {
  const projectId = assignment.project_id;
  const draft = drafts.value[projectId] || { hours: 0, description: '' };
  const signature = draftSignature(draft);
  if (signature === draftSignatures.value[projectId]) return;
  if (savePromises.has(projectId)) return savePromises.get(projectId);

  savingProjects.value = new Set([...savingProjects.value, projectId]);
  const promise = (async () => {
    try {
      await api('/api/timesheets/entries', {
        method: 'PUT',
        body: {
          work_date: selectedDate.value,
          project_id: projectId,
          hours: Number(draft.hours || 0),
          description: draft.description,
        },
      });
      draftSignatures.value = { ...draftSignatures.value, [projectId]: signature };
      await loadMine();
    } catch (e) {
      toast(e.message, true);
      throw e;
    } finally {
      savePromises.delete(projectId);
      const next = new Set(savingProjects.value);
      next.delete(projectId);
      savingProjects.value = next;
    }
  })();
  savePromises.set(projectId, promise);
  return promise;
};
const confirmDates = async (dates, confirmed = true) => {
  try {
    const uniqueDates = [...new Set(dates)].filter(Boolean);
    if (uniqueDates.includes(selectedDate.value)) {
      for (const assignment of activeAssignments(selectedDate.value)) await saveEntry(assignment);
    }
    const submittedDates = confirmed
      ? uniqueDates.filter((date) => activeAssignments(date).length > 0)
      : uniqueDates.filter((date) => !dayHasFinalApproval(date));
    if (!submittedDates.length) {
      toast(confirmed
        ? 'Нет дней с активными проектами для подтверждения'
        : 'Нет доступных дней для снятия подтверждения', true);
      return;
    }
    await api(`/api/timesheets/${confirmed ? 'confirm' : 'unconfirm'}`, {
      method: 'POST',
      body: { dates: submittedDates },
    });
    await loadMine();
    toast(confirmed ? 'Таймшиты подтверждены' : 'Подтверждение снято');
  } catch (e) {
    toast(e.message, true);
  }
};

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
  return [base, isNonWorkingDate(date) ? 'non-working' : '', projects.length && absence ? (absence.status === 'confirmed' ? 'absence-confirmed' : 'absence-pending') : ''].filter(Boolean).join(' ');
};
const isNonWorkingDate = (date) => {
  const info = productionCalendar.value[date];
  if (info) return !info.is_working;
  return [0, 6].includes(new Date(`${date}T00:00:00`).getDay());
};
const rowEntry = (row, date) => mgmtEntriesFor(row.employee.id, date)
  .find(entry => Number(entry.project_id) === Number(row.projectId));
const rowDescription = (row, date) => String(rowEntry(row, date)?.description || '').trim();
const rowActive = (row, date) => clientAssignments(row.employee.id, row.clientId)
  .some(a => Number(a.project_id) === Number(row.projectId) && a.valid_from <= date && (!a.valid_to || a.valid_to >= date));
const sameRow = (a, b) => a && b && a.employee.id === b.employee.id && a.projectId === b.projectId && a.clientId === b.clientId;
const selectedDates = computed(() => {
  const selection = dragSelection.value;
  if (!selection) return [];
  const from = selection.start < selection.end ? selection.start : selection.end;
  const to = selection.start > selection.end ? selection.start : selection.end;
  return monthDays.value.filter(date => date >= from && date <= to && rowActive(selection.row, date));
});
const cellSelected = (row, date) => sameRow(row, dragSelection.value?.row) && selectedDates.value.includes(date);
const cancelSelection = () => { dragSelection.value = null; };
const startSelection = (event, row, date) => {
  if (event.button !== 0 || event.detail > 1 || !can('timesheets.management.manage') || !rowActive(row, date) || savingBulk.value) return;
  event.preventDefault();
  cellTooltip.value = null;
  dragSelection.value = { row, start: date, end: date, moved: false, dragging: true };
};
const enterCell = (event, row, date) => {
  const selection = dragSelection.value;
  if (selection) {
    if (!selection.dragging) return;
    if (!(event.buttons & 1)) { cancelSelection(); return; }
    if (sameRow(row, selection.row)) {
      selection.end = date;
      selection.moved ||= date !== selection.start;
    }
    return;
  }
  showCellTooltip(event, row, date);
};
const finishSelection = async () => {
  if (!dragSelection.value?.dragging) return;
  const selection = dragSelection.value;
  const dates = [...selectedDates.value];
  if (!selection.moved || dates.length < 2) { cancelSelection(); return; }
  selection.dragging = false;
  bulkError.value = '';
  bulkModal.value = { row: selection.row, dates, hours: '' };
  await nextTick();
  bulkHoursInput.value?.focus();
};
const closeBulk = () => {
  if (savingBulk.value) return;
  bulkModal.value = null;
  cancelSelection();
};
const showCellTooltip = async (event, row, date) => {
  if (dragSelection.value || bulkModal.value || editModal.value) return;
  keepCellTooltip();
  const sequence = ++tooltipSequence;
  const rect = event.currentTarget.getBoundingClientRect();
  const tooltip = { text: rowDescription(row, date) || 'Описание не заполнено', left: rect.left, top: rect.bottom + 6 };
  cellTooltip.value = tooltip;
  await nextTick();
  if (sequence !== tooltipSequence || !cellTooltip.value) return;
  const bounds = tooltipElement.value?.getBoundingClientRect();
  if (!bounds) return;
  cellTooltip.value = { ...tooltip,
    left: Math.max(8, Math.min(rect.left, window.innerWidth - bounds.width - 8)),
    top: rect.bottom + 6 + bounds.height > window.innerHeight - 8 ? Math.max(8, rect.top - bounds.height - 6) : rect.bottom + 6,
  };
};
const saveBulk = async () => {
  if (!bulkModal.value || savingBulk.value) return;
  const edit = bulkModal.value;
  const hours = Number(edit.hours);
  if (edit.hours === '' || !Number.isFinite(hours) || hours < 0 || hours > 24 || Math.abs(hours * 4 - Math.round(hours * 4)) > 0.00001) {
    bulkError.value = 'Введите часы от 0 до 24 с шагом 0,25.';
    bulkHoursInput.value?.focus();
    return;
  }
  savingBulk.value = true;
  bulkError.value = '';
  try {
    await api('/api/timesheets/management/bulk-hours', { method: 'PUT', body: {
      employee_id: edit.row.employee.id, project_id: edit.row.projectId, dates: edit.dates, hours,
    } });
    bulkModal.value = null;
    cancelSelection();
    await loadManagement();
    toast('Часы заполнены. Описания сохранены. Подтверждение нужно выполнить заново.');
  } catch (e) { bulkError.value = e.message; toast(e.message, true); }
  finally { savingBulk.value = false; }
};
const handleManagerKey = (event) => {
  if (event.key !== 'Escape') return;
  closeBulk();
  if (!savingManagerEdit.value) editModal.value = null;
  cellTooltip.value = null;
};
onMounted(() => {
  window.addEventListener('mouseup', finishSelection);
  window.addEventListener('blur', cancelSelection);
  window.addEventListener('keydown', handleManagerKey);
});
onBeforeUnmount(() => {
  keepCellTooltip();
  window.removeEventListener('mouseup', finishSelection);
  window.removeEventListener('blur', cancelSelection);
  window.removeEventListener('keydown', handleManagerKey);
});
watch([search, departmentFilter, projectFilter, accountFilter, clientFilter, employeeFilter], () => {
  cancelSelection(); cellTooltip.value = null;
});
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
const openManagerEdit = async (employee, date, clientId, projectId) => {
  if (!can('timesheets.management.manage')) return;
  cancelSelection();
  cellTooltip.value = null;
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
  if (projects.length) {
    editModal.value = { employee, date, clientId, projects };
    await nextTick();
    const input = managerEditor.value?.querySelector(`[data-project-id="${projectId}"] input`) || managerEditor.value?.querySelector('input');
    input?.focus(); input?.select();
  }
};
const saveManagerEdit = async () => {
  if (!editModal.value || savingManagerEdit.value) return;
  savingManagerEdit.value = true;
  try {
    const edit = editModal.value;
    await api('/api/timesheets/management/entries', {
      method: 'PUT',
      body: {
        employee_id: edit.employee.id,
        work_date: edit.date,
        entries: edit.projects.map(row => ({
          project_id: row.project_id,
          hours: Number(row.hours || 0),
          description: row.description,
        })),
      },
    });
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
  employee_confirmation_deleted_outside_assignment: 'Снято подтверждение дня вне подключения',
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
      :platform-access="() => auth.fetch('/api/employees/access/me')"
      :bottom-items="bottomItems"
      aria-label="Навигация сервиса таймшитов"
      @update:section="section = $event"
      @logout="auth.logout"
    />

    <main class="workspace" :class="{ 'workspace-mine': section === 'mine' }">
      <UiAppTopbar service="timesheets" :section="section" :items="[...menuItems,...bottomItems]" :loading="loading">
        <template v-if="section === 'analytics'" #breadcrumb-extra>
          <span class="irlix-breadcrumbs__separator" aria-hidden="true">—</span>
          <UiViewSelect
            v-model="analyticsMode"
            :options="analyticsViewOptions"
            aria-label="Вариант отображения коммерческой загрузки"
          />
        </template>
        <template #actions>
          <UiPeriodPicker v-if="section !== 'audit'" v-model="month" />
        </template>
      </UiAppTopbar>
      <div v-if="error" class="toast error">{{ error }}</div>
      <div v-if="message" class="toast success">{{ message }}</div>



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

          <div class="mine-calendar-grid">
            <div class="calendar-weekdays">
              <b>Пн</b><b>Вт</b><b>Ср</b><b>Чт</b><b>Пт</b><b>Сб</b><b>Вс</b>
            </div>
            <div class="period-actions-head" aria-hidden="true"></div>

            <template v-for="(week, weekIndex) in calendarWeeks" :key="`week-${weekIndex}`">
              <div class="calendar-week">
                <div
                  v-for="(date, dayIndex) in week"
                  :key="date || `blank-${weekIndex}-${dayIndex}`"
                  class="day"
                  :class="date ? [dayClass(date), { selected: selectedDate === date }] : 'blank'"
                  @click="selectDate(date)"
                >
                  <template v-if="date">
                    <small>{{ Number(date.slice(-2)) }}</small>
                    <strong>{{ dayHours(date).toFixed(2) }}</strong>
                    <span>часов</span>
                  </template>
                </div>
              </div>

              <div class="period-action week-action">
                <strong>{{ weekTotalFor(week).toFixed(2) }}</strong>
                <span>ч</span>
                <div class="period-action-buttons">
                  <button
                    class="period-icon ok"
                    :disabled="allConfirmableDatesConfirmed(datesForWeek(week))"
                    title="Подтвердить неделю"
                    aria-label="Подтвердить неделю"
                    @click="confirmDates(datesForWeek(week))"
                  >✓</button>
                  <button
                    class="period-icon danger"
                    :disabled="!hasPrelimConfirmation(datesForWeek(week))"
                    title="Снять подтверждение за неделю"
                    aria-label="Снять подтверждение за неделю"
                    @click="confirmDates(datesForWeek(week), false)"
                  >×</button>
                </div>
              </div>
            </template>

            <div class="month-period-spacer"></div>
            <div class="period-action month-action">
              <strong>{{ monthTotal.toFixed(2) }}</strong>
              <span>ч / месяц</span>
              <div class="period-action-buttons">
                <button
                  class="period-icon ok"
                  :disabled="allConfirmableDatesConfirmed(monthDays)"
                  title="Подтвердить месяц"
                  aria-label="Подтвердить месяц"
                  @click="confirmDates(monthDays)"
                >✓</button>
                <button
                  class="period-icon danger"
                  :disabled="!hasPrelimConfirmation(monthDays)"
                  title="Снять подтверждение за месяц"
                  aria-label="Снять подтверждение за месяц"
                  @click="confirmDates(monthDays, false)"
                >×</button>
              </div>
            </div>
          </div>
        </div>

        <aside ref="dayEditor" class="panel day-editor">
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
                @blur="saveEntry(assignment)"
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
                @blur="saveEntry(assignment)"
              ></textarea>
            </label>
            <small v-if="savingProjects.has(assignment.project_id)" class="autosave-state">Сохранение…</small>
            <small v-if="isLockedProject(assignment.project_id)" class="locked">
              Финально подтверждено или период закрыт — редактирование заблокировано
            </small>
          </article>

          <div class="day-confirm-actions">
            <button
              class="day-confirm ok"
              :disabled="!activeAssignments(selectedDate).length || prelimConfirmed(selectedDate) || dayFinal(selectedDate)"
              @click="confirmDates([selectedDate])"
            >Подтвердить день</button>
            <button
              class="day-confirm secondary"
              :disabled="!prelimConfirmed(selectedDate) || dayHasFinalApproval(selectedDate)"
              :title="dayHasFinalApproval(selectedDate) ? 'Финальное подтверждение должен снять руководитель или аккаунт-менеджер' : 'Снять подтверждение за день'"
              @click="confirmDates([selectedDate], false)"
            >Снять подтверждение</button>
          </div>

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

        <div class="matrix-wrap" @scroll="cellTooltip = null">
          <table class="matrix">
            <thead>
              <tr>
                <th class="sticky name">Сотрудник / проект</th>
                <th class="sticky action">Статус</th>
                <th class="sticky total">Итого</th>
                <th v-for="date in monthDays" :key="date" :class="{ 'non-working': isNonWorkingDate(date) }">{{ Number(date.slice(-2)) }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="client in clientOptions" :key="`client-${client.value}`">
                <tr
                  v-if="managementRows.some((row) => String(row.clientId) === String(client.value))"
                  class="client-group-row"
                >
                  <td :colspan="monthDays.length + 3" class="client-group-cell">
                    <strong>{{ client.label }}</strong>
                  </td>
                </tr>
                <tr
                  v-for="row in managementRows.filter((item) => String(item.clientId) === String(client.value))"
                  :key="`${row.employee.id}-${row.projectId}`"
                >
                  <td class="sticky name">
                    <strong>{{ String(row.employee.full_name || '').trim().split(/\s+/).slice(0, 2).join(' ') }}</strong>
                    <small>{{ row.projectName || 'Нет проектов в выбранном месяце' }}</small>
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
                    :class="[mgmtCellClass(row.employee, date, row.clientId, row.projectId), { 'has-absence': rowActive(row, date) && !!absenceFor(date, row.employee.id, management), 'has-description': rowActive(row, date) && !!rowDescription(row, date), 'range-selected': cellSelected(row, date) }]"
                    :aria-label="`${date}: ${rowDescription(row, date) || 'Описание не заполнено'}`"
                    @mousedown="startSelection($event, row, date)"
                    @mouseenter="enterCell($event, row, date)"
                    @mouseleave="leaveCell"
                    @dblclick="openManagerEdit(row.employee, date, row.clientId, row.projectId)"
                  >
                    <span v-if="rowActive(row, date) && absenceFor(date, row.employee.id, management)" class="absence-half" aria-hidden="true"></span>
                    <span v-if="rowActive(row, date)"><b>{{ Number(mgmtHours(row.employee.id, date, row.clientId, row.projectId).toFixed(2)) }}</b></span>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>

      <section v-else-if="section === 'analytics' && analytics" class="analytics-page">
        <div class="kpis">
          <div class="kpi">
            <span>Сотрудников</span>
            <strong>{{ analytics.totals.employees }}</strong>
          </div>
          <div class="kpi">
            <span>Коммерческая загрузка</span>
            <strong>{{ Number(analytics.totals.commercial_hours).toFixed(1) }} / {{ Number(analytics.totals.norm_hours).toFixed(1) }} ч</strong>
            <em>{{ analytics.totals.commercial_percent }}%</em>
            <span class="analytics-note">Только финально подтверждённые часы</span>
          </div>
          <div class="chart-card">
            <div class="pie" :style="pieStyle"></div>
            <div class="chart-legend">
              <span v-for="segment in chartSegments" :key="segment.key">
                <i :style="{ background: segment.color }"></i>{{ segment.label }} — {{ segment.value }} ч</span>
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
      <form ref="managerEditor" class="modal manager-edit-modal" role="dialog" aria-modal="true" aria-label="Редактирование таймшита" @submit.prevent="saveManagerEdit">
        <div class="modal-head">
          <div><div class="eyebrow">{{ editModal.date }}</div><h2>{{ editModal.employee.full_name }}</h2><small>{{ editModal.projects[0]?.client_name }}</small></div>
          <button type="button" class="close" aria-label="Закрыть" @click="editModal = null">×</button>
        </div>
        <article v-for="project in editModal.projects" :key="project.project_id" :data-project-id="project.project_id" class="project-card">
          <strong v-if="project.project_name && project.project_name !== project.client_name">{{ project.project_name }}</strong>
          <label class="manager-hours-field">Часы<input v-model.number="project.hours" type="number" min="0" max="24" step="0.25" @keydown.enter.prevent="saveManagerEdit" /></label>
          <label>Описание<textarea v-model="project.description" rows="2"></textarea></label>
        </article>
        <button type="submit" class="manager-save" :disabled="savingManagerEdit">{{ savingManagerEdit ? 'Сохранение…' : 'Сохранить' }}</button>
      </form>
    </div>
    <Teleport to="body">
      <div v-if="cellTooltip" ref="tooltipElement" role="tooltip" class="timesheet-description-tooltip" :style="{ left: `${cellTooltip.left}px`, top: `${cellTooltip.top}px` }" @mouseenter="keepCellTooltip" @mouseleave="leaveCell">{{ cellTooltip.text }}</div>
    </Teleport>
    <div v-if="bulkModal" class="overlay" @click.self="closeBulk">
      <form class="modal bulk-hours-modal irlix-ui" role="dialog" aria-modal="true" aria-labelledby="bulk-hours-title" @submit.prevent="saveBulk">
        <div class="modal-head">
          <div><h2 id="bulk-hours-title">Массовое заполнение</h2><small>{{ bulkModal.row.employee.full_name }} · {{ bulkModal.row.projectName }}</small></div>
          <UiButton type="button" variant="ghost" aria-label="Закрыть" :disabled="savingBulk" @click="closeBulk">×</UiButton>
        </div>
        <p>{{ bulkModal.dates[0] }} — {{ bulkModal.dates.at(-1) }} · дней: {{ bulkModal.dates.length }}</p>
        <label class="irlix-field">Часы в каждом дне<input ref="bulkHoursInput" v-model="bulkModal.hours" type="number" min="0" max="24" step="0.25" required :disabled="savingBulk" /></label>
        <p class="bulk-hours-hint">Описание каждого дня останется без изменений.</p>
        <p v-if="bulkError" role="alert">{{ bulkError }}</p>
        <UiButton type="submit" :disabled="savingBulk">{{ savingBulk ? 'Заполнение…' : 'Заполнить' }}</UiButton>
      </form>
    </div>
  </div>
</template>
