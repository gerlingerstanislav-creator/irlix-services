<script setup>
import { computed, onMounted, ref } from 'vue';
import { UiBadge, UiButton, UiPageHeader, UiPanel } from '@irlix/ui';
import { auth } from './auth';
import AppSidebar from './components/AppSidebar.vue';
import EmployeeCardDrawer from './components/EmployeeCardDrawer.vue';
import NewEmployeeModal from './components/NewEmployeeModal.vue';

const currentSection = ref('employees');
const employees = ref([]);
const departments = ref([]);
const referenceData = ref({ employee_statuses: [], work_formats: [], cooperation_types: [], genders: [] });
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

const api = async (url, options = {}) => {
  const response = await auth.fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers ?? {}) } });
  const payload = await response.json().catch(() => ({}));
  if (response.status === 401) {
    await auth.login();
    throw new Error('Требуется повторная авторизация');
  }
  if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
  return payload;
};

const loadEmployees = async () => {
  loading.value = true; error.value = '';
  try {
    const [employeesPayload, departmentsPayload, referencesPayload] = await Promise.all([
      api('/api/employees/employees'), api('/api/employees/departments'), api('/api/employees/reference-data'),
    ]);
    employees.value = employeesPayload.data ?? [];
    departments.value = departmentsPayload.data ?? [];
    referenceData.value = referencesPayload.data ?? referenceData.value;
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
};

const employeeCreated = async (employee) => { showNewEmployee.value = false; await loadEmployees(); selectedEmployeeId.value = employee.id; };
const openCreateDepartment = () => { editingDepartmentId.value = null; departmentForm.value = emptyDepartment(); showDepartmentForm.value = true; };
const openEditDepartment = (department) => {
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

onMounted(loadEmployees);
</script>

<template>
  <div class="app-shell irlix-ui">
    <AppSidebar v-model:section="currentSection" />
    <main class="workspace">
      <template v-if="currentSection === 'employees'">
        <UiPageHeader eyebrow="EMPLOYEES" title="Сотрудники" description="Реестр, кадровое оформление, история сотрудничества и зарплат."><template #actions><UiButton @click="showNewEmployee = true">+ Сотрудник</UiButton></template></UiPageHeader>
        <div v-if="error" class="alert">{{ error }}</div>
        <section class="stats"><div><strong>{{ employees.length }}</strong><span>Сотрудников</span></div><div><strong>{{ departments.length }}</strong><span>Подразделений</span></div><div><strong>OIDC</strong><span>Keycloak</span></div></section>
        <UiPanel>
          <div class="toolbar toolbar-four"><input v-model="search" type="search" placeholder="Поиск по имени, логину или должности" /><select v-model="departmentFilter"><option value="">Все подразделения</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select><select v-model="statusFilter"><option value="">Все статусы</option><option v-for="status in referenceData.employee_statuses" :key="status" :value="status">{{ status }}</option></select><UiButton variant="secondary" @click="loadEmployees">Обновить</UiButton></div>
          <div v-if="loading" class="empty-state">Загрузка…</div>
          <div v-else-if="!filteredEmployees.length" class="empty-state"><strong>Сотрудников пока нет</strong><UiButton @click="showNewEmployee = true">Добавить сотрудника</UiButton></div>
          <div v-else class="table-wrap"><table class="irlix-data-table employee-table"><thead><tr><th>Сотрудник</th><th>Подразделение</th><th>Должность</th><th>Оклад (gross)</th><th>Статус</th><th>Тип</th><th>Формат</th></tr></thead><tbody><tr v-for="employee in filteredEmployees" :key="employee.id" class="clickable-row" @click="selectedEmployeeId = employee.id"><td><strong>{{ employee.full_name }}</strong><small class="employee-login">{{ employee.work_email || employee.login || '—' }}</small></td><td>{{ employee.department_name || '—' }}</td><td>{{ employee.position || '—' }}</td><td>—</td><td><UiBadge tone="success">{{ employee.employment_status || '—' }}</UiBadge></td><td>{{ employee.cooperation_type || '—' }}</td><td>{{ employee.work_format || '—' }}</td></tr></tbody></table></div>
        </UiPanel>
      </template>

      <template v-else-if="currentSection === 'departments'">
        <UiPageHeader eyebrow="ORGANIZATION" title="Подразделения" description="Организационная структура компании и технические привязки."><template #actions><UiButton @click="openCreateDepartment">+ Подразделение</UiButton></template></UiPageHeader>
        <div v-if="error" class="alert">{{ error }}</div>
        <UiPanel><div v-if="loading" class="empty-state">Загрузка…</div><div v-else class="table-wrap organization-table-wrap"><table class="irlix-data-table organization-table"><thead><tr><th>◇ Название / Алиас</th><th>♙ Руководитель</th><th>♙ HR</th><th>♧ Сотрудники</th><th>◇ ID (Яндекс)</th><th>◇ Группа (LDAP)</th><th /></tr></thead><tbody><tr v-for="department in flattenedDepartmentTree" :key="department.id"><td><div class="org-name" :style="{ paddingLeft: `${department.level * 18}px` }"><button v-if="department.hasChildren" class="tree-chevron" type="button" :aria-label="department.collapsed ? 'Развернуть' : 'Свернуть'" @click.stop="toggleDepartment(department.id)">{{ department.collapsed ? '›' : '⌄' }}</button><span v-else class="tree-chevron-placeholder" /><span>{{ department.name }}<small v-if="department.alias"> / {{ department.alias }}</small></span><UiBadge v-if="department.is_production" tone="info">Производственное</UiBadge></div></td><td>{{ department.manager_name || '—' }}</td><td>{{ department.hr_name || '—' }}</td><td>{{ department.employee_count }}</td><td>{{ department.yandex_id ?? '—' }}</td><td>{{ department.ldap_group || '—' }}</td><td><UiButton variant="secondary" compact @click="openEditDepartment(department)">✎</UiButton></td></tr></tbody></table></div></UiPanel>
      </template>
    </main>

    <NewEmployeeModal v-if="showNewEmployee" :departments="departments" :reference-data="referenceData" @close="showNewEmployee = false" @created="employeeCreated" />
    <EmployeeCardDrawer v-if="selectedEmployeeId" :employee-id="selectedEmployeeId" :departments="departments" :reference-data="referenceData" @close="selectedEmployeeId = null" @updated="loadEmployees" />

    <div v-if="showDepartmentForm" class="overlay" @click.self="showDepartmentForm = false">
      <form class="drawer" @submit.prevent="saveDepartment">
        <div class="drawer-head"><div><div class="eyebrow">ORGANIZATION</div><h2>{{ editingDepartmentId ? 'Редактирование подразделения' : 'Новое подразделение' }}</h2></div><button type="button" class="close" @click="showDepartmentForm = false">×</button></div>
        <label class="irlix-field">Название<input v-model="departmentForm.name" required /></label><label class="irlix-field">Алиас<input v-model="departmentForm.alias" /></label><label class="irlix-field">Родительское подразделение<select v-model="departmentForm.parent_id"><option value="">Нет</option><option v-for="department in departments" :key="department.id" :value="department.id" :disabled="department.id === editingDepartmentId">{{ department.name }}</option></select></label><label class="irlix-field">Руководитель<select v-model="departmentForm.manager_id"><option value="">Не назначен</option><option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.full_name }}</option></select></label><label class="irlix-field">HR<select v-model="departmentForm.hr_id"><option value="">Не назначен</option><option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.full_name }}</option></select></label><label class="irlix-field">ID (Яндекс)<input v-model="departmentForm.yandex_id" type="number" step="1" /></label><label class="irlix-field">Группа LDAP / Keycloak<input v-model="departmentForm.ldap_group" placeholder="Например: os_backend" /></label><label class="checkbox-field"><input v-model="departmentForm.is_production" type="checkbox" /> Производственное подразделение</label><p class="form-hint">Группа используется как техническая привязка к Keycloak при provisioning сотрудника.</p><div class="form-actions"><UiButton type="button" variant="secondary" @click="showDepartmentForm = false">Отмена</UiButton><UiButton type="submit">Сохранить</UiButton></div>
      </form>
    </div>
  </div>
</template>
