<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: Object, default: () => ({ start: '', end: '' }) },
  disabledIntervals: { type: Array, default: () => [] },
  allowOpenEnd: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  label: { type: String, default: 'Период отсутствия' },
  error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const root = ref(null);
const draftStart = ref('');
const draftEnd = ref('');
const localError = ref('');
const today = new Date();
const anchor = ref(new Date(today.getFullYear(), today.getMonth(), 1));

const pad = (value) => String(value).padStart(2, '0');
const iso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const parseIso = (value) => {
  if (!value) return null;
  const [year, month, day] = value.slice(0, 10).split('-').map(Number);
  return new Date(year, month - 1, day);
};
const addDays = (date, amount) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + amount);
const startOfWeek = (date) => {
  const day = date.getDay() || 7;
  return addDays(date, 1 - day);
};
const formatDate = (value) => {
  const date = parseIso(value);
  return date ? new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'short', year: 'numeric' }).format(date).replace(' г.', '') : '';
};
const fieldText = computed(() => {
  if (!props.modelValue?.start) return 'Выберите даты';
  if (!props.modelValue?.end) return `${formatDate(props.modelValue.start)} — дата окончания не указана`;
  return `${formatDate(props.modelValue.start)} — ${formatDate(props.modelValue.end)}`;
});

const monthTitle = (date) => new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric' }).format(date);
const secondMonth = computed(() => new Date(anchor.value.getFullYear(), anchor.value.getMonth() + 1, 1));
const weekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

const monthDays = (monthDate) => {
  const first = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
  const offset = (first.getDay() || 7) - 1;
  const gridStart = addDays(first, -offset);
  return Array.from({ length: 42 }, (_, index) => {
    const date = addDays(gridStart, index);
    const value = iso(date);
    return {
      value,
      day: date.getDate(),
      outside: date.getMonth() !== monthDate.getMonth(),
      disabled: isDisabled(value),
    };
  });
};

const isDisabled = (value) => props.disabledIntervals.some((interval) => {
  const from = String(interval.starts_on || interval.start || '').slice(0, 10);
  const to = String(interval.ends_on || interval.end || '9999-12-31').slice(0, 10);
  return from && value >= from && value <= to;
});
const crossesDisabled = (from, to) => {
  if (!from || !to) return false;
  for (let cursor = parseIso(from), end = parseIso(to); cursor <= end; cursor = addDays(cursor, 1)) {
    if (isDisabled(iso(cursor))) return true;
  }
  return false;
};
const inDraftRange = (value) => draftStart.value && draftEnd.value && value >= draftStart.value && value <= draftEnd.value;

const syncDraft = () => {
  draftStart.value = props.modelValue?.start || '';
  draftEnd.value = props.modelValue?.end || '';
  localError.value = '';
  const source = parseIso(draftStart.value) || today;
  anchor.value = new Date(source.getFullYear(), source.getMonth(), 1);
};
const openPicker = () => {
  if (props.disabled) return;
  syncDraft();
  open.value = true;
};
const clear = () => {
  if (props.disabled) return;
  draftStart.value = '';
  draftEnd.value = '';
  emit('update:modelValue', { start: '', end: '' });
  localError.value = '';
};
const selectDate = (value) => {
  if (props.disabled || isDisabled(value)) return;
  localError.value = '';
  if (!draftStart.value || draftEnd.value) {
    draftStart.value = value;
    draftEnd.value = '';
    return;
  }
  if (value < draftStart.value) {
    draftStart.value = value;
    draftEnd.value = '';
    return;
  }
  if (crossesDisabled(draftStart.value, value)) {
    localError.value = 'Выбранный период пересекается с уже запланированным отсутствием';
    return;
  }
  draftEnd.value = value;
};
const confirm = () => {
  if (!draftStart.value) return;
  if (!props.allowOpenEnd && !draftEnd.value) {
    localError.value = 'Выберите дату окончания';
    return;
  }
  emit('update:modelValue', { start: draftStart.value, end: draftEnd.value });
  open.value = false;
};
const cancel = () => { open.value = false; syncDraft(); };
const shiftMonth = (amount) => { anchor.value = new Date(anchor.value.getFullYear(), anchor.value.getMonth() + amount, 1); };

const applyPreset = (kind) => {
  const current = new Date();
  let from = current;
  let to = current;
  if (kind === 'tomorrow') from = to = addDays(current, 1);
  if (kind === 'this-week') { from = startOfWeek(current); to = addDays(from, 6); }
  if (kind === 'last-week') { from = addDays(startOfWeek(current), -7); to = addDays(from, 6); }
  if (kind === 'this-month') { from = new Date(current.getFullYear(), current.getMonth(), 1); to = new Date(current.getFullYear(), current.getMonth() + 1, 0); }
  if (kind === 'last-month') { from = new Date(current.getFullYear(), current.getMonth() - 1, 1); to = new Date(current.getFullYear(), current.getMonth(), 0); }
  if (kind === 'this-year') { from = new Date(current.getFullYear(), 0, 1); to = new Date(current.getFullYear(), 11, 31); }
  const fromIso = iso(from);
  const toIso = iso(to);
  if (crossesDisabled(fromIso, toIso)) {
    localError.value = 'Этот быстрый период пересекается с уже запланированным отсутствием';
    return;
  }
  draftStart.value = fromIso;
  draftEnd.value = toIso;
  anchor.value = new Date(from.getFullYear(), from.getMonth(), 1);
  localError.value = '';
};

const handleOutside = (event) => {
  if (open.value && root.value && !root.value.contains(event.target)) cancel();
};
onMounted(() => document.addEventListener('pointerdown', handleOutside));
onBeforeUnmount(() => document.removeEventListener('pointerdown', handleOutside));
watch(() => props.modelValue, () => { if (!open.value) syncDraft(); }, { deep: true });
watch(() => props.disabled, (value) => { if (value) open.value = false; });
</script>

<template>
  <div ref="root" class="range-picker">
    <label class="irlix-field range-field">
      {{ label }}
      <button type="button" class="range-input" :disabled="disabled" :class="{ invalid: error || localError }" @click="open ? cancel() : openPicker()">
        <span :class="{ placeholder: !modelValue?.start }">{{ fieldText }}</span>
        <span class="range-icons">
          <span v-if="modelValue?.start && !disabled" class="range-clear" role="button" tabindex="0" aria-label="Очистить период" @click.stop="clear" @keydown.enter.stop="clear">×</span>
          <span aria-hidden="true">▣</span>
        </span>
      </button>
    </label>
    <div v-if="error || localError" class="field-error">{{ localError || error }}</div>

    <div v-if="open" class="range-popover" @pointerdown.stop>
      <aside class="range-presets">
        <button type="button" @click="applyPreset('today')">Сегодня</button>
        <button type="button" @click="applyPreset('tomorrow')">Завтра</button>
        <button type="button" @click="applyPreset('this-week')">Эта неделя</button>
        <button type="button" @click="applyPreset('last-week')">Прошлая неделя</button>
        <button type="button" @click="applyPreset('this-month')">Этот месяц</button>
        <button type="button" @click="applyPreset('last-month')">Прошлый месяц</button>
        <button type="button" @click="applyPreset('this-year')">Этот год</button>
      </aside>

      <div class="range-calendar-area">
        <div class="calendar-months">
          <section v-for="(monthDate, monthIndex) in [anchor, secondMonth]" :key="monthDate.getTime()" class="calendar-month">
            <header>
              <button type="button" class="month-nav" @click="shiftMonth(-1)">‹</button>
              <strong>{{ monthTitle(monthDate) }}</strong>
              <button type="button" class="month-nav" @click="shiftMonth(1)">›</button>
            </header>
            <div class="weekdays"><span v-for="day in weekdays" :key="day">{{ day }}</span></div>
            <div class="month-grid">
              <button
                v-for="day in monthDays(monthDate)"
                :key="`${monthIndex}-${day.value}`"
                type="button"
                :disabled="day.disabled"
                :title="day.disabled ? 'На эту дату уже запланировано отсутствие' : ''"
                :class="{
                  outside: day.outside,
                  disabled: day.disabled,
                  selected: day.value === draftStart || day.value === draftEnd,
                  'in-range': inDraftRange(day.value),
                }"
                @click="selectDate(day.value)"
              >{{ day.day }}</button>
            </div>
          </section>
        </div>
        <div v-if="allowOpenEnd && draftStart && !draftEnd" class="open-end-note">Можно подтвердить только дату начала и закрыть период позже.</div>
        <div v-if="localError" class="calendar-error">{{ localError }}</div>
        <footer class="range-footer">
          <button type="button" class="calendar-cancel" @click="cancel">×&nbsp; Отмена</button>
          <button type="button" class="calendar-confirm" :disabled="!draftStart || (!allowOpenEnd && !draftEnd)" @click="confirm">✓&nbsp; Подтвердить</button>
        </footer>
      </div>
    </div>
  </div>
</template>
