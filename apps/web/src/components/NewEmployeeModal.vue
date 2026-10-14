<script setup>
import { computed, ref, watch } from 'vue';
import { UiButton, UiSearchSelect } from '@irlix/ui';
import { positionsForDepartment, departmentOptions } from '../staffTree';

const props = defineProps({
  departments: { type: Array, default: () => [] },
  positions: { type: Array, default: () => [] },
  referenceData: { type: Object, required: true },
  canHire: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'created']);
const saving = ref(false);
const error = ref('');
const form = ref({
  gender: 'Мужчина', first_name: '', last_name: '', middle_name: '',
  login: '', personal_email: '', department_id: '', position_id: '',
  cooperation_type: 'Штат', hired_at: new Date().toLocaleDateString('en-CA'),
  gross_salary: '', hire_immediately: false,
});
const departmentChoices = computed(() => departmentOptions(props.departments));
const availablePositions = computed(() => positionsForDepartment(props.positions, form.value.department_id));
watch(() => form.value.department_id, () => { form.value.position_id = ''; });
const transliterate = (value = '') => {
  const map = { а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'e',ж:'zh',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'h',ц:'ts',ч:'ch',ш:'sh',щ:'sch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya' };
  return value.toLowerCase().trim().split('').map(char => map[char] ?? char).join('').replace(/[^a-z0-9]+/g, '.').replace(/^\.+|\.+$/g, '');
};
watch([() => form.value.first_name, () => form.value.last_name], () => {
  const first = transliterate(form.value.first_name);
  const last = transliterate(form.value.last_name);
  if (first && last) form.value.login = `${first}.${last}`;
});
const submit = async () => {
  error.value = '';
  saving.value = true;
  try {
    const payload = { ...form.value,
      gross_salary: Number(form.value.gross_salary),
      hire_immediately: props.canHire && form.value.hire_immediately,
      hired_at: props.canHire && form.value.hire_immediately ? form.value.hired_at : null,
      position_id: form.value.position_id === '' ? null : Number(form.value.position_id),
    };
    const response = await fetch('/api/employees/employees', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat()[0] : result.message || `HTTP ${response.status}`);
    emit('created', result.data);
  } catch (e) { error.value = e.message; }
  finally { saving.value = false; }
};
</script>

<template>
  <div class="employee-modal-backdrop" @click.self="emit('close')">
    <form class="employee-modal" style="position:absolute;top:0;right:0;bottom:0;max-height:100vh;height:100%;width:min(660px,100vw);border-radius:0;overflow-y:auto;" @submit.prevent="submit">
      <header><h2>Заявка на трудоустройство</h2><button type="button" class="employee-close" @click="emit('close')">×</button></header>
      <p class="employee-onboarding-note">До трудоустройства запись не участвует в рабочих процессах, учётная запись Keycloak и письма не создаются.</p>
      <div v-if="error" class="employee-form-error">{{ error }}</div>
      <div class="employee-form-grid three">
        <label class="irlix-field"><span>Пол*</span><select v-model="form.gender" required><option v-for="gender in referenceData.genders || []" :key="gender">{{ gender }}</option></select></label>
        <label class="irlix-field"><span>Имя*</span><input v-model="form.first_name" required maxlength="255" /></label>
        <label class="irlix-field"><span>Фамилия*</span><input v-model="form.last_name" required maxlength="255" /></label>
      </div>
      <label class="irlix-field"><span>Отчество</span><input v-model="form.middle_name" /></label>
      <label class="irlix-field"><span>Логин*</span><input v-model.trim="form.login" pattern="[a-z0-9]+(?:\.[a-z0-9]+)+" required /><small>Рабочая почта: {{ form.login || 'login' }}@irlix.ru</small></label>
      <label class="irlix-field"><span>Личная почта*</span><input v-model="form.personal_email" type="email" required /></label>
      <div class="irlix-field"><span>Подразделение*</span><UiSearchSelect v-model="form.department_id" :options="departmentChoices" placeholder="Выберите подразделение" aria-label="Подразделение сотрудника" search-placeholder="Поиск подразделения" /></div>
      <label class="irlix-field"><span>Должность</span><select v-model="form.position_id" :disabled="!form.department_id"><option value="">Не назначена</option><option v-for="p in availablePositions" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
      <label class="irlix-field"><span>Тип сотрудничества*</span><select v-model="form.cooperation_type" required><option v-for="type in referenceData.cooperation_types || []" :key="type">{{ type }}</option></select></label>
      <label class="irlix-field"><span>Оклад gross*</span><input v-model="form.gross_salary" type="number" min="0" step="0.01" required /></label>
      <label v-if="canHire" class="checkbox-field"><input v-model="form.hire_immediately" type="checkbox" /> Сразу трудоустроить</label>
      <label v-if="canHire && form.hire_immediately" class="irlix-field"><span>Дата трудоустройства*</span><input v-model="form.hired_at" type="date" required /></label>
      <footer><UiButton type="button" variant="secondary" @click="emit('close')">Отмена</UiButton><UiButton type="submit" :disabled="saving || !form.department_id">{{ saving ? 'Сохранение…' : (form.hire_immediately && canHire ? 'Трудоустроить' : 'Подать заявку') }}</UiButton></footer>
    </form>
  </div>
</template>
