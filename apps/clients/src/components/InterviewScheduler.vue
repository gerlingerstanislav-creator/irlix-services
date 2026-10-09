<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { UiButton } from '@irlix/ui';
const props = defineProps({ open: Boolean, anchor: { type: Object, default: null }, busy: Boolean, error: String, title:{type:String,default:'Назначить интервью'}, includeTime:{type:Boolean,default:true} });
const emit = defineEmits(['close', 'apply']);
const panel = ref(null);
const viewport = ref({ width: window.innerWidth, height: window.innerHeight });
const today = new Date();
const selected = ref(new Date(today.getFullYear(), today.getMonth(), today.getDate()));
const month = ref(new Date(today.getFullYear(), today.getMonth(), 1));
const hour = ref(String(Math.min(23, today.getHours() + 1)).padStart(2, '0'));
const minute = ref('00');
const monthLabel = computed(() => month.value.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }));
const selectedLabel = computed(() => selected.value.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }));
const days = computed(() => {
  const offset = (month.value.getDay() + 6) % 7;
  const first = new Date(month.value.getFullYear(), month.value.getMonth(), 1 - offset);
  return Array.from({ length: 42 }, (_, index) => new Date(first.getFullYear(), first.getMonth(), first.getDate() + index));
});
const sameDay = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
const location = computed(() => {
  const width = Math.min(350, viewport.value.width - 24);
  const anchor = props.anchor || { left: (viewport.value.width - width) / 2, bottom: 100 };
  const top = Math.max(12, Math.min(anchor.bottom + 8, viewport.value.height - 520 - 12));
  return { width: width + 'px', left: Math.max(12, Math.min(anchor.left, viewport.value.width - width - 12)) + 'px',
    top: top + 'px', maxHeight: (viewport.value.height - top - 12) + 'px' };
});
function moveMonth(offset) { month.value = new Date(month.value.getFullYear(), month.value.getMonth() + offset, 1); }
function choose(day) { selected.value = day; month.value = new Date(day.getFullYear(), day.getMonth(), 1); }
function apply() {
  const pad = number => String(number).padStart(2, '0');
  const day = selected.value;
  const date = day.getFullYear() + '-' + pad(day.getMonth() + 1) + '-' + pad(day.getDate());
  emit('apply', props.includeTime ? date + 'T' + hour.value + ':' + minute.value : date);
}
function keydown(event) { if (event.key === 'Escape' && !props.busy) emit('close'); }
function resize() { viewport.value = { width: window.innerWidth, height: window.innerHeight }; }
onMounted(async () => { document.addEventListener('keydown', keydown); window.addEventListener('resize',resize); await nextTick(); panel.value?.focus(); });
onBeforeUnmount(() => { document.removeEventListener('keydown', keydown); window.removeEventListener('resize',resize); });
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="interview-picker-layer">
      <button class="interview-picker-backdrop" aria-label="Закрыть календарь" :disabled="busy" @click="emit('close')" />
      <section ref="panel" class="interview-picker" :style="location" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1">
        <header><strong>{{title}}</strong><button type="button" aria-label="Закрыть календарь" :disabled="busy" @click="emit('close')">×</button></header>
        <div class="month-navigation"><button type="button" aria-label="Предыдущий месяц" :disabled="busy" @click="moveMonth(-1)">‹</button><strong>{{monthLabel}}</strong><button type="button" aria-label="Следующий месяц" :disabled="busy" @click="moveMonth(1)">›</button></div>
        <div class="calendar-grid calendar-weekdays"><span v-for="weekday in ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']" :key="weekday">{{weekday}}</span></div>
        <div class="calendar-grid"><button v-for="day in days" :key="day.toISOString()" type="button" :class="{ muted:day.getMonth()!==month.getMonth(), selected:sameDay(day,selected), today:sameDay(day,today) }" :aria-label="day.toLocaleDateString('ru-RU')" :aria-pressed="sameDay(day,selected)" :disabled="busy" @click="choose(day)">{{day.getDate()}}</button></div>
        <div class="interview-time"><span>{{selectedLabel}}</span><label v-if="includeTime">Время<select v-model="hour" :disabled="busy" aria-label="Часы"><option v-for="value in 24" :key="value" :value="String(value-1).padStart(2,'0')">{{String(value-1).padStart(2,'0')}}</option></select><b>:</b><select v-model="minute" :disabled="busy" aria-label="Минуты"><option v-for="value in 60" :key="value" :value="String(value-1).padStart(2,'0')">{{String(value-1).padStart(2,'0')}}</option></select></label></div>
        <div v-if="error" class="error-banner" role="alert">{{error}}</div>
        <footer><UiButton compact variant="secondary" :disabled="busy" @click="emit('close')">Отмена</UiButton><UiButton compact :disabled="busy" @click="apply">{{busy?'Сохраняю…':'Применить'}}</UiButton></footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.interview-picker-layer{position:fixed;inset:0;z-index:1300}
.interview-picker-backdrop{position:absolute;inset:0;border:0;background:rgba(16,24,40,.08)}
.interview-picker{position:fixed;max-height:calc(100dvh - 24px);overflow:auto;padding:16px;border:1px solid var(--irlix-control-border,#dde1e7);border-radius:14px;background:var(--irlix-color-surface);box-shadow:0 16px 48px rgba(20,30,40,.2);color:#303943;font-size:var(--irlix-font-size-body);outline:0}
.interview-picker header,.month-navigation{display:flex;align-items:center;justify-content:space-between;gap:8px}
.interview-picker header>button,.month-navigation>button{width:30px;height:30px;border:0;border-radius:8px;background:transparent;font:inherit;font-size:var(--irlix-font-size-page-title);cursor:pointer;color:#69737e}
.interview-picker button:not(.ui-button):hover{background:#eff7f4}
.month-navigation{margin:12px 0 8px}.month-navigation strong{font-size:var(--irlix-font-size-table);text-transform:capitalize}
.calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.calendar-weekdays span{text-align:center;color:#929aa3;font-size:var(--irlix-font-size-caption);padding:6px}
.calendar-grid button{aspect-ratio:1;border:0;border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:var(--irlix-font-size-table);cursor:pointer}
.calendar-grid button.muted{color:#adb5bd}.calendar-grid button.today{box-shadow:inset 0 0 0 1px #8dd8c1}
.calendar-grid button.selected{background:var(--irlix-color-primary,#12b890);color:#fff}
.interview-time{display:grid;gap:10px;margin:12px 0;color:#66717e;font-size:var(--irlix-font-size-caption)}
.interview-time label{display:flex;align-items:center;gap:8px}
.interview-time select{height:34px;min-width:64px;padding:0 10px;border:1px solid var(--irlix-control-border,#dde1e7);border-radius:var(--irlix-control-radius,10px);background:var(--irlix-color-surface);color:#303943;font:inherit;font-size:var(--irlix-font-size-body)}
.interview-picker footer{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}
</style>
