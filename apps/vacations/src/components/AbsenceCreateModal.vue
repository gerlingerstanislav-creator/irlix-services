<script setup>
import { computed, ref, watch } from 'vue';
import { UiButton, UiSearchSelect } from '@irlix/ui';
import { api } from '../api';
import { formatDate, statusLabels, typeLabels } from '../constants';
import DateRangePicker from './DateRangePicker.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  employees: { type: Array, default: () => [] },
  forEmployee: { type: Boolean, default: false },
  title: { type: String, default: 'Запланировать отсутствие' },
  eyebrow: { type: String, default: 'НОВОЕ ОТСУТСТВИЕ' },
});
const emit = defineEmits(['close', 'changed']);

const saving = ref(false);
const savingMode = ref('');
const localError = ref('');
const occupied = ref([]);
const occupiedLoading = ref(false);
let occupiedRequest = 0;
const fileInput = ref(null);
const file = ref(null);
const createdAbsenceId = ref(null);
const fileUploaded = ref(false);
const form = ref(emptyForm());

function emptyForm() {
  return {
    employee_id: '',
    type: 'paid_vacation',
    period: { start: '', end: '' },
    comment: '',
  };
}

const employeeOptions = computed(() => [...props.employees]
  .sort((a, b) => String(a.full_name || '').localeCompare(String(b.full_name || ''), 'ru'))
  .map((employee) => ({
    value: employee.id,
    label: `${employee.full_name} · ${employee.department_name || 'Без подразделения'}`,
  })));
const periodConflict = computed(() => {
  const from = form.value.period.start;
  if (!from) return null;
  const until = form.value.period.end || '9999-12-31';
  return occupied.value.find((absence) => {
    const start = String(absence.starts_on || '').slice(0, 10);
    const end = String(absence.ends_on || '9999-12-31').slice(0, 10);
    return start && start <= until && end >= from;
  }) || null;
});
const openEndedType = computed(() => ['sick_leave', 'maternity_leave'].includes(form.value.type));
const documentRequired = computed(() => ['paid_vacation', 'unpaid_vacation', 'sick_leave', 'maternity_leave'].includes(form.value.type));
const documentHint = computed(() => {
  if (form.value.type === 'sick_leave') return 'Листок нетрудоспособности';
  if (form.value.type === 'maternity_leave') return 'Подтверждающий документ';
  if (['paid_vacation', 'unpaid_vacation'].includes(form.value.type)) return 'Заявление на отпуск';
  return 'Документ';
});
const canSave = computed(() => {
  if (occupiedLoading.value || periodConflict.value) return false;
  if (props.forEmployee && !form.value.employee_id) return false;
  if (!form.value.period.start) return false;
  if (!openEndedType.value && !form.value.period.end) return false;
  return true;
});
const localToday = () => {
  const date = new Date();
  const pad = (number) => String(number).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};
const canSubmit = computed(() => {
  if (!canSave.value) return false;
  if (openEndedType.value && !form.value.period.end) return false;
  if (form.value.type === 'sick_leave' && form.value.period.end > localToday()) return false;
  if (documentRequired.value && !file.value && !fileUploaded.value) return false;
  return true;
});
const submitHint = computed(() => form.value.type === 'sick_leave'
  ? 'Больничный можно запланировать заранее. Отправить его на кадровое подтверждение можно после даты окончания, приложив листок нетрудоспособности.'
  : '«Запланировать» сохраняет отсутствие в статусе «Запланировано». Для отправки отпуска на согласование заявление должно быть приложено.');

const reset = () => {
  occupiedRequest += 1;
  occupiedLoading.value = false;
  form.value = emptyForm();
  file.value = null;
  localError.value = '';
  occupied.value = [];
  createdAbsenceId.value = null;
  fileUploaded.value = false;
  savingMode.value = '';
  if (fileInput.value) fileInput.value.value = '';
};
const close = () => {
  if (saving.value) return;
  reset();
  emit('close');
};

const loadOccupied = async () => {
  const requestId = ++occupiedRequest;
  if (!props.open) return;
  if (props.forEmployee && !form.value.employee_id) {
    occupied.value = [];
    occupiedLoading.value = false;
    return;
  }
  occupiedLoading.value = true;
  occupied.value = [];
  localError.value = '';
  try {
    const params = new URLSearchParams();
    if (props.forEmployee) params.set('employee_id', String(form.value.employee_id));
    const payload = await api(`/api/vacations/absences/occupied${params.size ? `?${params}` : ''}`);
    if (requestId === occupiedRequest && props.open) occupied.value = payload.data || [];
  } catch (error) {
    if (requestId === occupiedRequest && props.open) localError.value = error.message;
  } finally {
    if (requestId === occupiedRequest) occupiedLoading.value = false;
  }
};

const onFile = (event) => {
  const selected = event.target.files?.[0] || null;
  file.value = selected;
  fileUploaded.value = false;
  localError.value = '';
};
const clearFile = () => {
  if (fileUploaded.value) return;
  file.value = null;
  if (fileInput.value) fileInput.value.value = '';
};
const uploadFile = async (absenceId) => {
  if (!file.value || fileUploaded.value) return;
  const body = new FormData();
  body.append('file', file.value);
  body.append('kind', 'application');
  await api(`/api/vacations/absences/${absenceId}/attachments`, { method: 'POST', body });
  fileUploaded.value = true;
};

const persist = async (mode = 'planned') => {
  if (!canSave.value || (mode === 'submit' && !canSubmit.value)) return;
  saving.value = true;
  savingMode.value = mode;
  localError.value = '';
  try {
    let absenceId = createdAbsenceId.value;
    if (!absenceId) {
      const body = {
        type: form.value.type,
        starts_on: form.value.period.start,
        ends_on: form.value.period.end || null,
        comment: form.value.comment || null,
      };
      if (props.forEmployee) body.employee_id = Number(form.value.employee_id);
      const payload = await api(props.forEmployee ? '/api/vacations/absences/for-employee' : '/api/vacations/absences', { method: 'POST', body });
      absenceId = Number(payload.data?.id || 0);
      createdAbsenceId.value = absenceId || null;
    }

    if (file.value && absenceId && !fileUploaded.value) await uploadFile(absenceId);
    if (mode === 'submit' && absenceId) {
      await api(`/api/vacations/absences/${absenceId}/submit`, { method: 'POST' });
    }

    emit('changed', { mode, absenceId });
    close();
  } catch (error) {
    localError.value = error.message;
    if (createdAbsenceId.value && !fileUploaded.value && file.value) {
      localError.value = `Отсутствие создано, но документ не загрузился: ${error.message}. Можно повторить загрузку в этом окне.`;
    }
  } finally {
    saving.value = false;
    savingMode.value = '';
  }
};

watch(() => props.open, (value) => {
  if (value) {
    reset();
    if (!props.forEmployee) loadOccupied();
  }
});
watch(() => form.value.employee_id, () => {
  if (props.forEmployee && props.open && !createdAbsenceId.value) {
    form.value.period = { start: '', end: '' };
    loadOccupied();
  }
});
watch(() => form.value.type, () => { localError.value = ''; });
</script>

<template>
  <div v-if="open" class="overlay" @click.self="close">
    <form class="modal absence-create-modal" @submit.prevent="persist('planned')">
      <div class="modal-head">
        <div><div class="eyebrow">{{ eyebrow }}</div><h2>{{ title }}</h2></div>
        <button class="close" type="button" @click="close">×</button>
      </div>

      <div v-if="localError" class="modal-error">{{ localError }}</div>

      <div v-if="forEmployee" class="irlix-field">
        <label>Сотрудник</label>
        <UiSearchSelect
          v-model="form.employee_id"
          :options="employeeOptions"
          :disabled="Boolean(createdAbsenceId)"
          :clearable="false"
          :teleport="true"
          placeholder="Выберите сотрудника"
          search-placeholder="Поиск по ФИО или подразделению"
          aria-label="Сотрудник"
        />
      </div>

      <label class="irlix-field">Тип отсутствия
        <select v-model="form.type" :disabled="Boolean(createdAbsenceId)">
          <option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option>
        </select>
      </label>

      <DateRangePicker
        v-model="form.period"
        :disabled="Boolean(createdAbsenceId)"
        :disabled-intervals="occupied"
        :allow-open-end="openEndedType"
        :label="openEndedType ? 'Период (дата окончания может быть указана позже)' : 'Период отсутствия'"
      />
      <p v-if="occupiedLoading" class="hint">Проверяем уже занятые даты…</p>
      <p v-if="periodConflict" class="field-error" role="alert">
        Выбранный период пересекается с отсутствием №{{ periodConflict.id }}
        ({{ formatDate(periodConflict.starts_on) }} — {{ periodConflict.ends_on ? formatDate(periodConflict.ends_on) : 'без даты окончания' }},
        {{ statusLabels[periodConflict.status] || periodConflict.status }}).
        Проверьте существующую запись.
      </p>
      <p v-if="openEndedType" class="hint">Для больничного и декрета достаточно даты начала. До отправки на согласование период нужно закрыть и приложить документ.</p>

      <label class="irlix-field">Комментарий
        <textarea v-model="form.comment" :disabled="Boolean(createdAbsenceId)" rows="3" maxlength="2000" placeholder="Необязательно" />
      </label>

      <div class="irlix-field document-upload-field">
        <span>{{ documentHint }}</span>
        <div class="document-picker">
          <input ref="fileInput" type="file" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" :disabled="fileUploaded" @change="onFile" />
          <button v-if="file && !fileUploaded" type="button" class="document-clear" @click="clearFile">Удалить</button>
        </div>
        <small v-if="fileUploaded">{{ file?.name }} · загружен</small>
        <small v-else-if="file">{{ file.name }}</small>
        <small v-else>Можно прикрепить сейчас или позже. К одному отсутствию допускается один документ.</small>
      </div>

      <p class="hint">{{ submitHint }}</p>
      <div class="actions create-actions">
        <UiButton type="button" variant="secondary" @click="close">Отмена</UiButton>
        <UiButton type="submit" variant="secondary" :disabled="saving || !canSave">{{ saving && savingMode === 'planned' ? 'Сохраняем…' : 'Запланировать' }}</UiButton>
        <UiButton type="button" :disabled="saving || !canSubmit" @click="persist('submit')">{{ saving && savingMode === 'submit' ? 'Отправляем…' : 'Отправить на согласование' }}</UiButton>
      </div>
    </form>
  </div>
</template>