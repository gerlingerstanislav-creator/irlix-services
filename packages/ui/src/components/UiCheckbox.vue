<script setup>
import { computed, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  label: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'change']);
const attrs = useAttrs();
const checked = computed(() => Boolean(props.modelValue));
function onChange(event) {
  const value = event.target.checked;
  emit('update:modelValue', value);
  emit('change', value);
}
</script>

<template>
  <label class="ui-checkbox" :class="{ 'ui-checkbox--disabled': disabled }">
    <input v-bind="attrs" class="ui-checkbox__input" type="checkbox" :checked="checked" :disabled="disabled" @change="onChange" />
    <span class="ui-checkbox__control" aria-hidden="true">
      <svg viewBox="0 0 16 16" fill="none" focusable="false"><path d="m3.5 8 3 3 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </span>
    <span class="ui-checkbox__label"><slot>{{ label }}</slot></span>
  </label>
</template>

<style scoped>
.ui-checkbox {
  display: inline-flex;
  align-items: center;
  gap: var(--irlix-space-2, 8px);
  min-height: 27px;
  cursor: pointer;
  color: var(--irlix-color-text);
  font-size: var(--irlix-font-size-body, 13px);
  line-height: 1.35;
  position: relative;
}
.ui-checkbox__input {
  position: absolute;
  width: 18px;
  height: 18px;
  margin: 0;
  opacity: 0;
  cursor: inherit;
}
.ui-checkbox__control {
  flex: none;
  display: grid;
  place-items: center;
  width: 18px;
  height: 18px;
  border: 1px solid var(--irlix-color-border-strong);
  border-radius: 5px;
  background: var(--irlix-color-surface);
  color: var(--irlix-color-on-accent);
  box-sizing: border-box;
  transition: background-color .12s ease, border-color .12s ease;
}
.ui-checkbox__control svg { width: 14px; height: 14px; visibility: hidden; }
.ui-checkbox__input:checked + .ui-checkbox__control {
  background: var(--irlix-color-primary);
  border-color: var(--irlix-color-primary);
}
.ui-checkbox__input:checked + .ui-checkbox__control svg { visibility: visible; }
.ui-checkbox__input:focus-visible + .ui-checkbox__control {
  outline: 2px solid var(--irlix-color-primary);
  outline-offset: 3px;
}
.ui-checkbox__input:disabled + .ui-checkbox__control { opacity: .45; }
.ui-checkbox--disabled { opacity: .6; cursor: not-allowed; }
.ui-checkbox__label { min-width: 0; }
</style>
