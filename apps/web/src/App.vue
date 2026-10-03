<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { UiAppTopbar, UiBadge, UiButton, UiFilterRail, UiPanel, UiSearchSelect } from '@irlix/ui';
import { auth } from './auth';
import AppSidebar from './components/AppSidebar.vue';
import AuditLogView from './components/AuditLogView.vue';
import EmployeeCardDrawer from './components/EmployeeCardDrawer.vue';
import NewEmployeeModal from './components/NewEmployeeModal.vue';
import SpecialRolesView from './components/SpecialRolesView.vue';
import StaffPositionsView from './components/StaffPositionsView.vue';

const SECTION_PATHS = Object.freeze({
  employees: '/employees/',
  departments: '/employees/departments',
  positions: '/employees/staff-positions',
  roles: '/employees/roles',
  audit: '/employees/audit',
});
const TOPBAR_ITEMS = Object.freeze([
  { id: 'employees', label: 'Сотрудники' },
  { id: 'departments', label: 'Подразделения' },
  { id: 'positions', label: 'Штатное расписание' },
  { id: 'roles', label: 'Роли' },
  { id: 'audit', label: 'История действий' },
]);

const currentSection = ref('employees');
const employees = ref([]);
const departments = ref([]);
const positions = ref([]);
const referenceData = ref({ employee_statuses: [], work_formats: [], cooperation_types: [], genders: [] });
const access = ref({ allowed: false, permissions: {}, roles: [], scope: 'none' });
const accessLoaded = ref(false);
const loading = ref(false);
const error = ref('');
const search = ref('');
const departmentFilter = ref('');
const statusFilter = ref('');
const showNewEmployee = ref(false);
const selectedEmployeeId = ref(null);
const showDepartmentForm = ref(false);
const editingDepartmentId = ref(null);
const departmentForm = ref(emptyDepartment());
const collapsedDepartments = ref(new Set());
const staffPositionsRef = ref(null);
const specialRolesRef = ref(null);

const canManageEmployees = computed(() => Boolean(access.value.permissions?.['employees.manage']));
const canManageOrganization = computed(() => Boolean(access.value.permissions?.['organization.manage']));
const canManagePositions = computed(() => Boolean(access.value.permissions?.['staff_positions.manage']));
const canManageAccess = computed(() => Boolean(access.value.permissions?.['access.manage']));
const canReadAudit = computed(() => Boolean(access.value.permissions?.['audit.read']));
const isPlatformAdmin = computed(() => (access.value.roles ?? []).includes('platform-admin'));
const activePositions = computed(() => positions.value.filter((position) => !position.closed_at));
const directions = computed(() => departments.value.filter((department) => Boolean(department.is_production)));
const departmentFilterOptions = computed(() => departments.value.map((department) => ({ value: department.id, label: department.name })));
const statusFilterOptions = computed(() => referenceData.value.employee_statuses.map((status) => ({ value: status, label: status })));
const selectedDepartmentLabel = computed(() => departmentFilterOptions.value.find((option) => String(option.value) === String(departmentFilter.value))?.label || '');
const employeeFilterItems = computed(() => [
  { id: 'search', label: 'Поиск', icon: 'search', active: Boolean(search.value.trim()), valueLabel: search.value.trim() },
  { id: 'department', label: 'Подразделение', icon: 'building', active: Boolean(departmentFilter.value), valueLabel: selectedDepartmentLabel.value },
  { id: 'status', label: 'Статус', icon: 'status', active: Boolean(statusFilter.value), valueLabel: statusFilter.value },
]);

function emptyDepartment() {
  return { name: '', alias: '', parent_id: '', manager_id: '', hr_id: '', yandex_id: '', ldap_group: '', is_production: false };
}

const filteredEmployees = computed(() => {
  const needle = search.value.trim().toLowerCase();
  return employees.value.filter((employee) => {
    const matchesSearch = !needle || employee.full_name?.toLowerCase().includes(needle) || employee.position?.toLowerCase().includes(needle) || employee.login?.toLowerCase().includes(needle);
    const matchesDepartment = !departmentFilter.value || String(employee.department_id ?? '') === String(departmentFilter.value);
    const matchesStatus = !statusFilter.value || employee.employment_status === statusFilter.value;
    return matchesSearch && matchesDepartment && matchesStatus;
  });
});

const flattenedDepartmentTree = computed(() => {
  const byParent = new Map();
  departments.value.forEach((department) => {
    const key = department.parent_id == null ? 'root' : String(department.parent_id);
    if (!byParent.has(key)) byParent.set(key, []);
    byParent.get(key).push(department);
  });
  const result = [];
  const visit = (key = 'root', level = 0) => {
    (byParent.get(key) ?? []).forEach((department) => {
      const children = byParent.get(String(department.id)) ?? [];
      const collapsed = collapsedDepartments.value.has(department.id);
      result.push({ ...department, level, hasChildren: children.length > 0, collapsed });
      if (!collapsed) visit(String(department.id), level + 1);
    });
  };
  visit();
  return result;
});

const toggleDepartment = (id) => {
  const next = new Set(collapsedDepartments.value);
  if (next.has(id)) next.delete(id); else next.add(id);
  collapsedDepartments.value = next;
};

const normalizedPath = () => {
  const pathname = window.location.pathname.replace(/\/+$/, '').toLowerCase();
  return pathname || '/';
};

const sectionFromPath = (path) => {
  if (path.endsWith('/staff-positions') || path.endsWith('/positions')) return 'positions';
  if (path.endsWith('/departments')) return 'departments';
  if (path.endsWith('/roles')) return 'roles';
  if (path.endsWith('/audit')) return 'audit';
  return 'employees';
};

const syncSectionFromLocation = () => {
  const section = sectionFromPath(normalizedPath());
  currentSection.value = section;

  if (section === 'employees') {
    const params = new URLSearchParams(window.location.search);
    const departmentId = params.get('department_id');
    departmentFilter.value = departmentId || '';
    if (departmentId) {
      search.value = '';
      statusFilter.value = '';
    }
  }
};

const navigateToSection = (section) => {
  const target = SECTION_PATHS[section] || SECTION_PATHS.employees;
  const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (currentUrl !== target) window.history.pushState({}, '', target);
  syncSectionFromLocation();
};

const resetEmployeeFilters = () => {
  search.value = '';
  departmentFilter.value = '';
  statusFilter.value = '';
  if (currentSection.value === 'employees' && window.location.search) window.history.replaceState({}, '', SECTION_PATHS.employees);
};

const employeesUrlForDepartment = (departmentId) => `/employees/?department_id=${encodeURIComponent(departmentId)}`;
const handlePopState = () => syncSectionFromLocation();
const openCreateStaffPosition = () => staffPositionsRef.value?.openCreate?.();
const openAssignRole = () => specialRolesRef.value?.openAssign?.();

const api = async (url, options = {}) => {
  const response = await auth.fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers ?? {}) } });
  const payload = await response.json().catch(() => ({}));
  if (response.status === 401) throw new Error(payload.message || 'Сервис отклонил текущую сессию авторизации (401). Выполните выход и войдите заново.');
  if (response.status === 403) throw new Error(payload.message || 'Недостаточно прав для доступа к данным Employees (403).');
  if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
  return payload;
};

const loadEmployees = async () => {
  loading.value = true; error.value = '';
  try {
    const accessPayload = await api('/api/employees/access/me');
    access.value = accessPayload.data ?? access.value;
    accessLoaded.value = true;
    if (!access.value.allowed) return;
    const [employeesPayload, departmentsPayload, positionsPayload, referencesPayload] = await Promise.all([
      api('/api/employees/employees'), api('/api/employees/departments'), api('/api/employees/staff-positions'), api('/api/employees/reference-data'),
    ]);
    employees.value = employeesPayload.data ?? [];
    departments.value = departmentsPayload.data ?? [];
    positions.value = positionsPayload.data ?? [];
    referenceData.value = referencesPayload.data ?? referenceData.value;
  } catch (e) { error.value = e.message; accessLoaded.value = true; }
  finally { loading.value = false; }
};

const employeeCreated = async (employee) => { showNewEmployee.value = false; await loadEmployees(); selectedEmployeeId.value = employee.id; };
const openCreateDepartment = () => { editingDepartmentId.value = null; departmentForm.value = emptyDepartment(); showDepartmentForm.value = true; };
const openEditDepartment = (department) => {
  if (!canManageOrganization.value) return;
  editingDepartmentId.value = department.id;
  departmentForm.value = { name: department.name ?? '', alias: department.alias ?? '', parent_id: department.parent_id ?? '', manager_id: department.manager_id ?? '', hr_id: department.hr_id ?? '', yandex_id: department.yandex_id ?? '', ldap_group: department.ldap_group ?? '', is_production: Boolean(department.is_production) };
  showDepartmentForm.value = true;
};
const saveDepartment = async () => {
  error.value = '';
  const payload = { name: departmentForm.value.name, alias: departmentForm.value.alias || null, parent_id: departmentForm.value.parent_id || null, manager_id: departmentForm.value.manager_id || null, hr_id: departmentForm.value.hr_id || null, yandex_id: departmentForm.value.yandex_id === '' ? null : Number(departmentForm.value.yandex_id), ldap_group: departmentForm.value.ldap_group || null, is_production: Boolean(departmentForm.value.is_production) };
  try {
    const url = editingDepartmentId.value ? `/api/employees/departments/${editingDepartmentId.value}` : '/api/employees/departments';
    await api(url, { method: editingDepartmentId.value ? 'PUT' : 'POST', body: JSON.stringify(payload) });
    showDepartmentForm.value = false; await loadEmployees();
  } catch (e) { error.value = e.message; }
};
const deleteDepartment = async () => {
  if (!editingDepartmentId.value || !isPlatformAdmin.value) return;
  const name = departmentForm.value.name || 'подразделение';
  const confirmed = window.confirm(`Полностью удалить подразделение «${name}»? Это необратимое действие. Удаление разрешено только для подразделения без сотрудников, дочерних подразделений и должностей штатного расписания.`);
  if (!confirmed) return;
  const code = window.prompt('Для подтверждения полного удаления введите контрольный код:');
  if (code === null) return;
  error.value = '';
  try {
    await api(`/api/employees/departments/${editingDepartmentId.value}`, { method: 'DELETE', body: JSON.stringify({ confirmation_code: code }) });
    showDepartmentForm.value = false;
    editingDepartmentId.value = null;
    await loadEmployees();
  } catch (e) { error.value = e.message; }
};

onMounted(() => {
  syncSectionFromLocation();
  window.addEventListener('popstate', handlePopState);
  loadEmployees();
});
onBeforeUnmount(() => window.removeEventListener('popstate', handlePopState));
</script>

<template>
  <div class="app-shell irlix-ui">
    <AppSidebar
      :section="currentSection"
      :can-read-audit="canReadAudit"
      :can-manage-roles="canManageAccess"
      @update:section="navigateToSection"
    />

    <main class="workspace">
      <UiAppTopbar service="employees" :section="currentSection" :items="TOPBAR_ITEMS" :loading="loading">
        <template #actions>
          <div class="employees-topbar-summary">
            <span v-if="currentSection === 'employees'"><strong>{{ filteredEmployees.length }}</strong> из {{ employees.length }} сотрудников</span>
            <span v-else-if="currentSection === 'departments'"><strong>{{ departments.length }}</strong> подразделений</span>
            <span v-else-if="currentSection === 'positions'"><strong>{{ activePositions.length }}</strong> открытых · {{ positions.length }} всего</span>
          </div>
          <UiButton v-if="currentSection === 'employees' && canManageEmployees" @click="showNewEmployee = true">+ Сотрудник</UiButton>
          <UiButton v-else-if="currentSection === 'departments' && canManageOrganization" @click="openCreateDepartment">+ Подразделение</UiButton>
          <UiButton v-else-if="currentSection === 'positions' && canManagePositions" @click="openCreateStaffPosition">+ Должность</UiButton>
          <UiButton v-else-if="currentSection === 'roles' && canManageAccess" @click="openAssignRole">+ Назначить</UiButton>
        </template>
      </UiAppTopbar>

      <div v-if="accessLoaded && !access.allowed" class="empty-state access-denied">
        <strong>Нет доступа к Employees</strong>
        <span>Обычные сотрудники не работают с этим сервисом. Доступ предоставляется руководителям, HR, Finance и администраторам компании.</span>
      </div>

      <template v-if="access.allowed && currentSection === 'employees'">
        <div v-if="error" class="alert">{{ error }}</div>
        <UiPanel class="registry-scroll-panel">
          <div v-if="loading" class="empty-state">Загрузка…</div>
          <div v-else-if="!filteredEmployees.length" class="empty-state"><strong>Сотрудников в доступном scope нет</strong><UiButton v-if="canManageEmployees" @click="showNewEmployee = true">Добавить сотрудника</UiButton></div>
          <div v-else class="table-wrap"><table class="irlix-data-table employee-table"><thead><tr><th>Сотрудник</th><th>Подразделение</th><th>Должность</th><th>Статус</th><th>Тип</th><th>Формат</th></tr></thead><tbody><tr v-for="employee in filteredEmployees" :key="employee.id" class="clickable-row" @click="selectedEmployeeId = employee.id"><td><strong>{{ employee.full_name }}</strong><small class="employee-login">{{ employee.work_email || employee.login || '—' }}</small></td><td>{{ employee.department_name || '—' }}</td><td>{{ employee.position || '—' }}</td><td><UiBadge tone="success">{{ employee.employment_status || '—' }}</UiBadge></td><td>{{ employee.cooperation_type || '—' }}</td><td>{{ employee.work_format || '—' }}</td></tr></tbody></table></div>
        </UiPanel>
      </template>

      <template v-if="access.allowed && currentSection === 'departments'">
        <div v-if="error" class="alert">{{ error }}</div>
        <UiPanel class="registry-scroll-panel"><div v-if="loading" class="empty-state">Загрузка…</div><div v-else class="table-wrap organization-table-wrap"><table class="irlix-data-table organization-table"><thead><tr><th>◇ Название / Алиас</th><th>♙ Руководитель</th><th>♙ HR</th><th>♧ Сотрудники</th><th>◇ ID (Яндекс)</th><th>◇ Группа (LDAP)</th><th /></tr></thead><tbody><tr v-for="department in flattenedDepartmentTree" :key="department.id"><td><div class="org-name" :style="{ paddingLeft: `${department.level * 18}px` }"><button v-if="department.hasChildren" class="tree-chevron" type="button" :aria-label="department.collapsed ? 'Развернуть' : 'Свернуть'" @click.stop="toggleDepartment(department.id)">{{ department.collapsed ? '›' : '⌄' }}</button><span v-else class="tree-chevron-placeholder" /><span>{{ department.name }}<small v-if="department.alias"> / {{ department.alias }}</small></span><UiBadge v-if="department.is_production" tone="info">Производственное</UiBadge></div></td><td>{{ department.manager_name || '—' }}</td><td>{{ department.hr_name || '—' }}</td><td><a :href="employeesUrlForDepartment(department.id)" :aria-label="`Показать сотрудников подразделения ${department.name}`">{{ department.employee_count }}</a></td><td>{{ department.yandex_id ?? '—' }}</td><td>{{ department.ldap_group || '—' }}</td><td><UiButton v-if="canManageOrganization" variant="secondary" compact @click="openEditDepartment(department)">✎</UiButton></td></tr></tbody></table></div></UiPanel>
      </template>

      <div v-if="access.allowed && currentSection === 'positions'" class="staffing-route">
        <div v-if="error" class="alert">{{ error }}</div>
        <StaffPositionsView ref="staffPositionsRef" :positions="positions" :departments="departments" :can-manage="canManagePositions" @updated="loadEmployees" />
      </div>
      <SpecialRolesView ref="specialRolesRef" v-if="access.allowed && canManageAccess && currentSection === 'roles'" :employees="employees" />
      <AuditLogView v-if="access.allowed && canReadAudit && currentSection === 'audit'" :employees="employees" />
    </main>

    <UiFilterRail v-if="access.allowed && currentSection === 'employees'" :items="employeeFilterItems" @reset="resetEmployeeFilters">
      <label class="irlix-field"><span>Поиск</span><input v-model="search" type="search" placeholder="Имя, логин или должность" /></label>
      <label class="irlix-field"><span>Подразделение</span><UiSearchSelect v-model="departmentFilter" :options="departmentFilterOptions" placeholder="Все подразделения" search-placeholder="Поиск подразделения" /></label>
      <label class="irlix-field"><span>Статус</span><UiSearchSelect v-model="statusFilter" :options="statusFilterOptions" placeholder="Все статусы" search-placeholder="Поиск статуса" /></label>
    </UiFilterRail>

    <NewEmployeeModal v-if="showNewEmployee && canManageEmployees" :departments="departments" :positions="activePositions" :reference-data="referenceData" @close="showNewEmployee = false" @created="employeeCreated" />
    <EmployeeCardDrawer v-if="selectedEmployeeId" :employee-id="selectedEmployeeId" :departments="departments" :positions="activePositions" :reference-data="referenceData" :access="access" @close="selectedEmployeeId = null" @updated="loadEmployees" />

    <div v-if="showDepartmentForm && canManageOrganization" class="overlay" @click.self="showDepartmentForm = false">
      <form class="drawer" @submit.prevent="saveDepartment">
        <div class="drawer-head"><div><div class="eyebrow">ORGANIZATION</div><h2>{{ editingDepartmentId ? 'Редактирование подразделения' : 'Новое подразделение' }}</h2></div><button type="button" class="close" @click="showDepartmentForm = false">×</button></div>
        <label class="irlix-field">Название<input v-model="departmentForm.name" required /></label><label class="irlix-field">Алиас<input v-model="departmentForm.alias" /></label><label class="irlix-field">Родительское подразделение<select v-model="departmentForm.parent_id"><option value="">Нет</option><option v-for="department in departments" :key="department.id" :value="department.id" :disabled="department.id === editingDepartmentId">{{ department.name }}</option></select></label><label class="irlix-field">Руководитель<select v-model="departmentForm.manager_id"><option value="">Не назначен</option><option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.full_name }}</option></select></label><label class="irlix-field">HR<select v-model="departmentForm.hr_id"><option value="">Не назначен</option><option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.full_name }}</option></select></label><label class="irlix-field">ID (Яндекс)<input v-model="departmentForm.yandex_id" type="number" step="1" /></label><label class="irlix-field">Группа LDAP / Keycloak<input v-model="departmentForm.ldap_group" placeholder="Например: os_backend" /></label><label class="checkbox-field"><input v-model="departmentForm.is_production" type="checkbox" /> Производственное подразделение</label><p class="form-hint">Группа используется как техническая привязка к Keycloak при provisioning сотрудника.</p><div class="form-actions"><UiButton v-if="editingDepartmentId && isPlatformAdmin" type="button" variant="danger" @click="deleteDepartment">Удалить полностью</UiButton><UiButton type="button" variant="secondary" @click="showDepartmentForm = false">Отмена</UiButton><UiButton type="submit">Сохранить</UiButton></div>
      </form>
    </div>
  </div>
</template>
