<script setup>
import { computed, ref } from 'vue';
import { UiButton } from '@irlix/ui';

const props = defineProps({ departments: { type: Array, default: () => [] }, referenceData: { type: Object, required: true } });
const emit = defineEmits(['close', 'created']);
const error = ref('');
const saving = ref(false);
const form = ref({ gender: 'Мужчина', first_name: '', last_name: '', middle_name: '', login: '', hired_at: new Date().toISOString().slice(0, 10), cooperation_type: 'Штат', personal_email: '', department_id: '', position: '' });
const workEmail = computed(() => form.value.login ? `${form.value.login}@irlix.ru` : 'Не заданы имя/фамилия');

const submit = async () => {
  error.value = ''; saving.value = true;
  try {
    const response = await fetch('/api/employees/employees', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(form.value) });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
    emit('created', payload.data);
  } catch (e) { error.value = e.message; }
  finally { saving.value = false; }
};
</script>

<template>
  <div class="employee-modal-backdrop" @click.self="emit('close')">
    <form class="employee-modal" @submit.prevent="submit">
      <header><h2>Новый сотрудник</h2><button type="button" class="employee-close" @click="emit('close')">×</button></header>
      <div class="employee-onboarding-note">Сотрудник будет добавлен со статусом «Трудоустроен». После добавления будет подготовлена отправка onboarding-письма на персональную почту.</div>
      <div v-if="error" class="employee-form-error">{{ error }}</div>
      <div class="employee-form-grid three">
        <label class="irlix-field"><span>Пол*</span><select v-model="form.gender" required><option v-for="gender in referenceData.genders || []" :key="gender">{{ gender }}</option></select></label>
        <label class="irlix-field"><span>Имя*</span><input v-model="form.first_name" maxlength="32" required placeholder="Иван" /></label>
        <label class="irlix-field"><span>Фамилия*</span><input v-model="form.last_name" maxlength="32" required placeholder="Иванов" /></label>
      </div>
      <label class="irlix-field"><span>Логин*</span><div class="login-field"><input v-model="form.login" required placeholder="ivan.ivanov" /><strong>@irlix.ru</strong></div><small>Рабочая почта: {{ workEmail }}</small></label>
      <div class="employee-form-grid two">
        <label class="irlix-field"><span>Дата приёма*</span><input v-model="form.hired_at" type="date" required /></label>
        <label class="irlix-field"><span>Тип сотрудничества*</span><select v-model="form.cooperation_type" required><option v-for="type in referenceData.cooperation_types || []" :key="type">{{ type }}</option></select></label>
      </div>
      <label class="irlix-field"><span>Персональная почта*</span><input v-model="form.personal_email" type="email" required placeholder="bestworker@gmail.com" /><small>Используется для отправки инструкций по VPN, подключению к сервисам и других onboarding-инструкций.</small></label>
      <label class="irlix-field"><span>Подразделение*</span><select v-model="form.department_id" required><option value="" disabled>Выберите подразделение</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></label>
      <label class="irlix-field"><span>Должность</span><input v-model="form.position" placeholder="Должность сотрудника" /></label>
      <footer><UiButton type="button" variant="secondary" @click="emit('close')">Отмена</UiButton><UiButton type="submit" :disabled="saving">{{ saving ? 'Добавление…' : '✓ Добавить' }}</UiButton></footer>
    </form>
  </div>
</template>
