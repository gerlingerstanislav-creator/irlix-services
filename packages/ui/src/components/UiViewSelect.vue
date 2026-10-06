<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import UiIcon from './UiIcon.vue';
import { useAnchoredPopover } from '../useAnchoredPopover';

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, default: () => [] },
  ariaLabel: { type: String, default: 'Формат отображения' },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'change']);
const trigger = ref(null), menu = ref(null);
const id = useId();
const { open, style, close } = useAnchoredPopover(trigger, menu);
const options = computed(() => props.options.map(o => typeof o === 'object' && o !== null ? o : { value: o, label: String(o) }));
const selected = o => String(o.value) === String(props.modelValue);
const label = computed(() => options.value.find(selected)?.label || 'Выберите вид');
const focusOption = index => {
  const buttons = [...(menu.value?.querySelectorAll('button:not(:disabled)') || [])];
  buttons[(index + buttons.length) % buttons.length]?.focus();
};
async function show() {
  if (props.disabled) return;
  open.value = true;
  await nextTick();
  const buttons = [...(menu.value?.querySelectorAll('button:not(:disabled)') || [])];
  (buttons.find(b => b.getAttribute('aria-selected') === 'true') || buttons[0])?.focus();
}
function choose(option) {
  emit('update:modelValue', option.value);
  emit('change', option.value);
  close(true);
}
function navigate(event) {
  const buttons = [...menu.value.querySelectorAll('button:not(:disabled)')];
  const index = buttons.indexOf(document.activeElement);
  const keys = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: buttons.length - 1 };
  if (event.key in keys) { event.preventDefault(); focusOption(keys[event.key]); }
  if (event.key === 'Tab') close();
}
watch(() => props.disabled, value => { if (value) close(); });
</script>

<template>
  <button ref="trigger" type="button" class="ui-view-select" :disabled="disabled" :aria-label="ariaLabel" aria-haspopup="listbox" :aria-expanded="open" :aria-controls="`${id}-menu`" @click="open ? close() : show()" @keydown.down.prevent="show" @keydown.up.prevent="show">
    <span>{{ label }}</span><UiIcon name="chevron-down" :class="{ 'is-open': open }" />
  </button>
  <Teleport to="body">
    <div v-if="open" :id="`${id}-menu`" ref="menu" class="ui-view-select-menu irlix-ui" :style="style" role="listbox" :aria-label="ariaLabel" @keydown="navigate">
      <button v-for="option in options" :key="option.value" type="button" role="option" :aria-selected="selected(option)" :disabled="option.disabled" @click="choose(option)">{{ option.label }}</button>
    </div>
  </Teleport>
</template>

<style>
.ui-view-select { display:inline-flex; align-items:center; gap:var(--irlix-space-1); flex:none; min-height:var(--irlix-control-height); padding:0; border:0; border-radius:0; background:transparent; color:var(--irlix-color-primary-text); font-size:var(--irlix-topbar-font-size); font-weight:600; white-space:nowrap; cursor:pointer; box-shadow:none; }
.ui-view-select svg { width:14px; height:14px; stroke:currentColor; stroke-width:1.7; stroke-linecap:round; stroke-linejoin:round; transition:transform .12s; }
.ui-view-select svg.is-open { transform:rotate(180deg); }
.ui-view-select:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:2px; }
.ui-view-select:disabled { opacity:.45; cursor:not-allowed; }
.ui-view-select-menu { z-index:var(--irlix-popover-z-index); padding:4px; overflow:auto; border:1px solid var(--irlix-color-border); border-radius:var(--irlix-control-radius); background:var(--irlix-color-surface); box-shadow:var(--irlix-popover-shadow); }
.ui-view-select-menu > button { display:block; width:100%; min-height:32px; padding:8px 10px; border:0; border-radius:var(--irlix-radius-sm); background:transparent; color:var(--irlix-color-text); font-size:var(--irlix-topbar-font-size); text-align:left; cursor:pointer; }
.ui-view-select-menu > button:hover { background:var(--irlix-color-surface-muted); }
.ui-view-select-menu > button[aria-selected="true"] { color:var(--irlix-color-primary-text); }
.ui-view-select-menu > button:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:-2px; }
.ui-view-select-menu > button:disabled { opacity:.45; cursor:not-allowed; }
</style>
