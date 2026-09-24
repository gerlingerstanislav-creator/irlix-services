<script setup>
import { computed, onMounted, ref } from 'vue';

const currentSection = ref('employees');
const services = ref([
  { key: 'platform', name: 'Platform Core', endpoint: '/api/platform/health', status: 'checking' },
  { key: 'employees', name: 'Employees', endpoint: '/api/employees/health', status: 'checking' },
]);

const employees = ref([]);
const departments = ref([]);
const referenceData = ref({ employee_statuses: [], work_formats: [], cooperation_types: [] });
const loading = ref(false);
const error = ref('');
const search = ref('');
const departmentFilter = ref('');
const statusFilter = ref('');
const showEmployeeForm = ref(false);
const editingEmployeeId = ref(null);
const showDepartmentForm = ref(false);
const editingDepartmentId = ref(null);
const selectedDepartmentId = ref(null);

const employeeForm = ref(emptyEmployee());
const departmentForm = ref(emptyDepartment());

function emptyEmployee() {
  return {
    full_name: '',
    department_id: '',
    position: '',
    employment_status: '',
    work_format: '',
    cooperation_type: '',
    hired_at: '',
  };
}

function emptyDepartment() {
  return { name: '', alias: '', parent_id: '' };
}

const filteredEmployees = computed(() => {
  const needle = search.value.trim().toLowerCase();

  return employees.value.filter((employee) => {
    const matchesSearch = !needle
      || employee.full_name?.toLowerCase().includes(needle)
      || employee.position?.toLowerCase().includes(needle);
    const matchesDepartment = !departmentFilter.value
      || String(employee.department_id ?? '') === String(departmentFilter.value);
    const matchesStatus = !statusFilter.value || employee.employment_status === statusFilter.value;

    return matchesSearch && matchesDepartment && matchesStatus;
  });
});

const departmentTree = computed(() => {
  const byParent = new Map();
  departments.value.forEach((department) => {
    const key = department.parent_id == null ? 'root' : String(department.parent_id);
    if (!byParent.has(key)) byParent.set(key, []);
    byParent.get(key).push(department);
  });

  const build = (parentKey = 'root', level = 0) => (byParent.get(parentKey) ?? []).map((department) => ({
    ...department,
    level,
    children: build(String(department.id), level + 1),
  }));

  return build();
});

const flattenedDepartmentTree = computed(() => {
  const rows = [];
  const visit = (nodes) => nodes.forEach((node) => {
    rows.push(node);
    visit(node.children ?? []);
  });
  visit(departmentTree.value);
  return rows;
});

const selectedDepartment = computed(() => departments.value.find((item) => item.id === selectedDepartmentId.value) ?? null);
const selectedDepartmentEmployees = computed(() => {
  if (!selectedDepartmentId.value) return [];
  return employees.value.filter((employee) => employee.department_id === selectedDepartmentId.value);
});

const api = async (url, options = {}) => {
  const response = await fetch(url, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(options.headers ?? {}),
    },
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : payload.message;
    throw new Error(firstError || `HTTP ${response.status}`);
  }

  return payload;
};

const refreshServices = async () => {
  await Promise.all(services.value.map(async (service) => {
    service.status = 'checking';
    try {
      const response = await fetch(service.endpoint, { headers: { Accept: 'application/json' } });
      service.status = response.ok ? 'ok' : 'error';
    } catch {
      service.status = 'error';
    }
  }));
};

const loadEmployees = async () => {
  loading.value = true;
  error.value = '';

  try {
    const [employeesPayload, departmentsPayload, referencesPayload] = await Promise.all([
      api('/api/employees/employees'),
      api('/api/employees/departments'),
      api('/api/employees/reference-data'),
    ]);
    employees.value = employeesPayload.data ?? [];
    departments.value = departmentsPayload.data ?? [];
    referenceData.value = referencesPayload.data ?? referenceData.value;
    if (selectedDepartmentId.value && !departments.value.some((item) => item.id === selectedDepartmentId.value)) {
      selectedDepartmentId.value = null;
    }
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
};

const openCreateEmployee = () => {
  editingEmployeeId.value = null;
  employeeForm.value = emptyEmployee();
  showEmployeeForm.value = true;
};

const openEditEmployee = (employee) => {
  editingEmployeeId.value = employee.id;
  employeeForm.value = {
    full_name: employee.full_name ?? '',
    department_id: employee.department_id ?? '',
    position: employee.position ?? '',
    employment_status: employee.employment_status ?? '',
    work_format: employee.work_format ?? '',
    cooperation_type: employee.cooperation_type ?? '',
    hired_at: employee.hired_at ?? '',
  };
  showEmployeeForm.value = true;
};

const saveEmployee = async () => {
  error.value = '';
  const payload = {
    ...employeeForm.value,
    department_id: employeeForm.value.department_id || null,
    position: employeeForm.value.position || null,
    employment_status: employeeForm.value.employment_status || null,
    work_format: employeeForm.value.work_format || null,
    cooperation_type: employeeForm.value.cooperation_type || null,
    hired_at: employeeForm.value.hired_at || null,
  };

  try {
    if (editingEmployeeId.value) {
      await api(`/api/employees/employees/${editingEmployeeId.value}`, { method: 'PUT', body: JSON.stringify(payload) });
    } else {
      await api('/api/employees/employees', { method: 'POST', body: JSON.stringify(payload) });
    }
    showEmployeeForm.value = false;
    await loadEmployees();
  } catch (e) {
    error.value = e.message;
  }
};

const openCreateDepartment = () => {
  editingDepartmentId.value = null;
  departmentForm.value = emptyDepartment();
  showDepartmentForm.value = true;
};

const openEditDepartment = (department) => {
  editingDepartmentId.value = department.id;
  departmentForm.value = {
    name: department.name ?? '',
    alias: department.alias ?? '',
    parent_id: department.parent_id ?? '',
  };
  showDepartmentForm.value = true;
};

const saveDepartment = async () => {
  error.value = '';
  const payload = {
    name: departmentForm.value.name,
    alias: departmentForm.value.alias || null,
    parent_id: departmentForm.value.parent_id || null,
  };

  try {
    if (editingDepartmentId.value) {
      await api(`/api/employees/departments/${editingDepartmentId.value}`, { method: 'PUT', body: JSON.stringify(payload) });
    } else {
      await api('/api/employees/departments', { method: 'POST', body: JSON.stringify(payload) });
    }
    departmentForm.value = emptyDepartment();
    showDepartmentForm.value = false;
    await loadEmployees();
  } catch (e) {
    error.value = e.message;
  }
};

onMounted(async () => {
  await Promise.all([refreshServices(), loadEmployees()]);
});
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-mark">I</div>
        <div><strong>IRLIX</strong><span>Internal services</span></div>
      </div>

      <nav class="nav">
        <button :class="{ active: currentSection === 'overview' }" @click="currentSection = 'overview'"><span>◫</span> Обзор</button>
        <button :class="{ active: currentSection === 'employees' }" @click="currentSection = 'employees'"><span>◉</span> Сотрудники</button>
        <button :class="{ active: currentSection === 'departments' }" @click="currentSection = 'departments'"><span>⌘</span> Подразделения</button>
      </nav>

      <div class="sidebar-status"><span class="status-light" />Стенд подключён</div>
    </aside>

    <main class="workspace">
      <template v-if="currentSection === 'overview'">
        <header class="page-header">
          <div><div class="eyebrow">IRLIX SERVICES</div><h1>Платформа внутренних сервисов</h1><p>Первый рабочий контур: общая оболочка, Platform Core и Employees.</p></div>
          <button class="secondary" @click="refreshServices">Проверить сервисы</button>
        </header>
        <section class="service-grid">
          <article v-for="service in services" :key="service.key" class="service-card">
            <div class="service-state"><span class="dot" :class="service.status" />{{ service.status === 'ok' ? 'Доступен' : service.status === 'checking' ? 'Проверка…' : 'Недоступен' }}</div>
            <h2>{{ service.name }}</h2>
            <p>{{ service.key === 'platform' ? 'Общие платформенные механизмы.' : 'Сотрудники и организационная структура.' }}</p>
          </article>
        </section>
      </template>

      <template v-else-if="currentSection === 'employees'">
        <header class="page-header compact">
          <div><div class="eyebrow">EMPLOYEES</div><h1>Сотрудники</h1><p>Рабочий реестр сотрудников компании.</p></div>
          <button @click="openCreateEmployee">+ Сотрудник</button>
        </header>

        <div v-if="error" class="alert">{{ error }}</div>

        <section class="stats">
          <div><strong>{{ employees.length }}</strong><span>Сотрудников</span></div>
          <div><strong>{{ departments.length }}</strong><span>Подразделений</span></div>
          <div><strong>{{ services.find((item) => item.key === 'employees')?.status === 'ok' ? 'Online' : '—' }}</strong><span>Employees API</span></div>
        </section>

        <section class="panel">
          <div class="toolbar toolbar-four">
            <input v-model="search" type="search" placeholder="Поиск по имени или должности" />
            <select v-model="departmentFilter"><option value="">Все подразделения</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select>
            <select v-model="statusFilter"><option value="">Все статусы</option><option v-for="status in referenceData.employee_statuses" :key="status" :value="status">{{ status }}</option></select>
            <button class="ghost" @click="loadEmployees">Обновить</button>
          </div>

          <div v-if="loading" class="empty-state">Загрузка…</div>
          <div v-else-if="!filteredEmployees.length" class="empty-state"><strong>Сотрудников пока нет</strong><span>Добавь первого сотрудника — запись сохранится в PostgreSQL Employees.</span><button @click="openCreateEmployee">Добавить сотрудника</button></div>
          <div v-else class="table-wrap">
            <table>
              <thead><tr><th>Сотрудник</th><th>Подразделение</th><th>Должность</th><th>Статус</th><th>Формат</th><th>Тип сотрудничества</th><th>Дата найма</th><th /></tr></thead>
              <tbody>
                <tr v-for="employee in filteredEmployees" :key="employee.id">
                  <td><strong>{{ employee.full_name }}</strong></td><td>{{ employee.department_name || '—' }}</td><td>{{ employee.position || '—' }}</td>
                  <td><span class="pill">{{ employee.employment_status || '—' }}</span></td><td>{{ employee.work_format || '—' }}</td><td>{{ employee.cooperation_type || '—' }}</td><td>{{ employee.hired_at || '—' }}</td>
                  <td><button class="link-button" @click="openEditEmployee(employee)">Изменить</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>

      <template v-else>
        <header class="page-header compact">
          <div><div class="eyebrow">ORGANIZATION</div><h1>Подразделения</h1><p>Иерархическая организационная структура Employees.</p></div>
          <button @click="openCreateDepartment">+ Подразделение</button>
        </header>

        <div v-if="error" class="alert">{{ error }}</div>

        <section class="org-layout">
          <div class="panel org-tree-panel">
            <div class="panel-title"><div><strong>Оргструктура</strong><span>{{ departments.length }} подразделений</span></div><button class="ghost" @click="loadEmployees">Обновить</button></div>
            <div v-if="!flattenedDepartmentTree.length" class="empty-state compact-empty"><strong>Подразделений пока нет</strong><button @click="openCreateDepartment">Создать первое</button></div>
            <div v-else class="department-tree">
              <button v-for="department in flattenedDepartmentTree" :key="department.id" class="department-row" :class="{ selected: selectedDepartmentId === department.id }" :style="{ paddingLeft: `${16 + department.level * 24}px` }" @click="selectedDepartmentId = department.id">
                <span class="tree-marker">{{ department.level ? '↳' : '●' }}</span>
                <span class="department-name"><strong>{{ department.name }}</strong><small>{{ department.alias || 'Без алиаса' }}</small></span>
                <span class="employee-count">{{ department.employee_count }} чел.</span>
              </button>
            </div>
          </div>

          <div class="panel department-card">
            <template v-if="selectedDepartment">
              <div class="panel-title"><div><strong>{{ selectedDepartment.name }}</strong><span>{{ selectedDepartment.parent_name ? `Входит в ${selectedDepartment.parent_name}` : 'Корневое подразделение' }}</span></div><button class="secondary" @click="openEditDepartment(selectedDepartment)">Редактировать</button></div>
              <dl class="department-meta"><div><dt>Алиас</dt><dd>{{ selectedDepartment.alias || '—' }}</dd></div><div><dt>Сотрудников</dt><dd>{{ selectedDepartment.employee_count }}</dd></div></dl>
              <div class="department-people"><h3>Сотрудники подразделения</h3><div v-if="!selectedDepartmentEmployees.length" class="muted">В подразделении пока нет сотрудников.</div><button v-for="employee in selectedDepartmentEmployees" :key="employee.id" class="person-row" @click="openEditEmployee(employee)"><span><strong>{{ employee.full_name }}</strong><small>{{ employee.position || 'Должность не указана' }}</small></span><span>{{ employee.employment_status || '—' }}</span></button></div>
            </template>
            <div v-else class="empty-state"><strong>Выбери подразделение</strong><span>Справа появятся его параметры и сотрудники.</span></div>
          </div>
        </section>
      </template>
    </main>

    <div v-if="showEmployeeForm" class="overlay" @click.self="showEmployeeForm = false">
      <form class="drawer" @submit.prevent="saveEmployee">
        <div class="drawer-head"><div><div class="eyebrow">EMPLOYEES</div><h2>{{ editingEmployeeId ? 'Редактирование' : 'Новый сотрудник' }}</h2></div><button type="button" class="close" @click="showEmployeeForm = false">×</button></div>
        <label>ФИО<input v-model="employeeForm.full_name" required /></label>
        <label>Подразделение<select v-model="employeeForm.department_id"><option value="">Не выбрано</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></label>
        <label>Должность<input v-model="employeeForm.position" /></label>
        <label>Статус<select v-model="employeeForm.employment_status"><option value="">Не выбрано</option><option v-for="status in referenceData.employee_statuses" :key="status" :value="status">{{ status }}</option></select></label>
        <label>Формат работы<select v-model="employeeForm.work_format"><option value="">Не выбрано</option><option v-for="format in referenceData.work_formats" :key="format" :value="format">{{ format }}</option></select></label>
        <label>Тип сотрудничества<select v-model="employeeForm.cooperation_type"><option value="">Не выбрано</option><option v-for="type in referenceData.cooperation_types" :key="type" :value="type">{{ type }}</option></select></label>
        <label>Дата найма<input v-model="employeeForm.hired_at" type="date" /></label>
        <div class="form-actions"><button type="button" class="secondary" @click="showEmployeeForm = false">Отмена</button><button type="submit">Сохранить</button></div>
      </form>
    </div>

    <div v-if="showDepartmentForm" class="overlay" @click.self="showDepartmentForm = false">
      <form class="modal" @submit.prevent="saveDepartment">
        <div class="drawer-head"><div><div class="eyebrow">STRUCTURE</div><h2>{{ editingDepartmentId ? 'Редактирование подразделения' : 'Новое подразделение' }}</h2></div><button type="button" class="close" @click="showDepartmentForm = false">×</button></div>
        <label>Название<input v-model="departmentForm.name" required /></label>
        <label>Алиас<input v-model="departmentForm.alias" placeholder="Например: backend" /></label>
        <label>Родительское подразделение<select v-model="departmentForm.parent_id"><option value="">Корневое подразделение</option><option v-for="department in departments.filter((item) => item.id !== editingDepartmentId)" :key="department.id" :value="department.id">{{ department.name }}</option></select></label>
        <div class="form-actions"><button type="button" class="secondary" @click="showDepartmentForm = false">Отмена</button><button type="submit">{{ editingDepartmentId ? 'Сохранить' : 'Создать' }}</button></div>
      </form>
    </div>
  </div>
</template>
