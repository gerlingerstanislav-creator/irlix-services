<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number, Array], default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Выберите' },
  searchPlaceholder: { type: String, default: 'Поиск' },
  labelKey: { type: String, default: 'label' },
  valueKey: { type: String, default: 'value' },
  multiple: { type: Boolean, default: false },
  clearable: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
  emptyText: { type: String, default: 'Ничего не найдено' },
  ariaLabel: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'change', 'open', 'close']);
const root = ref(null);
const searchInput = ref(null);
const open = ref(false);
const query = ref('');

const normalized = computed(() => props.options.map((option) => {
  if (option !== null && typeof option === 'object') {
    return {
      value: option[props.valueKey],
      label: String(option[props.labelKey] ?? option[props.valueKey] ?? ''),
      disabled: Boolean(option.disabled),
      kind: option.kind === 'group' ? 'group' : 'option',
      depth: Math.max(0, Number(option.depth || 0)),
    };
  }
  return { value: option, label: String(option ?? ''), disabled: false, kind: 'option', depth: 0 };
}));

const selectableOptions = computed(() => normalized.value.filter((option) => option.kind !== 'group'));
const selectedValues = computed(() => props.multiple
  ? (Array.isArray(props.modelValue) ? props.modelValue : [])
  : [props.modelValue]);

const equals = (a, b) => String(a ?? '') === String(b ?? '');
const isSelected = (value) => selectedValues.value.some((selected) => equals(selected, value));
const selectedOptions = computed(() => selectableOptions.value.filter((option) => isSelected(option.value)));
const displayLabel = computed(() => {
  if (!selectedOptions.value.length) return props.placeholder;
  if (props.multiple) return props.placeholder;
  return selectedOptions.value[0].label;
});
const selectedCount = computed(() => props.multiple ? selectedOptions.value.length : 0);
const hasValue = computed(() => props.multiple
  ? Array.isArray(props.modelValue) && props.modelValue.length > 0
  : props.modelValue !== '' && props.modelValue !== null && props.modelValue !== undefined);
const filtered = computed(() => {
  const needle = query.value.trim().toLocaleLowerCase('ru');
  if (!needle) return normalized.value;

  const result = [];
  let index = 0;
  while (index < normalized.value.length) {
    const current = normalized.value[index];
    if (current.kind !== 'group') {
      if (current.label.toLocaleLowerCase('ru').includes(needle)) result.push(current);
      index += 1;
      continue;
    }

    const children = [];
    let childIndex = index + 1;
    while (childIndex < normalized.value.length && normalized.value[childIndex].kind !== 'group') {
      children.push(normalized.value[childIndex]);
      childIndex += 1;
    }
    const groupMatches = current.label.toLocaleLowerCase('ru').includes(needle);
    const matchingChildren = groupMatches
      ? children
      : children.filter((option) => option.label.toLocaleLowerCase('ru').includes(needle));
    if (matchingChildren.length) result.push(current, ...matchingChildren);
    index = childIndex;
  }
  return result;
});

const setOpen = async (value) => {
  if (props.disabled) return;
  open.value = value;
  if (value) {
    query.value = '';
    emit('open');
    await nextTick();
    searchInput.value?.focus();
  } else emit('close');
};

const select = (option) => {
  if (option.kind === 'group' || option.disabled) return;
  if (props.multiple) {
    const current = Array.isArray(props.modelValue) ? [...props.modelValue] : [];
    const next = isSelected(option.value)
      ? current.filter((value) => !equals(value, option.value))
      : [...current, option.value];
    emit('update:modelValue', next);
    emit('change', next);
    return;
  }
  emit('update:modelValue', option.value);
  emit('change', option.value);
  setOpen(false);
};

const clear = (event) => {
  event.stopPropagation();
  const next = props.multiple ? [] : '';
  emit('update:modelValue', next);
  emit('change', next);
};
const onDocumentPointerDown = (event) => {
  if (open.value && root.value && !root.value.contains(event.target)) setOpen(false);
};
const onKeydown = (event) => {
  if (event.key === 'Escape' && open.value) {
    event.preventDefault();
    setOpen(false);
  }
};

watch(() => props.disabled, (value) => { if (value && open.value) setOpen(false); });
document.addEventListener('pointerdown', onDocumentPointerDown);
document.addEventListener('keydown', onKeydown);
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown);
  document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
  <div ref="root" class="ui-search-select" :class="{ open, disabled, filled: hasValue }">
    <button type="button" class="ui-search-select__trigger" :disabled="disabled" :aria-label="ariaLabel || placeholder" :aria-expanded="open" aria-haspopup="listbox" @click="setOpen(!open)">
      <span v-if="selectedCount" class="ui-search-select__count">{{ selectedCount }}</span>
      <span class="ui-search-select__label">{{ displayLabel }}</span>
      <span class="ui-search-select__actions">
        <span v-if="clearable && hasValue" class="ui-search-select__clear" role="button" aria-label="Сбросить фильтр" @click="clear">×</span>
        <span class="ui-search-select__chevron" aria-hidden="true">
          <svg viewBox="0 0 16 10" focusable="false">
            <path d="M1.5 2 8 8l6.5-6" />
          </svg>
        </span>
      </span>
    </button>
    <div v-if="open" class="ui-search-select__menu">
      <div class="ui-search-select__search-wrap"><input ref="searchInput" v-model="query" class="ui-search-select__search" type="search" :placeholder="searchPlaceholder" @click.stop /></div>
      <div class="ui-search-select__options" role="listbox" :aria-multiselectable="multiple || undefined">
        <template v-for="option in filtered" :key="`${option.kind}-${String(option.value)}-${option.label}`">
          <div v-if="option.kind === 'group'" class="ui-search-select__group">{{ option.label }}</div>
          <button v-else type="button" class="ui-search-select__option" :class="{ selected: isSelected(option.value) }" :style="{ paddingLeft: `${12 + option.depth * 18}px` }" :disabled="option.disabled" role="option" :aria-selected="isSelected(option.value)" @click="select(option)">
            <span class="ui-search-select__marker" :class="{ multiple }" aria-hidden="true"><i v-if="isSelected(option.value)"></i></span>
            <span class="ui-search-select__option-label">{{ option.label }}</span>
          </button>
        </template>
        <div v-if="!filtered.length" class="ui-search-select__empty">{{ emptyText }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ui-search-select { position: relative; width: 100%; min-width: 0; font: inherit; }
.ui-search-select__trigger {
  position: relative;
  width: 100%;
  height: var(--irlix-control-height, 32px);
  min-height: var(--irlix-control-height, 32px);
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 0 50px 0 var(--irlix-control-padding-x, 12px);
  border: 1px solid var(--irlix-control-border, #dde1e7);
  border-radius: var(--irlix-control-radius, 10px);
  background: #fff;
  color: var(--irlix-control-placeholder, #a5abb4);
  font: inherit;
  font-weight: 400;
  text-align: left;
  cursor: pointer;
  box-shadow: none;
}
.ui-search-select__trigger:hover { filter: none; border-color: #cfd4dc; background: #fff; }
.ui-search-select.open .ui-search-select__trigger { border-color: var(--irlix-color-primary); box-shadow: 0 0 0 2px rgba(18, 184, 144, .1); }
.ui-search-select.disabled { opacity: .6; }
.ui-search-select__count { flex: 0 0 auto; min-width: 20px; height: 20px; padding: 0 5px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; background: var(--irlix-color-primary-soft, #e4f8f2); color: var(--irlix-color-primary-text, #008a6b); font-size: 12px; font-weight: 700; line-height: 1; }
.ui-search-select__label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ui-search-select.filled .ui-search-select__label { color: var(--irlix-control-text, #4f5967); }
.ui-search-select__actions {
  position: absolute;
  right: 10px;
  top: 50%;
  width: 38px;
  height: 18px;
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
  transform: translateY(-50%);
  color: #8f969f;
}
.ui-search-select__clear { flex: 0 0 16px; width: 16px; height: 16px; display: grid; place-items: center; border-radius: 4px; font-size: 15px; line-height: 1; }
.ui-search-select__clear:hover { background: #f0f2f4; color: #5e6670; }
.ui-search-select__chevron {
  flex: 0 0 18px;
  width: 18px;
  height: 18px;
  display: grid;
  place-items: center;
  transform: rotate(0deg);
  transform-origin: 50% 50%;
  transition: transform .12s ease;
}
.ui-search-select__chevron svg {
  width: 14px;
  height: 9px;
  display: block;
  overflow: visible;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.7;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.ui-search-select.open .ui-search-select__chevron { transform: rotate(180deg); }
.ui-search-select__menu { position: absolute; z-index: 120; top: calc(100% + 5px); left: 0; width: max(100%, 220px); max-width: min(360px, calc(100vw - 24px)); overflow: hidden; border: 1px solid #dfe3e8; border-radius: var(--irlix-control-radius, 10px); background: #fff; box-shadow: 0 8px 24px rgba(28, 35, 45, .14); }
.ui-search-select__search-wrap { border-bottom: 1px solid #e4e7eb; }
.ui-search-select__search { width: 100%; height: var(--irlix-control-height, 32px); min-height: var(--irlix-control-height, 32px) !important; padding: 0 12px !important; border: 0 !important; border-radius: 0 !important; outline: 0; box-shadow: none !important; background: #fff; color: var(--irlix-control-text, #4f5967); font: inherit; }
.ui-search-select__options { max-height: 260px; overflow-y: auto; padding: 3px 0; }
.ui-search-select__group { padding: 9px 12px 5px; color: #7a828d; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .025em; background: #fff; }
.ui-search-select__option { width: 100%; min-height: 34px; display: flex; align-items: center; gap: 10px; padding: 6px 12px; border: 0; border-radius: 0; background: #fff; color: #454d59; font: inherit; font-weight: 400; text-align: left; cursor: pointer; }
.ui-search-select__option:hover { filter: none; background: #f7f9f9; }
.ui-search-select__option.selected { color: #25313b; background: #eef9f6; }
.ui-search-select__option-label { display: block; min-width: 0; line-height: 1.15; white-space: normal; overflow-wrap: break-word; }
.ui-search-select__marker { flex: 0 0 20px; width: 20px; height: 20px; display: grid; place-items: center; border: 2px solid #d9dde1; border-radius: 50%; background: #fff; }
.ui-search-select__marker.multiple { border-radius: 5px; }
.ui-search-select__marker i { width: 10px; height: 10px; display: block; border-radius: inherit; background: var(--irlix-color-primary); }
.ui-search-select__empty { padding: 13px 12px; color: #8b939d; font-size: 12px; text-align: center; }
</style>
