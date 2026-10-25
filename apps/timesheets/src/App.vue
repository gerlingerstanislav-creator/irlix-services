<script setup>
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { UiAppTopbar, UiAppSidebar, UiButton, UiFilterBar, UiSearchSelect, UiPeriodPicker, UiViewSelect, UiRegistryTable } from '@irlix/ui';
import { auth } from './auth';
import { api } from './api';
import { clientPeriodApproved } from './approval-state.js';

const initialParams = new URLSearchParams(window.location.search);
const requestedSection = initialParams.get('section') === 'partners' ? 'management' : initialParams.get('section');
const section = ref(['mine', 'management', 'analytics', 'audit'].includes(requestedSection) ? requestedSection : 'mine');
const month = ref(/^\d{4}-(0[1-9]|1[0-2])$/.test(initialParams.get('month') || '') ? initialParams.get('month') : new Date().toISOString().slice(0, 7));
const workspace = ref(null);
const management = ref(null);
const partnerManagement = ref(null);
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
const analyticsDepartmentFilter = ref('');
const analyticsClientFilter = ref('');
// Empty selection means "Все", matching the clear behavior of the other filters.
const analyticsStaffFilter = ref('');
const analyticsStaffOptions = [
  { value: '', label: 'Все' },
  { value: 'staff', label: 'Штат' },
  { value: 'partners', label: 'Партнеры' },
];
const analyticsDepartmentOptions = computed(() => (analytics.value?.filters?.departments || [])
  .map((item) => ({ value: String(item.id), label: item.name })));
const analyticsClientOptions = computed(() => (analytics.value?.filters?.clients || [])
  .map((item) => ({ value: String(item.id), label: item.name })));
let analyticsRequestSequence = 0;
const analyticsViewOptions = [
  { value: 'employees', label: 'Сотрудники' },
  { value: 'departments', label: 'Подразделения' },
  { value: 'company', label: 'Компания' },
];
const search = ref('');
const departmentFilter = ref('');
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
const finalProjectIds = (source, date = null) => new Set((source?.final_approvals || [])
  .filter(item => !date || !(source?.final_approval_exceptions || []).some(ex =>
    Number(ex.employee_id) === Number(item.employee_id)
    && Number(ex.client_member_id) === Number(item.client_member_id) && ex.work_date === date))
  .map(item => Number(item.client_member_id)));
const dayFinal = (date, source = workspace.value) => {
  const assignments = activeAssignments(date, source);
  if (!assignments.length) return false;
  const approved = finalProjectIds(source, date);
  return assignments.every((assignment) => approved.has(Number(assignment.client_member_id)) || clientPeriodApproved(source, assignment.client_id, date));
};
const dayHasFinalApproval = (date, source = workspace.value) => {
  const approved = finalProjectIds(source, date);
  return activeAssignments(date, source).some((assignment) => approved.has(Number(assignment.client_member_id)) || clientPeriodApproved(source, assignment.client_id, date));
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
const isLockedProject = (projectId) => finalProjectIds(workspace.value, selectedDate.value).has(Number(projectId))
  || activeAssignments(selectedDate.value).some(a => Number(a.client_member_id) === Number(projectId) && clientPeriodApproved(workspace.value, a.client_id, selectedDate.value));
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
  const [staffResponse, partnersResponse] = await Promise.all([
    api(`/api/timesheets/management?month=${month.value}`),
    api(`/api/timesheets/partner-hours?month=${month.value}`),
  ]);
  management.value = staffResponse.data;
  partnerManagement.value = partnersResponse.data;
};
const loadAnalytics = async () => {
  const sequence = ++analyticsRequestSequence;
  const params = new URLSearchParams({ month: month.value, staff_type: analyticsStaffFilter.value || 'all' });
  if (analyticsDepartmentFilter.value) params.set('department_id', analyticsDepartmentFilter.value);
  if (analyticsClientFilter.value) params.set('client_id', analyticsClientFilter.value);
  const result = (await api(`/api/timesheets/analytics?${params.toString()}`)).data;
  if (sequence === analyticsRequestSequence) analytics.value = result;
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

watch([analyticsDepartmentFilter, analyticsClientFilter, analyticsStaffFilter], () => {
  if (section.value === 'analytics') refresh();
});
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
  const entry = entriesFor(selectedDate.value).find((item) => Number(item.client_member_id) === Number(assignment.client_member_id));
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
    next[assignment.client_member_id] = entryDraft(assignment);
    signatures[assignment.client_member_id] = draftSignature(next[assignment.client_member_id]);
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
  const projectId = assignment.client_member_id;
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
          client_member_id: projectId,
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
const partnerEntryFor = (clientMemberId, date) =>
  (partnerManagement.value?.entries || []).find((entry) =>
    Number(entry.client_member_id) === Number(clientMemberId) && entry.work_date === date);
const partnerActive = (clientMemberId, date) =>
  (partnerManagement.value?.members || []).some((member) =>
    Number(member.client_member_id) === Number(clientMemberId)
    && member.valid_from <= date && (!member.valid_to || member.valid_to >= date));
const partnerPeriodLocked = (clientId, date) =>
  (partnerManagement.value?.locked_reporting_periods || []).some((period) =>
    Number(period.client_id) === Number(clientId)
    && period.period_start <= date && period.period_end >= date);
const partnerFinal = (clientMemberId, clientId, date) =>
  partnerPeriodLocked(clientId, date)
  || ((partnerManagement.value?.final_approvals || []).some((a) =>
    Number(a.client_member_id) === Number(clientMemberId))
    && !(partnerManagement.value?.final_approval_exceptions || []).some((a) =>
      Number(a.client_member_id) === Number(clientMemberId) && a.work_date === date));
const partnerPrelim = (clientMemberId, date) =>
  (partnerManagement.value?.confirmations || []).some((a) =>
    Number(a.client_member_id) === Number(clientMemberId) && a.work_date === date);
const mgmtEntriesFor = (employeeId, date) =>
  (management.value?.entries || []).filter((entry) => Number(entry.employee_id) === Number(employeeId) && entry.work_date === date);
const mgmtHours = (employeeId, date, clientId, projectId = null) => {
  const projects = new Set(clientAssignments(employeeId, clientId).filter((assignment) => (!projectId || Number(assignment.client_member_id) === Number(projectId)) && assignment.valid_from <= date && (!assignment.valid_to || assignment.valid_to >= date)).map((assignment) => Number(assignment.client_member_id)));
  return mgmtEntriesFor(employeeId, date).filter((entry) => projects.has(Number(entry.client_member_id))).reduce((sum, entry) => sum + Number(entry.hours || 0), 0);
};
const mgmtPrelim = (employeeId, date) =>
  (management.value?.confirmations || []).some((item) => Number(item.employee_id) === Number(employeeId) && item.work_date === date);
const mgmtFinal = (employeeId, date, clientId, projectId = null) => {
  const projects = clientAssignments(employeeId, clientId)
    .filter((assignment) => (!projectId || Number(assignment.client_member_id) === Number(projectId)) && assignment.valid_from <= date && (!assignment.valid_to || assignment.valid_to >= date))
    .map((assignment) => Number(assignment.client_member_id));
  if (!projects.length) return false;
  if (clientPeriodApproved(management.value, clientId, date)) return true;
  const approved = new Set(
    (management.value?.final_approvals || [])
      .filter((item) => Number(item.employee_id) === Number(employeeId))
      .map((item) => Number(item.client_member_id))
  );
  const exceptions = management.value?.final_approval_exceptions || [];
  return projects.every((projectId) => approved.has(projectId) && !exceptions.some((item) =>
    Number(item.employee_id) === Number(employeeId) && Number(item.client_member_id) === projectId && item.work_date === date));
};
const mgmtCellClass = (employee, date, clientId, projectId = null) => {
  const absence = absenceFor(date, employee.id, management.value);
  const projects = clientAssignments(employee.id, clientId)
    .filter((assignment) => (!projectId || Number(assignment.client_member_id) === Number(projectId)) && assignment.valid_from <= date && (!assignment.valid_to || assignment.valid_to >= date));
  const base = !projects.length ? 'inactive' : mgmtFinal(employee.id, date, clientId, projectId) ? 'final' : mgmtPrelim(employee.id, date) ? 'prelim' : '';
  return [base, isNonWorkingDate(date) ? 'non-working' : '', projects.length && absence ? (absence.status === 'confirmed' ? 'absence-confirmed' : 'absence-pending') : ''].filter(Boolean).join(' ');
};
const isNonWorkingDate = (date) => {
  const info = productionCalendar.value[date];
  if (info) return !info.is_working;
  return [0, 6].includes(new Date(`${date}T00:00:00`).getDay());
};
const rowEntry = (row, date) => row.isPartner
  ? partnerEntryFor(row.memberId, date)
  : mgmtEntriesFor(row.employee.id, date).find(entry => Number(entry.client_member_id) === Number(row.memberId));
const rowHours = (row, date) => row.isPartner
  ? Number(partnerEntryFor(row.memberId, date)?.hours || 0)
  : mgmtHours(row.employee.id, date, row.clientId, row.memberId);
const rowTotal = (row) => monthDays.value.reduce((sum, date) => sum + rowHours(row, date), 0);
const rowFinal = (row, date) => row.isPartner
  ? partnerFinal(row.memberId, row.clientId, date)
  : mgmtFinal(row.employee.id, date, row.clientId, row.memberId);
const rowPrelim = (row, date) => row.isPartner
  ? partnerPrelim(row.memberId, date)
  : mgmtPrelim(row.employee.id, date);
const rowDescription = (row, date) => String(rowEntry(row, date)?.description || '').trim();
const clientPeriodLocked = (clientId, date) => clientPeriodApproved(management.value, clientId, date);
const rowActive = (row, date) => row.isPartner ? partnerActive(row.memberId, date)
  : clientAssignments(row.employee.id, row.clientId)
    .some(a => Number(a.client_member_id) === Number(row.memberId) && a.valid_from <= date && (!a.valid_to || a.valid_to >= date));
const rowCellClass = (row, date) => row.isPartner
  ? [!rowActive(row, date) ? 'inactive' : rowFinal(row, date) ? 'final' : rowPrelim(row, date) ? 'prelim' : '',
     isNonWorkingDate(date) ? 'non-working' : ''].filter(Boolean).join(' ')
  : mgmtCellClass(row.employee, date, row.clientId, row.memberId);
const sameRow = (a, b) => a && b && a.employee.id === b.employee.id && a.memberId === b.memberId && a.clientId === b.clientId;
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
  if (event.button !== 0 || event.detail > 1 || row.isPartner || !can('timesheets.management.manage') || !rowActive(row, date) || savingBulk.value) return;
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
      employee_id: edit.row.employee.id, client_member_id: edit.row.memberId, dates: edit.dates, hours,
    } });
    bulkModal.value = null;
    cancelSelection();
    await loadManagement();
    toast('Часы заполнены. Подтверждение нужно повторить только для изменённых дней.');
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
watch([search, departmentFilter, accountFilter, clientFilter, employeeFilter], () => {
  cancelSelection(); cellTooltip.value = null;
});
const employeeTotal = (employeeId, clientId, projectId = null) => monthDays.value.reduce((sum, date) => sum + mgmtHours(employeeId, date, clientId, projectId), 0);
const projectMonthApprovalState = (employeeId, clientId, projectId) => {
  if (!projectId) return { all: false, any: false };

  const activeDates = monthDays.value.filter((date) => clientAssignments(employeeId, clientId)
    .some((assignment) => Number(assignment.client_member_id) === Number(projectId)
      && assignment.valid_from <= date
      && (!assignment.valid_to || assignment.valid_to >= date)));
  const approvedDates = activeDates.filter((date) => mgmtFinal(employeeId, date, clientId, projectId));

  return {
    all: activeDates.length > 0 && approvedDates.length === activeDates.length,
    any: approvedDates.length > 0,
  };
};
const finalApprove = async (row, approved) => {
  try {
    const url = row.isPartner ? '/api/timesheets/partner-hours/final-approval' : '/api/timesheets/management/final-approval';
    await api(url, {
      method: 'POST',
      body: {
        ...(!row.isPartner ? { employee_id: row.employee.id } : {}),
        client_member_id: row.memberId, month: month.value, approved,
      },
    });
    await loadManagement();
    toast(approved ? 'Финальное подтверждение установлено' : 'Финальное подтверждение снято');
  } catch (e) {
    toast(e.message, true);
  }
};
const openManagerEdit = async (row, date) => {
  if (!can('timesheets.management.manage') || !rowActive(row, date)) return;
  cancelSelection();
  cellTooltip.value = null;
  if (row.isPartner) {
    const member = (partnerManagement.value?.members || []).find((a) =>
      Number(a.client_member_id) === Number(row.memberId) && a.valid_from <= date && (!a.valid_to || a.valid_to >= date));
    if (!member) return;
    const entry = partnerEntryFor(row.memberId, date);
    editModal.value = {
      isPartner: true, employee: row.employee, date, clientId: row.clientId,
      memberId: row.memberId, clientName: row.clientName,
      projects: [{ ...member, hours: Number(entry?.hours || 0), description: entry?.description || '' }],
    };
    await nextTick();
    managerEditor.value?.querySelector('input')?.focus();
    return;
  }
  const { employee, clientId, memberId: projectId } = row;
  const projects = [...new Map(clientAssignments(employee.id, clientId)
    .filter((assignment) => assignment.valid_from <= date && (!assignment.valid_to || assignment.valid_to >= date))
    .map((assignment) => [Number(assignment.client_member_id), assignment])).values()]
    .map((assignment) => {
      const entry = mgmtEntriesFor(employee.id, date)
        .find((item) => Number(item.client_member_id) === Number(assignment.client_member_id));
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
    if (edit.isPartner) {
      const record = edit.projects[0];
      await api('/api/timesheets/partner-hours', {
        method: 'PUT',
        body: {
          client_member_id: edit.memberId, work_date: edit.date,
          hours: Number(record.hours || 0), description: record.description || null,
        },
      });
    } else {
      await api('/api/timesheets/management/entries', {
        method: 'PUT',
        body: {
          employee_id: edit.employee.id,
          work_date: edit.date,
          entries: edit.projects.map(row => ({
            client_member_id: row.client_member_id,
            hours: Number(row.hours || 0),
            description: row.description,
          })),
        },
      });
    }
    await loadManagement();
    editModal.value = null;
    toast('День изменён. Подтверждение нужно повторить только для этого дня.');
  } catch (e) {
    toast(e.message, true);
  } finally {
    savingManagerEdit.value = false;
  }
};
const togglePartnerConfirm = async () => {
  const edit = editModal.value;
  if (!edit?.isPartner || savingManagerEdit.value || partnerPeriodLocked(edit.clientId, edit.date)
      || rowFinal({ isPartner: true, memberId: edit.memberId, clientId: edit.clientId }, edit.date)) return;
  const saved = partnerEntryFor(edit.memberId, edit.date);
  if (Number(saved?.hours || 0) !== Number(edit.projects[0]?.hours || 0)
      || String(saved?.description || '') !== String(edit.projects[0]?.description || '')) {
    toast('Сначала сохраните изменения часов и описания.', true);
    return;
  }
  savingManagerEdit.value = true;
  try {
    await api('/api/timesheets/partner-hours/confirm', { method: 'POST', body: {
      client_member_id: edit.memberId, work_date: edit.date,
      confirmed: !partnerPrelim(edit.memberId, edit.date),
    } });
    await loadManagement();
    editModal.value = null;
    toast('Предварительное подтверждение партнёра обновлено');
  } catch (e) { toast(e.message, true); }
  finally { savingManagerEdit.value = false; }
};
const rowMonthApprovalState = (row) => {
  if (!row.isPartner) return projectMonthApprovalState(row.employee.id, row.clientId, row.memberId);
  const activeDates = monthDays.value.filter((date) => rowActive(row, date));
  const approvedDates = activeDates.filter((date) => rowFinal(row, date));
  return { all: activeDates.length > 0 && approvedDates.length === activeDates.length, any: approvedDates.length > 0 };
};
const accountOptions = computed(() => {
  const ids = [...new Set(
    [...(management.value?.assignments || []), ...(partnerManagement.value?.members || [])]
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
const clientOptions = computed(() => [...new Map(
  [...(management.value?.assignments || []), ...(partnerManagement.value?.members || [])]
    .map((assignment) => [Number(assignment.client_id), { value: String(assignment.client_id), label: assignment.client_name }])
).values()].sort((a, b) => a.label.localeCompare(b.label, 'ru')));
const employeeOptions = computed(() => {
  const staff = (management.value?.employees || []).map((employee) =>
    ({ value: String(employee.id), label: employee.full_name }));
  const partners = [...new Map((partnerManagement.value?.members || []).map((item) =>
    [String(item.partner_specialist_id), {
      value: `partner:${item.partner_specialist_id}`, label: `${item.specialist_name} (партнёр)`,
    }])).values()];
  return [...staff, ...partners].sort((a, b) => a.label.localeCompare(b.label, 'ru'));
});
const accountFilterOptions = computed(() => accountOptions.value.map((account) => ({ value: String(account.id), label: account.name })));
const departmentFilterOptions = computed(() => (management.value?.departments || []).map((department) => ({ value: String(department.id), label: department.name })));
const filteredEmployees = computed(() => (management.value?.employees || []).filter((employee) => {
  const text = `${employee.full_name || ''} ${employee.department_name || ''}`.toLowerCase();
  if (employeeFilter.value && String(employee.id) !== String(employeeFilter.value)) return false;
  if (search.value && !text.includes(search.value.toLowerCase())) return false;
  if (departmentFilter.value && String(employee.department_id) !== String(departmentFilter.value)) return false;
  if (accountFilter.value && !visibleAssignmentsForEmployee(employee.id)
    .some((assignment) => String(assignment.account_employee_id) === String(accountFilter.value))) return false;
  return true;
}));
const managementRows = computed(() => {
  const staff = filteredEmployees.value.flatMap((employee) => {
    const assignments = visibleAssignmentsForEmployee(employee.id);
    return [...new Map(assignments.map((assignment) => [Number(assignment.client_member_id), {
      employee, clientId: Number(assignment.client_id), clientName: assignment.client_name,
      memberId: Number(assignment.client_member_id), memberName: assignment.specialist_name,
    }])).values()]
      .filter((row) => !clientFilter.value || String(row.clientId) === String(clientFilter.value))
      .filter((row) => !accountFilter.value || String((assignments.find((a) => Number(a.client_member_id) === row.memberId)?.account_employee_id || '')) === String(accountFilter.value));
  });
  // Partners have a different ID namespace and no department in Employees.
  const partners = [...new Map((partnerManagement.value?.members || [])
    .map((item) => [Number(item.client_member_id), item])).values()]
    .filter((item) => !clientFilter.value || String(item.client_id) === String(clientFilter.value))
    .filter((item) => !accountFilter.value || String(item.account_employee_id) === String(accountFilter.value))
    .filter((item) => !departmentFilter.value)
    .filter((item) => !employeeFilter.value || employeeFilter.value === `partner:${item.partner_specialist_id}`)
    .filter((item) => !search.value ||
      `${item.specialist_name || ''} ${item.client_name || ''} партнёр`.toLowerCase().includes(search.value.toLowerCase()))
    .map((item) => ({
      isPartner: true,
      employee: { id: `partner:${item.partner_specialist_id}`, full_name: item.specialist_name },
      clientId: Number(item.client_id), clientName: item.client_name,
      memberId: Number(item.client_member_id), memberName: item.specialist_name,
    }));
  return [...staff, ...partners];
});

const analyticsRows = computed(() => {
  if (analyticsMode.value === 'departments') return analytics.value?.departments || [];
  if (analyticsMode.value === 'company') {
    return analytics.value?.totals
      ? [{ ...analytics.value.totals, employee_name: 'Компания', department_name: 'Компания' }]
      : [];
  }
  return [...(analytics.value?.employees || []), ...(analytics.value?.partners || [])];
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
const chartMainSegments = computed(() => chartSegments.value.filter(
  (segment) => segment.key === 'commercial' || segment.key === 'idle'
));
const chartAbsenceSegments = computed(() => chartSegments.value.filter(
  (segment) => segment.key !== 'commercial' && segment.key !== 'idle'
));
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

const analyticsRegistryColumns = computed(() => [
  '270px', '210px', '90px', '150px', '150px',
]);
const analyticsRegistryMinWidth = computed(() => analyticsRegistryColumns.value.reduce((sum, width) => sum + parseInt(width, 10), 0) + 'px');
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

          <article v-for="assignment in activeAssignments(selectedDate)" :key="assignment.client_member_id" class="project-card">
            <div>
              <strong>{{ assignment.client_name }}</strong>
              <span>{{ assignment.client_name }}</span>
            </div>
            <label>
              Часы
              <input
                v-if="drafts[assignment.client_member_id]"
                v-model.number="drafts[assignment.client_member_id].hours"
                type="number"
                min="0"
                max="24"
                step="0.25"
                :disabled="isLockedProject(assignment.client_member_id)"
                @blur="saveEntry(assignment)"
              />
            </label>
            <label>
              Описание
              <textarea
                v-if="drafts[assignment.client_member_id]"
                v-model="drafts[assignment.client_member_id].description"
                rows="4"
                placeholder="Что было сделано"
                :disabled="isLockedProject(assignment.client_member_id)"
                @blur="saveEntry(assignment)"
              ></textarea>
            </label>
            <small v-if="savingProjects.has(assignment.client_member_id)" class="autosave-state">Сохранение…</small>
            <small v-if="isLockedProject(assignment.client_member_id)" class="locked">
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
                  :key="`${row.isPartner ? 'partner' : 'staff'}-${row.employee.id}-${row.memberId}`"
                >
                  <td class="sticky name">
                    <strong>{{ String(row.employee.full_name || '').trim().split(/\s+/).slice(0, 2).join(' ') }}</strong>
                    <small>{{ row.isPartner ? 'Партнёр · ' : '' }}{{ row.clientName }} · {{ row.memberName }}</small>
                  </td>
                  <td class="sticky action">
                    <div class="approval-actions">
                      <button
                        class="icon-btn ok"
                        title="Финально подтвердить специалиста за месяц"
                        aria-label="Финально подтвердить специалиста за месяц"
                        :disabled="!can('timesheets.management.manage') || !row.memberId || rowMonthApprovalState(row).all"
                        @click="finalApprove(row, true)"
                      >✓</button>
                      <button
                        class="icon-btn danger"
                        title="Снять финальное подтверждение специалиста за месяц"
                        aria-label="Снять финальное подтверждение специалиста за месяц"
                        :disabled="!can('timesheets.management.manage') || !row.memberId || !rowMonthApprovalState(row).any"
                        @click="finalApprove(row, false)"
                      >×</button>
                    </div>
                  </td>
                  <td class="sticky total"><strong>{{ rowTotal(row).toFixed(2) }}</strong></td>
                  <td
                    v-for="date in monthDays"
                    :key="date"
                    class="matrix-cell"
                    :class="[rowCellClass(row, date), { 'has-absence': !row.isPartner && rowActive(row, date) && !!absenceFor(date, row.employee.id, management), 'has-description': rowActive(row, date) && !!rowDescription(row, date), 'client-locked': rowActive(row, date) && (row.isPartner ? partnerPeriodLocked(row.clientId, date) : clientPeriodLocked(row.clientId, date)), 'range-selected': cellSelected(row, date) }]"
                    :aria-label="`${date}: ${rowDescription(row, date) || 'Описание не заполнено'}`"
                    @mousedown="startSelection($event, row, date)"
                    @mouseenter="enterCell($event, row, date)"
                    @mouseleave="leaveCell"
                    @dblclick="openManagerEdit(row, date)"
                  >
                    <span v-if="!row.isPartner && rowActive(row, date) && absenceFor(date, row.employee.id, management)" class="absence-half" aria-hidden="true"></span>
                     <svg v-if="rowActive(row, date) && (row.isPartner ? partnerPeriodLocked(row.clientId, date) : clientPeriodLocked(row.clientId, date))" class="client-lock" viewBox="0 0 12 14" aria-label="Отправлено на согласование или согласовано клиентом" role="img">
                       <path d="M3.5 6V4a2.5 2.5 0 0 1 5 0v2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                       <rect x="1.5" y="6" width="9" height="7" rx="1.5" fill="currentColor"/>
                     </svg>
                    <span v-if="rowActive(row, date)"><b>{{ Number(rowHours(row, date).toFixed(2)) }}</b></span>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>

      

      <section v-else-if="section === 'analytics' && analytics" class="analytics-page">
        <UiFilterBar class="analytics-filters">
          <UiSearchSelect v-model="analyticsDepartmentFilter" :options="analyticsDepartmentOptions"
            placeholder="Подразделение: все" search-placeholder="Поиск подразделения" aria-label="Фильтр по подразделению" />
          <UiSearchSelect v-model="analyticsClientFilter" :options="analyticsClientOptions"
            placeholder="Клиент: все" search-placeholder="Поиск клиента" aria-label="Фильтр по клиенту" />
          <UiSearchSelect v-model="analyticsStaffFilter" :options="analyticsStaffOptions"
            placeholder="Все" search-placeholder="Поиск типа специалиста" aria-label="Штат" />
        </UiFilterBar>
        <div class="kpis">
          <div class="kpi">
            <span>Специалистов</span>
            <strong>{{ analytics.totals.employees }}</strong>
          </div>
          <div class="kpi">
            <span>Коммерческая загрузка</span>
            <strong>{{ Number(analytics.totals.commercial_hours).toFixed(1) }} / {{ Number(analytics.totals.norm_hours).toFixed(1) }} ч</strong>
            <em>{{ analytics.totals.commercial_percent }}%</em>
          </div>
          <div class="chart-card">
            <div class="pie" :style="pieStyle"></div>
            <div class="chart-legend" aria-label="Показатели коммерческой загрузки">
              <div class="chart-legend-column">
                <span v-for="segment in chartMainSegments" :key="segment.key">
                  <i :style="{ background: segment.color }"></i>{{ segment.label }} — {{ segment.value }} ч
                </span>
              </div>
              <div class="chart-legend-column">
                <span v-for="segment in chartAbsenceSegments" :key="segment.key">
                  <i :style="{ background: segment.color }"></i>{{ segment.label }} — {{ segment.value }} ч
                </span>
              </div>
            </div>
          </div>
        </div>

        <div class="table-wrap">
          <UiRegistryTable class="shared-registry-content" :columns="analyticsRegistryColumns" :min-width="analyticsRegistryMinWidth" aria-label="Коммерческая загрузка" density="compact">
            <template #head>
              <tr>
                <th>{{ analyticsMode === 'employees' ? 'Сотрудник' : analyticsMode === 'departments' ? 'Подразделение' : 'Компания' }}</th>
                <th>Ком. загрузка / норма, ч</th>
                <th>%</th>
                <th>Отсутствия, ч</th>
                <th>Простой, ч</th>
              </tr>
            </template>
            
              <tr v-for="row in analyticsRows" :key="row.identity_type === 'partner' ? `partner:${row.partner_specialist_id}` : row.employee_id || row.department_id || 'company'">
                <td>{{ row.employee_name || row.department_name }}
                  <small v-if="row.identity_type === 'partner'">Партнёр · {{ row.client_name }}</small></td>
                <td>{{ Number(row.commercial_hours).toFixed(1) }} / {{ Number(row.norm_hours || 0).toFixed(1) }}</td>
                <td><b>{{ row.commercial_percent === null ? '—' : row.commercial_percent + '%' }}</b></td>
                <td>{{ Number(row.absence_hours || 0).toFixed(1) }}</td>
                <td>{{ row.idle_hours === null ? '—' : Number(row.idle_hours).toFixed(1) }}</td>
              </tr>
            
          </UiRegistryTable>
        </div>
      </section>

      <section v-else-if="section === 'audit'" class="audit-page">
        <div class="table-wrap">
          <UiRegistryTable class="shared-registry-content" :columns="['20%','20%','15%','15%','15%','15%']" min-width="1000px" aria-label="История изменений таймшитов" density="compact">
            <template #head><tr><th>Дата</th><th>Действие</th><th>Кто</th><th>Сотрудник</th><th>Проект</th><th>День</th></tr></template>
            
              <tr v-for="row in auditRows" :key="row.id">
                <td>{{ new Date(row.created_at).toLocaleString('ru-RU') }}</td>
                <td>{{ auditActionLabel(row.action) }}</td>
                <td>{{ row.actor_employee_id ? `#${row.actor_employee_id}` : 'Система' }}</td>
                <td>{{ row.employee_id ? `#${row.employee_id}` : '—' }}</td>
                <td>{{ row.client_member_id ? `#${row.client_member_id}` : '—' }}</td>
                <td>{{ row.work_date || '—' }}</td>
              </tr>
            
          </UiRegistryTable>
        </div>
      </section>
    </main>

    <div v-if="editModal" class="overlay" @click.self="editModal = null">
      <form ref="managerEditor" class="modal manager-edit-modal" role="dialog" aria-modal="true" aria-label="Редактирование таймшита" @submit.prevent="saveManagerEdit">
        <div class="modal-head">
          <div><div class="eyebrow">{{ editModal.date }}</div><h2>{{ editModal.employee.full_name }}</h2><small>{{ editModal.isPartner ? 'Партнёр · ' : '' }}{{ editModal.projects[0]?.client_name }}</small></div>
          <button type="button" class="close" aria-label="Закрыть" @click="editModal = null">×</button>
        </div>
        <article v-for="project in editModal.projects" :key="project.client_member_id" :data-project-id="project.client_member_id" class="project-card">
          <strong v-if="project.project_name && project.project_name !== project.client_name">{{ project.project_name }}</strong>
          <label class="manager-hours-field">Часы<input v-model.number="project.hours" type="number" min="0" max="24" step="0.25" @keydown.enter.prevent="saveManagerEdit" /></label>
          <label>Описание<textarea v-model="project.description" rows="2"></textarea></label>
        </article>
        <button v-if="editModal.isPartner" type="button" class="manager-save"
          :disabled="savingManagerEdit || partnerPeriodLocked(editModal.clientId, editModal.date) || partnerFinal(editModal.memberId, editModal.clientId, editModal.date)"
          @click="togglePartnerConfirm">
          {{ partnerPrelim(editModal.memberId, editModal.date) ? 'Снять предварительное подтверждение' : 'Подтвердить день партнёра' }}
        </button>
        <button type="submit" class="manager-save" :disabled="savingManagerEdit">{{ savingManagerEdit ? 'Сохранение…' : 'Сохранить' }}</button>
      </form>
    </div>
    <Teleport to="body">
      <div v-if="cellTooltip" ref="tooltipElement" role="tooltip" class="timesheet-description-tooltip" :style="{ left: `${cellTooltip.left}px`, top: `${cellTooltip.top}px` }" @mouseenter="keepCellTooltip" @mouseleave="leaveCell">{{ cellTooltip.text }}</div>
    </Teleport>
    <div v-if="bulkModal" class="overlay" @click.self="closeBulk">
      <form class="modal bulk-hours-modal irlix-ui" role="dialog" aria-modal="true" aria-labelledby="bulk-hours-title" @submit.prevent="saveBulk">
        <div class="modal-head">
          <div><h2 id="bulk-hours-title">Массовое заполнение</h2><small>{{ bulkModal.row.employee.full_name }} · {{ bulkModal.row.clientName }} / {{ bulkModal.row.memberName }}</small></div>
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
