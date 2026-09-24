<script setup>
import { computed, ref, watch } from 'vue';
import { UiButton } from '@irlix/ui';

const props = defineProps({ departments: { type: Array, default: () => [] }, referenceData: { type: Object, required: true } });
const emit = defineEmits(['close', 'created']);
const error = ref('');
const saving = ref(false);
const loginChecking = ref(false);
const loginManuallyEdited = ref(false);
const loginOptions = ref([]);
const createdResult = ref(null);
let loginTimer = null;

const form = ref({ gender: 'Мужчина', first_name: '', last_name: '', middle_name: '', login: '', hired_at: new Date().toISOString().slice(0, 10), cooperation_type: 'Штат', personal_email: '', department_id: '', position: '' });
const workEmail = computed(() => form.value.login ? `${form.value.login}@irlix.ru` : 'Будет сформирована из логина');
const currentLoginState = computed(() => loginOptions.value.find((item) => item.login === form.value.login) ?? null);
const loginUnavailable = computed(() => currentLoginState.value?.available === false);

const transliterate = (value = '') => {
  const map = { а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'e',ж:'zh',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'h',ц:'ts',ч:'ch',ш:'sh',щ:'sch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya' };
  return value.trim().toLowerCase().split('').map((char) => map[char] ?? char).join('').replace(/[^a-z0-9]+/g, '.').replace(/^\.+|\.+$/g, '').replace(/\.{2,}/g, '.');
};

const generatedLogin = computed(() => {
  const first = transliterate(form.value.first_name);
  const last = transliterate(form.value.last_name);
  return first && last ? `${first}.${last}` : '';
});

const loadLoginOptions = async () => {
  if (!form.value.first_name || !form.value.last_name || !form.value.login) { loginOptions.value = []; return; }
  loginChecking.value = true;
  try {
    const params = new URLSearchParams({ first_name: form.value.first_name, last_name: form.value.last_name, login: form.value.login });
    const response = await fetch(`/api/employees/login-suggestions?${params}`, { headers: { Accept: 'application/json' } });
    const payload = await response.json().catch(() => ({}));
    if (response.ok) loginOptions.value = payload.data ?? [];
  } finally { loginChecking.value = false; }
};

const scheduleLoginCheck = () => {
  clearTimeout(loginTimer);
  loginTimer = setTimeout(loadLoginOptions, 250);
};

watch([() => form.value.first_name, () => form.value.last_name], () => {
  if (!loginManuallyEdited.value) form.value.login = generatedLogin.value;
  scheduleLoginCheck();
});
watch(() => form.value.login, scheduleLoginCheck);

const chooseLogin = (login) => { form.value.login = login; loginManuallyEdited.value = true; scheduleLoginCheck(); };
const finish = () => emit('created', createdResult.value.data);

const submit = async () => {
  error.value = '';
  if (loginUnavailable.value) { error.value = 'Этот логин уже занят. Выберите свободный вариант или введите другой.'; return; }
  saving.value = true;
  try {
    const response = await fetch('/api/employees/employees', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(form.value) });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat()[0] : payload.message || `HTTP ${response.status}`);
    createdResult.value = payload;
  } catch (e) { error.value = e.message; }
  finally { saving.value = false; }
};
</script>

<template>
  <div class="employee-modal-backdrop" @click.self="emit('close')">
    <div v-if="createdResult" class="employee-modal identity-result">
      <header><h2>Сотрудник добавлен</h2><button type="button" class="employee-close" @click="finish">×</button></header>
      <div class="identity-result-body">
        <p><strong>{{ createdResult.data.full_name }}</strong> создан в Employees.</p>
        <dl>
          <div><dt>Логин</dt><dd><code>{{ createdResult.data.login }}</code></dd></div>
          <div><dt>Рабочая почта</dt><dd><code>{{ createdResult.data.work_email }}</code></dd></div>
          <div><dt>Identity</dt><dd>{{ createdResult.data.identity_status }}</dd></div>
          <div v-if="createdResult.meta?.temporary_password"><dt>Временный пароль</dt><dd><code>{{ createdResult.meta.temporary_password }}</code><small>Показывается только сейчас. При первом входе Keycloak потребует сменить пароль.</small></dd></div>
        </dl>
        <div v-if="createdResult.meta?.warning" class="employee-form-error">{{ createdResult.meta.warning }}</div>
      </div>
      <footer><UiButton type="button" @click="finish">Готово</UiButton></footer>
    </div>

    <form v-else class="employee-modal" @submit.prevent="submit">
      <header><h2>Новый сотрудник</h2><button type="button" class="employee-close" @click="emit('close')">×</button></header>
      <div class="employee-onboarding-note">Сотрудник будет добавлен со статусом «Трудоустроен». Для него будет создана учётная запись Keycloak с временным паролем и обязательной сменой пароля при первом входе.</div>
      <div v-if="error" class="employee-form-error">{{ error }}</div>
      <div class="employee-form-grid three">
        <label class="irlix-field"><span>Пол*</span><select v-model="form.gender" required><option v-for="gender in referenceData.genders || []" :key="gender">{{ gender }}</option></select></label>
        <label class="irlix-field"><span>Имя*</span><input v-model="form.first_name" maxlength="32" required placeholder="Станислав" /></label>
        <label class="irlix-field"><span>Фамилия*</span><input v-model="form.last_name" maxlength="32" required placeholder="Герлингер" /></label>
      </div>
      <label class="irlix-field login-field-wrapper">
        <span>Логин*</span>
        <div class="login-field"><input v-model.trim="form.login" required pattern="[a-z0-9]+(?:\.[a-z0-9]+)+" placeholder="stanislav.gerlinger" @input="loginManuallyEdited = true" /><strong>@irlix.ru</strong></div>
        <small v-if="loginChecking">Проверяем доступность…</small>
        <small v-else-if="loginUnavailable" class="login-conflict">Такой логин уже существует. Выберите свободную альтернативу:</small>
        <small v-else-if="form.login">Логин свободен · рабочая почта: {{ workEmail }}</small>
        <div v-if="loginUnavailable" class="login-suggestions">
          <button v-for="option in loginOptions.filter((item) => item.available && item.login !== form.login)" :key="option.login" type="button" @click="chooseLogin(option.login)">{{ option.login }}</button>
        </div>
      </label>
      <div class="employee-form-grid two">
        <label class="irlix-field"><span>Дата приёма*</span><input v-model="form.hired_at" type="date" required /></label>
        <label class="irlix-field"><span>Тип сотрудничества*</span><select v-model="form.cooperation_type" required><option v-for="type in referenceData.cooperation_types || []" :key="type">{{ type }}</option></select></label>
      </div>
      <label class="irlix-field"><span>Персональная почта*</span><input v-model="form.personal_email" type="email" required placeholder="bestworker@gmail.com" /><small>Позже сюда будет отправляться onboarding-письмо с данными первого входа.</small></label>
      <label class="irlix-field"><span>Подразделение*</span><select v-model="form.department_id" required><option value="" disabled>Выберите подразделение</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></label>
      <label class="irlix-field"><span>Должность</span><input v-model="form.position" placeholder="Должность сотрудника" /></label>
      <footer><UiButton type="button" variant="secondary" @click="emit('close')">Отмена</UiButton><UiButton type="submit" :disabled="saving || loginChecking || loginUnavailable">{{ saving ? 'Добавление…' : '✓ Добавить' }}</UiButton></footer>
    </form>
  </div>
</template>
