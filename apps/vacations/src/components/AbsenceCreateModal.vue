<script setup>
import { computed, ref, watch } from 'vue';
import { UiButton } from '@irlix/ui';
import { api } from '../api';
import { typeLabels } from '../constants';
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
const localError = ref('');
const occupied = ref([]);
const occupiedLoading = ref(false);
const fileInput = ref(null);
const file = ref(null);
const createdAbsenceId = ref(null);
const form = ref(emptyForm());

function emptyForm() {
  return {
    employee_id: '',
    type: 'paid_vacation',
    period: { start: '', end: '' },
    comment: '',
  };
}

const employeesForCreate = computed(() => [...props.employees].sort((a, b) => String(a.full_name || '').localeCompare(String(b.full_name || ''), 'ru')));
const openEndedType = computed(() => ['sick_leave', 'maternity_leave'].includes(form.value.type));
const documentHint = computed(() => {
  if (form.value.type === 'sick_leave') return 'Листок нетрудоспособности';
  if (form.value.type === 'maternity_leave') return 'Подтверждающий документ';
  if (['paid_vacation', 'unpaid_vacation'].includes(form.value.type)) return 'Заявление на отпуск';
  return 'Документ';
});
const canSave = computed(() => {
  if (createdAbsenceId.value) return Boolean(file.value);
  if (props.forEmployee && !form.value.employee_id) return false;
  if (!form.value.period.start) return false;
  if (!openEndedType.value && !form.value.period.end) return false;
  return true;
});

const reset = () => {
  form.value = emptyForm();
  file.value = null;
  localError.value = '';
  occupied.value = [];
  createdAbsenceId.value = null;
  if (fileInput.value) fileInput.value.value = '';
};
const close = () => {
  if (saving.value) return;
  reset();
  emit('close');
};

const loadOccupied = async () => {
  if (!props.open) return;
  if (props.forEmployee && !form.value.employee_id) {
    occupied.value = [];
    return;
  }
  occupiedLoading.value = true;
  localError.value = '';
  try {
    const params = new URLSearchParams();
    if (props.forEmployee) params.set('employee_id', String(form.value.employee_id));
    const payload = await api(`/api/vacations/absences/occupied${params.size ? `?${params}` : ''}`);
    occupied.value = payload.data || [];
  } catch (error) {
    localError.value = error.message;
  } finally {
    occupiedLoading.value = false;
  }
};

const onFile = (event) => {
  const selected = event.target.files?.[0] || null;
  file.value = selected;
  localError.value = '';
};
const clearFile = () => {
  file.value = null;
  if (fileInput.value) fileInput.value.value = '';
};
const uploadFile = async (absenceId) => {
  if (!file.value) return;
  const body = new FormData();
  body.append('file', file.value);
  body.append('kind', 'application');
  await api(`/api/vacations/absences/${absenceId}/attachments`, { method: 'POST', body });
};

const save = async () => {
  if (!canSave.value) return;
  saving.value = true;
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

    if (file.value && absenceId) await uploadFile(absenceId);
    emit('changed');
    close();
  } catch (error) {
    localError.value = error.message;
    if (createdAbsenceId.value) {
      localError.value = `Отсутствие создано, но документ не загрузился: ${error.message}. Можно повторить загрузку в этом окне.`;
    }
  } finally {
    saving.value = false;
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
    <form class="modal absence-create-modal" @submit.prevent="save">
      <div class="modal-head">
        <div><div class="eyebrow">{{ eyebrow }}</div><h2>{{ title }}</h2></div>
        <button class="close" type="button" @click="close">×</button>
      </div>

      <div v-if="localError" class="modal-error">{{ localError }}</div>

      <label v-if="forEmployee" class="irlix-field">Сотрудник
        <select v-model="form.employee_id" :disabled="Boolean(createdAbsenceId)" required>
          <option value="" disabled>Выберите сотрудника</option>
          <option v-for="employee in employeesForCreate" :key="employee.id" :value="employee.id">{{ employee.full_name }} · {{ employee.department_name || 'Без подразделения' }}</option>
        </select>
      </label>

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
      <p v-if="openEndedType" class="hint">Для больничного и декрета достаточно даты начала. До отправки на согласование период нужно закрыть и приложить документ.</p>

      <label class="irlix-field">Комментарий
        <textarea v-model="form.comment" :disabled="Boolean(createdAbsenceId)" rows="3" maxlength="2000" placeholder="Необязательно" />
      </label>

      <div class="irlix-field document-upload-field">
        <span>{{ documentHint }}</span>
        <div class="document-picker">
          <input ref="fileInput" type="file" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" @change="onFile" />
          <button v-if="file" type="button" class="document-clear" @click="clearFile">Удалить</button>
        </div>
        <small v-if="file">{{ file.name }}</small>
        <small v-else>Можно прикрепить сейчас или позже. К одному отсутствию допускается один документ.</small>
      </div>

      <p class="hint">Отсутствие создаётся в статусе «Запланировано». Для отпуска заявление должно быть приложено до отправки на согласование.</p>
      <div class="actions">
        <UiButton type="button" variant="secondary" @click="close">Отмена</UiButton>
        <UiButton type="submit" :disabled="saving || !canSave">{{ saving ? 'Сохраняем…' : (createdAbsenceId ? 'Повторить загрузку' : 'Запланировать') }}</UiButton>
      </div>
    </form>
  </div>
</template>
