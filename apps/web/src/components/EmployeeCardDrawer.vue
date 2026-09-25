<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { UiBadge, UiButton } from '@irlix/ui';

const props = defineProps({ employeeId: { type: Number, required: true }, departments: { type: Array, default: () => [] }, referenceData: { type: Object, required: true }, access: { type: Object, default: () => ({ permissions: {} }) } });
const emit = defineEmits(['close', 'updated']);
const loading = ref(true); const error = ref(''); const detail = ref(null); const activeTab = ref('info');
const editField = ref(null); const editValue = ref(''); const showSalaryForm = ref(false); const lifecycleMode = ref(null);
const deleting = ref(false); const resendingOnboarding = ref(false); const onboardingMessage = ref('');
const drawerWidth = ref(690);
const salaryForm = ref({ effective_from: '', gross_salary: '', bonus: '', comment: '' });
const dismissForm = ref({ date: '' });
const rehireForm = ref({ started_at: '', cooperation_type: 'Штат', department_id: '', position: '' });
const cooperationForm = ref({ effective_from: '', cooperation_type: 'Штат' });
const employee = computed(() => detail.value?.employee ?? null);
const periods = computed(() => detail.value?.employment_periods ?? []);
const salaries = computed(() => detail.value?.salary_history ?? []);
const statuses = computed(() => detail.value?.status_history ?? []);
const assignments = computed(() => detail.value?.assignment_history ?? []);
const canManage = computed(() => Boolean(props.access.permissions?.['employees.manage']));
const canManageAccess = computed(() => Boolean(props.access.permissions?.['access.manage']));
const canReadSalary = computed(() => Boolean(props.access.permissions?.['employees.salary.read']));
const canManageSalary = computed(() => Boolean(props.access.permissions?.['employees.salary.manage']));

const fields = computed(() => [
  { title: 'Основная информация', rows: [
    { key: 'last_name', label: 'Фамилия' }, { key: 'first_name', label: 'Имя' }, { key: 'middle_name', label: 'Отчество' },
    { key: 'gender', label: 'Пол', type: 'select', options: props.referenceData.genders || [] },
    { key: 'login', label: 'Логин' }, { key: 'work_email', label: 'Рабочая почта', editable: false }, { key: 'identity_status', label: 'Identity', editable: false },
    { key: 'onboarding_email_status', label: 'Onboarding email', editable: false },
  ]},
  { title: 'Трудоустройство', rows: [
    { key: 'department_id', label: 'Подразделение', type: 'department' }, { key: 'position', label: 'Должность' },
    { key: 'specialization', label: 'Специализация' }, { key: 'work_format', label: 'Формат работы', type: 'select', options: props.referenceData.work_formats || [] },
  ]},
  { title: 'Личная информация', rows: [
    { key: 'birth_date', label: 'Дата рождения', type: 'date' }, { key: 'city', label: 'Город' }, { key: 'phone', label: 'Телефон' },
    { key: 'telegram', label: 'Telegram' }, { key: 'skype', label: 'Skype' }, { key: 'personal_email', label: 'Персональная почта', type: 'email' },
  ]},
]);

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
    detail.value = (await request(`/api/employees/employees/${props.employeeId}`)).data;
    if (!canReadSalary.value && activeTab.value === 'salary') activeTab.value = 'info';
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
};
const date = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : 'Бессрочно';
const money = (value) => value == null ? '—' : new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(Number(value));
const display = (field) => field.key === 'department_id' ? (employee.value?.department_name || '—') : field.type === 'date' ? (employee.value?.[field.key] ? date(employee.value[field.key]) : '—') : (employee.value?.[field.key] || '—');
const startEdit = (field) => { if (!canManage.value) return; editField.value = field.key; editValue.value = employee.value?.[field.key] ?? ''; };
const cancelEdit = () => { editField.value = null; editValue.value = ''; };
const saveField = async (field) => {
  try {
    let value = editValue.value === '' ? null : editValue.value;
    if (field.key === 'department_id' && value !== null) value = Number(value);
    await request(`/api/employees/employees/${props.employeeId}`, { method: 'PATCH', body: JSON.stringify({ [field.key]: value }) });
    cancelEdit(); await load(); emit('updated');
  } catch (e) { error.value = e.message; }
};
const runLifecycle = async (mode) => {
  try {
    if (mode === 'dismiss') await request(`/api/employees/employees/${props.employeeId}/dismiss`, { method: 'POST', body: JSON.stringify(dismissForm.value) });
    if (mode === 'rehire') await request(`/api/employees/employees/${props.employeeId}/rehire`, { method: 'POST', body: JSON.stringify({ ...rehireForm.value, department_id: Number(rehireForm.value.department_id) }) });
    if (mode === 'cooperation') await request(`/api/employees/employees/${props.employeeId}/change-cooperation`, { method: 'POST', body: JSON.stringify(cooperationForm.value) });
    lifecycleMode.value = null; await load(); emit('updated');
  } catch (e) { error.value = e.message; }
};
const resendOnboarding = async () => {
  if (!canManageAccess.value || resendingOnboarding.value || !employee.value?.keycloak_user_id) return;
  resendingOnboarding.value = true; error.value = ''; onboardingMessage.value = '';
  try {
    const payload = await request(`/api/employees/employees/${props.employeeId}/onboarding-email`, { method: 'POST' });
    onboardingMessage.value = `Письмо для установки пароля отправлено на ${payload?.data?.recipient || employee.value.personal_email}.`;
    await load(); emit('updated');
  } catch (e) { error.value = e.message; }
  finally { resendingOnboarding.value = false; }
};
const deleteEmployee = async () => {
  if (!canManageAccess.value || deleting.value || !employee.value) return;
  const confirmed = window.confirm(`Удалить ${employee.value.full_name} полностью? Будут удалены запись сотрудника, кадровая история, зарплаты, роли доступа и Keycloak identity. Это действие нельзя отменить.`);
  if (!confirmed) return;
  deleting.value = true; error.value = '';
  try {
    await request(`/api/employees/employees/${props.employeeId}`, { method: 'DELETE' });
    emit('updated'); emit('close');
  } catch (e) { error.value = e.message; }
  finally { deleting.value = false; }
};
const addSalary = async () => {
  try {
    await request(`/api/employees/employees/${props.employeeId}/salary-history`, { method: 'POST', body: JSON.stringify({ ...salaryForm.value, gross_salary: Number(salaryForm.value.gross_salary), bonus: salaryForm.value.bonus === '' ? null : Number(salaryForm.value.bonus), comment: salaryForm.value.comment || null }) });
    showSalaryForm.value = false; salaryForm.value = { effective_from: '', gross_salary: '', bonus: '', comment: '' }; await load();
  } catch (e) { error.value = e.message; }
};
const removeSalary = async (id) => { await request(`/api/employees/employees/${props.employeeId}/salary-history/${id}`, { method: 'DELETE' }); await load(); };

let resizeStartX = 0; let resizeStartWidth = 0;
const resizeMove = (event) => { const max = Math.max(520, window.innerWidth - 90); drawerWidth.value = Math.min(max, Math.max(520, resizeStartWidth + (resizeStartX - event.clientX))); };
const stopResize = () => { document.removeEventListener('pointermove', resizeMove); document.removeEventListener('pointerup', stopResize); document.body.classList.remove('employee-drawer-resizing'); };
const startResize = (event) => { resizeStartX = event.clientX; resizeStartWidth = drawerWidth.value; document.body.classList.add('employee-drawer-resizing'); document.addEventListener('pointermove', resizeMove); document.addEventListener('pointerup', stopResize); };

watch(() => props.employeeId, load);
onMounted(load);
onBeforeUnmount(stopResize);
</script>

<template>
  <div class="employee-card-backdrop" @click.self="emit('close')"><aside class="employee-card-drawer" :style="{ width: `${drawerWidth}px` }">
    <div class="employee-card-resize-handle" aria-label="Изменить ширину карточки" @pointerdown.prevent="startResize" />
    <header class="employee-card-top"><span>{{ employee?.last_name || '' }} {{ employee?.first_name || '' }}</span><button type="button" @click="emit('close')">×</button></header>
    <div v-if="loading" class="employee-card-loading">Загрузка…</div>
    <template v-else-if="employee">
      <section class="employee-card-hero"><div class="employee-avatar">♙</div><div><h2>{{ employee.full_name }}</h2><p>{{ employee.work_email || 'Рабочая почта не указана' }}</p></div><div><UiBadge tone="success">{{ employee.employment_status }}</UiBadge></div></section>
      <nav class="employee-card-tabs" :class="canReadSalary ? 'three' : 'two'"><button :class="{ active: activeTab === 'info' }" @click="activeTab='info'">♙ Инфо</button><button :class="{ active: activeTab === 'employment' }" @click="activeTab='employment'">▣ ТУ</button><button v-if="canReadSalary" :class="{ active: activeTab === 'salary' }" @click="activeTab='salary'">♙ Зарплаты</button></nav>
      <div v-if="error" class="employee-card-error">{{ error }}</div>
      <div v-if="onboardingMessage" class="employee-onboarding-note">{{ onboardingMessage }}</div>

      <section v-if="activeTab==='info'" class="employee-card-content">
        <div v-if="canManage" class="lifecycle-actions">
          <UiButton v-if="employee.employment_status==='Трудоустроен'" variant="secondary" compact @click="lifecycleMode='cooperation'">Изменить тип сотрудничества</UiButton>
          <UiButton v-if="employee.employment_status==='Трудоустроен'" variant="danger" compact @click="lifecycleMode='dismiss'">Уволить</UiButton>
          <UiButton v-if="employee.employment_status==='Уволен'" compact @click="lifecycleMode='rehire'">Вернуть в компанию</UiButton>
          <UiButton v-if="canManageAccess && employee.keycloak_user_id" variant="secondary" compact :disabled="resendingOnboarding" @click="resendOnboarding">{{ resendingOnboarding ? 'Отправляем…' : (employee.onboarding_email_status === 'sent' ? 'Отправить письмо повторно' : 'Отправить письмо') }}</UiButton>
          <UiButton v-if="canManageAccess" variant="danger" compact :disabled="deleting" @click="deleteEmployee">{{ deleting ? 'Удаление…' : 'Удалить пользователя' }}</UiButton>
        </div>
        <form v-if="canManage && lifecycleMode==='dismiss'" class="lifecycle-form" @submit.prevent="runLifecycle('dismiss')"><label>Дата увольнения<input v-model="dismissForm.date" type="date" required></label><UiButton compact type="submit">Подтвердить</UiButton></form>
        <form v-if="canManage && lifecycleMode==='rehire'" class="lifecycle-form" @submit.prevent="runLifecycle('rehire')"><input v-model="rehireForm.started_at" type="date" required><select v-model="rehireForm.cooperation_type"><option v-for="t in referenceData.cooperation_types" :key="t">{{t}}</option></select><select v-model="rehireForm.department_id" required><option value="">Подразделение</option><option v-for="d in departments" :key="d.id" :value="d.id">{{d.name}}</option></select><input v-model="rehireForm.position" placeholder="Должность"><UiButton compact type="submit">Вернуть</UiButton></form>
        <form v-if="canManage && lifecycleMode==='cooperation'" class="lifecycle-form" @submit.prevent="runLifecycle('cooperation')"><input v-model="cooperationForm.effective_from" type="date" required><select v-model="cooperationForm.cooperation_type"><option v-for="t in referenceData.cooperation_types" :key="t">{{t}}</option></select><UiButton compact type="submit">Сохранить</UiButton></form>

        <div v-for="section in fields" :key="section.title" class="employee-info-view"><h3>{{section.title}}</h3><dl>
          <div v-for="field in section.rows" :key="field.key" class="editable-attribute">
            <dt>{{field.label}}</dt>
            <dd v-if="editField!==field.key">{{ display(field) }}</dd>
            <dd v-else class="attribute-editor">
              <select v-if="field.type==='select'" v-model="editValue"><option value="">—</option><option v-for="o in field.options" :key="o">{{o}}</option></select>
              <select v-else-if="field.type==='department'" v-model="editValue"><option value="">—</option><option v-for="d in departments" :key="d.id" :value="d.id">{{d.name}}</option></select>
              <input v-else v-model="editValue" :type="field.type || 'text'">
              <button class="attribute-save" type="button" @click="saveField(field)">✓</button><button class="attribute-cancel" type="button" @click="cancelEdit">×</button>
            </dd>
            <button v-if="canManage && field.editable!==false && editField!==field.key" class="attribute-edit" type="button" aria-label="Редактировать" @click="startEdit(field)">✎</button>
            <span v-else />
          </div>
        </dl></div>
      </section>

      <section v-else-if="activeTab==='employment'" class="employee-card-content employee-history-content">
        <div class="employee-history-table"><table class="irlix-data-table"><thead><tr><th>Тип ТУ</th><th>Начало</th><th>Окончание</th></tr></thead><tbody><tr v-for="p in periods" :key="p.id"><td><UiBadge tone="info">{{p.cooperation_type}}</UiBadge></td><td>{{date(p.started_at)}}</td><td>{{date(p.ended_at)}}</td></tr></tbody></table></div>
        <h3 class="history-title">История статусов</h3><div class="employee-history-table"><table class="irlix-data-table"><thead><tr><th>Статус</th><th>С</th><th>По</th></tr></thead><tbody><tr v-for="s in statuses" :key="s.id"><td>{{s.status}}</td><td>{{date(s.effective_from)}}</td><td>{{date(s.effective_to)}}</td></tr></tbody></table></div>
        <h3 class="history-title">Переводы</h3><div class="employee-history-table"><table class="irlix-data-table"><thead><tr><th>Подразделение</th><th>Должность</th><th>С</th><th>По</th></tr></thead><tbody><tr v-for="a in assignments" :key="a.id"><td>{{a.department_name||'—'}}</td><td>{{a.position||'—'}}</td><td>{{date(a.effective_from)}}</td><td>{{date(a.effective_to)}}</td></tr></tbody></table></div>
      </section>

      <section v-else-if="canReadSalary" class="employee-card-content"><div v-if="canManageSalary" class="employee-card-actions"><UiButton compact @click="showSalaryForm=!showSalaryForm">+ Новая зарплата</UiButton></div>
        <form v-if="canManageSalary && showSalaryForm" class="inline-history-form salary" @submit.prevent="addSalary"><input v-model="salaryForm.effective_from" type="date" required><input v-model="salaryForm.gross_salary" type="number" min="0" placeholder="Оклад gross" required><input v-model="salaryForm.bonus" type="number" min="0" placeholder="Премия"><input v-model="salaryForm.comment" placeholder="Комментарий"><UiButton compact type="submit">Сохранить</UiButton></form>
        <div class="employee-history-table"><table class="irlix-data-table"><thead><tr><th>С</th><th>По</th><th>Оклад</th><th>Премия</th><th>Статус</th><th v-if="canManageSalary"></th></tr></thead><tbody><tr v-for="s in salaries" :key="s.id"><td>{{date(s.effective_from)}}</td><td>{{date(s.effective_to)}}</td><td>{{money(s.gross_salary)}}</td><td>{{money(s.bonus)}}</td><td><UiBadge :tone="s.effective_to ? 'neutral' : 'success'">{{s.status}}</UiBadge></td><td v-if="canManageSalary"><UiButton variant="danger" compact @click="removeSalary(s.id)">×</UiButton></td></tr></tbody></table></div>
      </section>
    </template>
  </aside></div>
</template>