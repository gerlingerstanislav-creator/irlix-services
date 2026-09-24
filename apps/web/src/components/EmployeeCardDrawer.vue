<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiBadge, UiButton } from '@irlix/ui';

const props = defineProps({ employeeId: { type: Number, required: true }, departments: { type: Array, default: () => [] }, referenceData: { type: Object, required: true } });
const emit = defineEmits(['close', 'updated']);
const loading = ref(true);
const error = ref('');
const detail = ref(null);
const activeTab = ref('info');
const editingInfo = ref(false);
const infoForm = ref({});
const employmentForm = ref({ cooperation_type: 'Штат', started_at: '', ended_at: '', department_id: '', position: '' });
const salaryForm = ref({ effective_from: '', gross_salary: '', bonus: '', status: 'Действует', comment: '' });
const showEmploymentForm = ref(false);
const showSalaryForm = ref(false);

const employee = computed(() => detail.value?.employee ?? null);
const periods = computed(() => detail.value?.employment_periods ?? []);
const salaries = computed(() => detail.value?.salary_history ?? []);

const request = async (url, options = {}) => {
  const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers ?? {}) } });
  if (response.status === 204) return null;
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
  return payload;
};

const load = async () => {
  loading.value = true; error.value = '';
  try {
    const payload = await request(`/api/employees/employees/${props.employeeId}`);
    detail.value = payload.data;
    infoForm.value = { ...payload.data.employee, department_id: payload.data.employee.department_id ?? '', birth_date: payload.data.employee.birth_date ?? '', fired_at: payload.data.employee.fired_at ?? '' };
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
};

const saveInfo = async () => {
  error.value = '';
  try {
    await request(`/api/employees/employees/${props.employeeId}`, { method: 'PUT', body: JSON.stringify({
      ...infoForm.value,
      department_id: infoForm.value.department_id || null,
      birth_date: infoForm.value.birth_date || null,
      fired_at: infoForm.value.fired_at || null,
      work_format: infoForm.value.is_remote ? 'Удалённо' : (infoForm.value.work_format || 'Офис'),
    }) });
    editingInfo.value = false;
    await load(); emit('updated');
  } catch (e) { error.value = e.message; }
};

const addEmployment = async () => {
  error.value = '';
  try {
    await request(`/api/employees/employees/${props.employeeId}/employment-periods`, { method: 'POST', body: JSON.stringify({ ...employmentForm.value, department_id: employmentForm.value.department_id || null, ended_at: employmentForm.value.ended_at || null, position: employmentForm.value.position || null }) });
    showEmploymentForm.value = false;
    employmentForm.value = { cooperation_type: 'Штат', started_at: '', ended_at: '', department_id: '', position: '' };
    await load();
  } catch (e) { error.value = e.message; }
};

const removeEmployment = async (id) => { await request(`/api/employees/employees/${props.employeeId}/employment-periods/${id}`, { method: 'DELETE' }); await load(); };

const addSalary = async () => {
  error.value = '';
  try {
    await request(`/api/employees/employees/${props.employeeId}/salary-history`, { method: 'POST', body: JSON.stringify({ ...salaryForm.value, gross_salary: Number(salaryForm.value.gross_salary), bonus: salaryForm.value.bonus === '' ? null : Number(salaryForm.value.bonus), comment: salaryForm.value.comment || null }) });
    showSalaryForm.value = false;
    salaryForm.value = { effective_from: '', gross_salary: '', bonus: '', status: 'Действует', comment: '' };
    await load();
  } catch (e) { error.value = e.message; }
};

const removeSalary = async (id) => { await request(`/api/employees/employees/${props.employeeId}/salary-history/${id}`, { method: 'DELETE' }); await load(); };
const money = (value) => value == null ? '—' : new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(Number(value));
const date = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : 'Бессрочно';
watch(() => props.employeeId, load);
onMounted(load);
</script>

<template>
  <div class="employee-card-backdrop" @click.self="emit('close')">
    <aside class="employee-card-drawer">
      <header class="employee-card-top"><span>{{ employee?.last_name || '' }} {{ employee?.first_name || '' }}</span><button type="button" @click="emit('close')">×</button></header>
      <div v-if="loading" class="employee-card-loading">Загрузка…</div>
      <template v-else-if="employee">
        <section class="employee-card-hero">
          <div class="employee-avatar">♙</div>
          <div><h2>{{ employee.full_name }}</h2><p>{{ employee.work_email || 'Рабочая почта не указана' }}</p></div>
          <UiBadge tone="success">{{ employee.employment_status || '—' }}</UiBadge>
        </section>
        <nav class="employee-card-tabs">
          <button :class="{ active: activeTab === 'info' }" @click="activeTab = 'info'">♙ Инфо</button>
          <button :class="{ active: activeTab === 'employment' }" @click="activeTab = 'employment'">▣ ТУ</button>
          <button :class="{ active: activeTab === 'salary' }" @click="activeTab = 'salary'">♙ Зарплаты</button>
          <button :class="{ active: activeTab === 'roles' }" @click="activeTab = 'roles'">♙ Роли</button>
          <button :class="{ active: activeTab === 'notes' }" @click="activeTab = 'notes'">▱ Заметки</button>
        </nav>
        <div v-if="error" class="employee-card-error">{{ error }}</div>

        <section v-if="activeTab === 'info'" class="employee-card-content">
          <div class="employee-card-actions"><UiButton v-if="!editingInfo" variant="secondary" compact @click="editingInfo = true">Редактировать</UiButton><template v-else><UiButton variant="secondary" compact @click="editingInfo = false">Отмена</UiButton><UiButton compact @click="saveInfo">Сохранить</UiButton></template></div>
          <div v-if="!editingInfo" class="employee-info-view">
            <h3>Основная информация</h3>
            <dl><div><dt>Фамилия</dt><dd>{{ employee.last_name || '—' }}</dd></div><div><dt>Имя</dt><dd>{{ employee.first_name || '—' }}</dd></div><div><dt>Отчество</dt><dd>{{ employee.middle_name || '—' }}</dd></div><div><dt>Пол</dt><dd>{{ employee.gender || '—' }}</dd></div></dl>
            <h3>Трудоустройство</h3>
            <dl><div><dt>Подразделение</dt><dd>{{ employee.department_name || '—' }}</dd></div><div><dt>Должность</dt><dd>{{ employee.position || '—' }}</dd></div><div><dt>Специализация</dt><dd>{{ employee.specialization || '—' }}</dd></div><div><dt>Удалённый сотрудник</dt><dd>{{ employee.is_remote ? 'Да' : 'Нет' }}</dd></div></dl>
            <h3>Личная информация</h3>
            <dl><div><dt>Дата рождения</dt><dd>{{ employee.birth_date ? date(employee.birth_date) : '—' }}</dd></div><div><dt>Город</dt><dd>{{ employee.city || '—' }}</dd></div><div><dt>Телефон</dt><dd>{{ employee.phone || '—' }}</dd></div><div><dt>Telegram</dt><dd>{{ employee.telegram || '—' }}</dd></div><div><dt>Skype</dt><dd>{{ employee.skype || '—' }}</dd></div><div><dt>Персональная почта</dt><dd>{{ employee.personal_email || '—' }}</dd></div></dl>
          </div>
          <form v-else class="employee-info-form" @submit.prevent="saveInfo">
            <label class="irlix-field">Имя<input v-model="infoForm.first_name" /></label><label class="irlix-field">Фамилия<input v-model="infoForm.last_name" /></label><label class="irlix-field">Отчество<input v-model="infoForm.middle_name" /></label>
            <label class="irlix-field">Пол<select v-model="infoForm.gender"><option value="">—</option><option v-for="g in referenceData.genders || []" :key="g">{{ g }}</option></select></label>
            <label class="irlix-field">Подразделение<select v-model="infoForm.department_id"><option value="">—</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></label>
            <label class="irlix-field">Должность<input v-model="infoForm.position" /></label><label class="irlix-field">Специализация<input v-model="infoForm.specialization" /></label>
            <label class="irlix-field">Статус<select v-model="infoForm.employment_status"><option v-for="s in referenceData.employee_statuses || []" :key="s">{{ s }}</option></select></label>
            <label class="irlix-field">Формат<select v-model="infoForm.work_format"><option v-for="f in referenceData.work_formats || []" :key="f">{{ f }}</option></select></label>
            <label class="irlix-field">Дата рождения<input v-model="infoForm.birth_date" type="date" /></label><label class="irlix-field">Город<input v-model="infoForm.city" /></label><label class="irlix-field">Телефон<input v-model="infoForm.phone" /></label><label class="irlix-field">Telegram<input v-model="infoForm.telegram" /></label><label class="irlix-field">Skype<input v-model="infoForm.skype" /></label><label class="irlix-field">Персональная почта<input v-model="infoForm.personal_email" type="email" /></label><label class="irlix-field">Дата увольнения<input v-model="infoForm.fired_at" type="date" /></label><label class="checkbox-field"><input v-model="infoForm.is_remote" type="checkbox" /> Удалённый сотрудник</label>
          </form>
        </section>

        <section v-else-if="activeTab === 'employment'" class="employee-card-content">
          <div class="employee-card-actions"><UiButton compact @click="showEmploymentForm = !showEmploymentForm">+ Добавить ТУ</UiButton></div>
          <form v-if="showEmploymentForm" class="inline-history-form" @submit.prevent="addEmployment"><select v-model="employmentForm.cooperation_type" required><option v-for="type in referenceData.cooperation_types || []" :key="type">{{ type }}</option></select><input v-model="employmentForm.started_at" type="date" required /><input v-model="employmentForm.ended_at" type="date" /><select v-model="employmentForm.department_id"><option value="">Подразделение</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select><input v-model="employmentForm.position" placeholder="Должность" /><UiButton compact type="submit">Сохранить</UiButton></form>
          <table class="irlix-data-table"><thead><tr><th>Тип ТУ</th><th>Дата начала</th><th>Дата окончания</th><th /></tr></thead><tbody><tr v-for="period in periods" :key="period.id"><td><UiBadge tone="info">{{ period.cooperation_type }}</UiBadge></td><td>{{ date(period.started_at) }}</td><td>{{ date(period.ended_at) }}</td><td><UiButton variant="danger" compact @click="removeEmployment(period.id)">×</UiButton></td></tr></tbody></table>
        </section>

        <section v-else-if="activeTab === 'salary'" class="employee-card-content">
          <div class="employee-card-actions"><UiButton compact @click="showSalaryForm = !showSalaryForm">+ Новая зарплата</UiButton></div>
          <form v-if="showSalaryForm" class="inline-history-form salary" @submit.prevent="addSalary"><input v-model="salaryForm.effective_from" type="date" required /><input v-model="salaryForm.gross_salary" type="number" min="0" placeholder="Оклад gross" required /><input v-model="salaryForm.bonus" type="number" min="0" placeholder="Премия" /><input v-model="salaryForm.comment" placeholder="Комментарий" /><UiButton compact type="submit">Сохранить</UiButton></form>
          <table class="irlix-data-table"><thead><tr><th>Дата</th><th>Оклад</th><th>Премия</th><th>Статус</th><th /></tr></thead><tbody><tr v-for="salary in salaries" :key="salary.id"><td>{{ date(salary.effective_from) }}</td><td>{{ money(salary.gross_salary) }}</td><td>{{ money(salary.bonus) }}</td><td><UiBadge tone="neutral">{{ salary.status }}</UiBadge></td><td><UiButton variant="danger" compact @click="removeSalary(salary.id)">×</UiButton></td></tr></tbody></table>
        </section>

        <section v-else class="employee-card-content"><div class="employee-card-placeholder"><strong>{{ activeTab === 'roles' ? 'Роли' : 'Заметки' }}</strong><p>Раздел зарезервирован в карточке сотрудника. Бизнес-правила будут добавлены отдельной итерацией.</p></div></section>
      </template>
    </aside>
  </div>
</template>
