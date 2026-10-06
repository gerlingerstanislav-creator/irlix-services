<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import UiIcon from './UiIcon.vue';
import { useAnchoredPopover } from '../useAnchoredPopover';
import { formatPeriod, parsePeriod, periodMonths, shiftPeriod } from '../period';

const props = defineProps({
  modelValue: { type: [String, Number], required: true },
  mode: { type: String, default: 'month', validator: value => ['month', 'year'].includes(value) },
  minYear: { type: Number, default: 1900 },
  maxYear: { type: Number, default: 2100 },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'change']);
const id = useId(), trigger = ref(null), popup = ref(null);
const { open, style, close } = useAnchoredPopover(trigger, popup, { width: 248, align: 'right' });
const selected = computed(() => parsePeriod(props.modelValue, props.mode));
const browseYear = ref(selected.value?.year || new Date().getFullYear());
const yearsView = ref(props.mode === 'year');
const firstYear = computed(() => Math.floor(browseYear.value / 12) * 12);
const validYear = year => year >= props.minYear && year <= props.maxYear;
const label = computed(() => !selected.value ? 'Выберите период' : props.mode === 'year' ? `${selected.value.year} год` : `${periodMonths[selected.value.month]} ${selected.value.year}`);
const heading = computed(() => yearsView.value ? `${firstYear.value} — ${firstYear.value + 11}` : String(browseYear.value));
const cells = computed(() => Array.from({ length: 12 }, (_, i) => yearsView.value
  ? { value: firstYear.value + i, label: String(firstYear.value + i), selected: selected.value?.year === firstYear.value + i, disabled: !validYear(firstYear.value + i) }
  : { value: i, label: periodMonths[i], selected: selected.value?.year === browseYear.value && selected.value?.month === i, disabled: !validYear(browseYear.value) }));
function commit(period) {
  if (!validYear(period.year)) return;
  let value = formatPeriod(period, props.mode);
  if (props.mode === 'year' && typeof props.modelValue === 'string') value = String(value);
  emit('update:modelValue', value);
  emit('change', value);
}
const canStep = delta => !props.disabled && !!selected.value && validYear(shiftPeriod(selected.value, delta, props.mode).year);
function step(delta) { if (canStep(delta)) { commit(shiftPeriod(selected.value, delta, props.mode)); close(); } }
const canBrowse = delta => yearsView.value ? (delta < 0 ? firstYear.value > props.minYear : firstYear.value + 11 < props.maxYear) : validYear(browseYear.value + delta);
function browse(delta) { if (canBrowse(delta)) browseYear.value += delta * (yearsView.value ? 12 : 1); }
async function focusCell() {
  await nextTick();
  (popup.value?.querySelector('[aria-pressed="true"]:not(:disabled)') || popup.value?.querySelector('.ui-period-picker__grid button:not(:disabled)'))?.focus({ preventScroll: true });
}
async function show() {
  if (props.disabled) return;
  browseYear.value = Math.min(props.maxYear, Math.max(props.minYear, selected.value?.year || new Date().getFullYear()));
  yearsView.value = props.mode === 'year';
  open.value = true;
  await focusCell();
}
async function choose(cell) {
  if (cell.disabled) return;
  if (yearsView.value && props.mode === 'month') { browseYear.value = cell.value; yearsView.value = false; await focusCell(); return; }
  commit({ year: yearsView.value ? cell.value : browseYear.value, month: yearsView.value ? 0 : cell.value });
  close(true);
}
function gridKey(event) {
  const buttons = [...popup.value.querySelectorAll('.ui-period-picker__grid button')];
  let index = buttons.indexOf(document.activeElement);
  if (index < 0) return;
  const delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -3, ArrowDown: 3 }[event.key];
  if (delta !== undefined) {
    event.preventDefault();
    for (let n = 0; n < buttons.length; n++) { index = (index + delta + buttons.length) % buttons.length; if (!buttons[index].disabled) { buttons[index].focus({ preventScroll: true }); buttons[index].scrollIntoView({ block: 'nearest' }); break; } }
  }
  if (event.key === 'Home' || event.key === 'End') { event.preventDefault(); const enabled = buttons.filter(b => !b.disabled); (event.key === 'Home' ? enabled[0] : enabled.at(-1))?.focus({ preventScroll: true }); }
}
function onFocusOut(event) {
  const target = event.relatedTarget;
  if (target) {
    if (!popup.value?.contains(target) && target !== trigger.value) close();
    return;
  }
  // Native focus transfer completes after focusout; do not remove the click target mid-event.
  requestAnimationFrame(() => { if (open.value && !popup.value?.contains(document.activeElement) && document.activeElement !== trigger.value) close(); });
}
watch(() => props.disabled, value => { if (value) close(); });
watch(() => props.mode, () => close());
</script>

<template>
  <div class="ui-period-picker" :class="{ 'is-disabled': disabled }" data-component="ui-period-picker">
    <button type="button" class="ui-period-picker__step" :aria-label="mode === 'year' ? 'Предыдущий год' : 'Предыдущий месяц'" :disabled="!canStep(-1)" @click="step(-1)"><UiIcon name="chevron-left" /></button>
    <button ref="trigger" type="button" class="ui-period-picker__value" :disabled="disabled" :aria-label="mode === 'year' ? 'Выбрать год' : 'Выбрать месяц и год'" aria-haspopup="dialog" :aria-expanded="open" :aria-controls="`${id}-calendar`" @click="open ? close() : show()" @keydown.down.prevent="show">{{ label }}</button>
    <button type="button" class="ui-period-picker__step" :aria-label="mode === 'year' ? 'Следующий год' : 'Следующий месяц'" :disabled="!canStep(1)" @click="step(1)"><UiIcon name="chevron-right" /></button>
  </div>
  <Teleport to="body">
    <div v-if="open" :id="`${id}-calendar`" ref="popup" class="ui-period-picker-popup irlix-ui" :style="style" role="dialog" :aria-label="mode === 'year' ? 'Выбор года' : 'Выбор месяца и года'" @keydown="gridKey" @focusout="onFocusOut">
      <div class="ui-period-picker__heading">
        <button type="button" :disabled="!canBrowse(-1)" :aria-label="yearsView ? 'Предыдущие годы' : 'Предыдущий год'" @click="browse(-1)"><UiIcon name="chevron-left" /></button>
        <button v-if="mode === 'month'" type="button" class="ui-period-picker__title" :aria-label="yearsView ? 'Выбрать месяц' : 'Выбрать год'" @click="yearsView = !yearsView">{{ heading }}</button>
        <span v-else class="ui-period-picker__title">{{ heading }}</span>
        <button type="button" :disabled="!canBrowse(1)" :aria-label="yearsView ? 'Следующие годы' : 'Следующий год'" @click="browse(1)"><UiIcon name="chevron-right" /></button>
      </div>
      <div class="ui-period-picker__grid"><button v-for="cell in cells" :key="`${yearsView}:${cell.value}`" type="button" :aria-pressed="cell.selected" :disabled="cell.disabled" @click="choose(cell)">{{ cell.label }}</button></div>
    </div>
  </Teleport>
</template>

<style>
.ui-period-picker { display:inline-flex; align-items:center; flex:none; height:var(--irlix-control-height); border:1px solid var(--irlix-control-border); border-radius:var(--irlix-control-radius); background:var(--irlix-color-surface); color:var(--irlix-control-text); white-space:nowrap; }
.ui-period-picker > button.ui-period-picker__step,.ui-period-picker > button.ui-period-picker__value { display:flex; align-items:center; justify-content:center; flex:none; height:calc(var(--irlix-control-height) - 2px); margin:0; padding:0; border:0; background:transparent; color:inherit; font-size:var(--irlix-topbar-font-size); cursor:pointer; }
.ui-period-picker__step { width:var(--irlix-period-step-width); }
.ui-period-picker__step:first-child { border-radius:calc(var(--irlix-control-radius) - 1px) 0 0 calc(var(--irlix-control-radius) - 1px); }
.ui-period-picker__step:last-child { border-radius:0 calc(var(--irlix-control-radius) - 1px) calc(var(--irlix-control-radius) - 1px) 0; }
.ui-period-picker > .ui-period-picker__value { padding:0 var(--irlix-period-label-padding); }
.ui-period-picker svg,.ui-period-picker-popup svg { width:14px; height:14px; stroke:currentColor; stroke-width:1.7; stroke-linecap:round; stroke-linejoin:round; }
.ui-period-picker button:hover:not(:disabled),.ui-period-picker-popup button:hover:not(:disabled) { background:var(--irlix-color-surface-muted); }
.ui-period-picker button:focus-visible,.ui-period-picker-popup button:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:-2px; }
.ui-period-picker button:disabled,.ui-period-picker-popup button:disabled { opacity:.45; cursor:not-allowed; }
.ui-period-picker-popup { z-index:var(--irlix-popover-z-index); overflow:auto; padding:10px; border:1px solid var(--irlix-color-border); border-radius:var(--irlix-control-radius); background:var(--irlix-color-surface); box-shadow:var(--irlix-popover-shadow); }
.ui-period-picker-popup.irlix-ui button { min-height:var(--irlix-control-height); padding:0 4px; border:0; border-radius:var(--irlix-radius-sm); background:transparent; color:var(--irlix-color-text); font-size:var(--irlix-topbar-font-size); cursor:pointer; }
.ui-period-picker__heading { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
.ui-period-picker__heading > button { display:flex; align-items:center; justify-content:center; min-width:28px; }
.ui-period-picker__title { font-size:var(--irlix-topbar-font-size); font-weight:600; }
.ui-period-picker__grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:4px; }
.ui-period-picker__grid > button[aria-pressed="true"] { background:var(--irlix-color-primary-soft); color:var(--irlix-color-primary-text); }
</style>
