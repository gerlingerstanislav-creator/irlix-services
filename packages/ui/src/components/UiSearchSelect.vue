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
    };
  }
  return { value: option, label: String(option ?? ''), disabled: false };
}));

const selectedValues = computed(() => props.multiple
  ? (Array.isArray(props.modelValue) ? props.modelValue : [])
  : [props.modelValue]);

const equals = (a, b) => String(a ?? '') === String(b ?? '');
const isSelected = (value) => selectedValues.value.some((selected) => equals(selected, value));
const selectedOptions = computed(() => normalized.value.filter((option) => isSelected(option.value)));
const displayLabel = computed(() => {
  if (!selectedOptions.value.length) return props.placeholder;
  if (!props.multiple) return selectedOptions.value[0].label;
  if (selectedOptions.value.length === 1) return selectedOptions.value[0].label;
  return `${props.placeholder} · ${selectedOptions.value.length}`;
});
const hasValue = computed(() => props.multiple
  ? Array.isArray(props.modelValue) && props.modelValue.length > 0
  : props.modelValue !== '' && props.modelValue !== null && props.modelValue !== undefined);
const filtered = computed(() => {
  const needle = query.value.trim().toLocaleLowerCase('ru');
  return needle ? normalized.value.filter((option) => option.label.toLocaleLowerCase('ru').includes(needle)) : normalized.value;
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
  if (option.disabled) return;
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
      <span class="ui-search-select__label">{{ displayLabel }}</span>
      <span class="ui-search-select__actions">
        <span v-if="clearable && hasValue" class="ui-search-select__clear" role="button" aria-label="Сбросить фильтр" @click="clear">×</span>
        <span class="ui-search-select__chevron" aria-hidden="true">⌄</span>
      </span>
    </button>
    <div v-if="open" class="ui-search-select__menu">
      <div class="ui-search-select__search-wrap"><input ref="searchInput" v-model="query" class="ui-search-select__search" type="search" :placeholder="searchPlaceholder" @click.stop /></div>
      <div class="ui-search-select__options" role="listbox" :aria-multiselectable="multiple || undefined">
        <button v-for="option in filtered" :key="String(option.value)" type="button" class="ui-search-select__option" :class="{ selected: isSelected(option.value) }" :disabled="option.disabled" role="option" :aria-selected="isSelected(option.value)" @click="select(option)">
          <span class="ui-search-select__marker" :class="{ multiple }" aria-hidden="true"><i v-if="isSelected(option.value)"></i></span>
          <span>{{ option.label }}</span>
        </button>
        <div v-if="!filtered.length" class="ui-search-select__empty">{{ emptyText }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ui-search-select { position: relative; width: 100%; min-width: 0; font: inherit; }
.ui-search-select__trigger { position: relative; width: 100%; min-height: var(--irlix-control-height, 34px); display: flex; align-items: center; gap: 10px; padding: 6px 57px 6px 11px; border: 1px solid var(--irlix-color-border, #dfe4ec); border-radius: 7px; background: #fff; color: #4f5967; font: inherit; font-weight: 400; text-align: left; cursor: pointer; box-shadow: none; }
.ui-search-select__trigger:hover { filter: none; border-color: #cfd6de; background: #fff; }
.ui-search-select.open .ui-search-select__trigger { border-color: var(--irlix-color-primary); box-shadow: 0 0 0 2px rgba(18, 184, 144, .1); }
.ui-search-select.disabled { opacity: .6; }
.ui-search-select__label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ui-search-select.filled .ui-search-select__label { color: var(--irlix-color-text, #1f2937); }
.ui-search-select__actions { position: absolute; right: 9px; top: 50%; width: 42px; height: 20px; display: inline-flex; align-items: center; justify-content: flex-end; gap: 6px; transform: translateY(-50%); color: #8a939d; }
.ui-search-select__clear { flex: 0 0 18px; width: 18px; height: 18px; display: grid; place-items: center; border-radius: 4px; font-size: 16px; line-height: 1; }
.ui-search-select__clear:hover { background: #f0f2f4; color: #5e6670; }
.ui-search-select__chevron { flex: 0 0 18px; width: 18px; height: 18px; display: grid; place-items: center; line-height: 1; transform: rotate(0deg); transform-origin: 50% 50%; font-size: 16px; transition: transform .12s ease; }
.ui-search-select.open .ui-search-select__chevron { transform: rotate(180deg); }
.ui-search-select__menu { position: absolute; z-index: 120; top: calc(100% + 5px); left: 0; width: max(100%, 220px); max-width: min(360px, calc(100vw - 24px)); overflow: hidden; border: 1px solid #dfe3e8; border-radius: 7px; background: #fff; box-shadow: 0 8px 24px rgba(28, 35, 45, .14); }
.ui-search-select__search-wrap { border-bottom: 1px solid #e4e7eb; }
.ui-search-select__search { width: 100%; height: 35px; padding: 7px 10px; border: 0 !important; border-radius: 0 !important; outline: 0; box-shadow: none !important; background: #fff; color: var(--irlix-color-text, #1f2937); font: inherit; }
.ui-search-select__options { max-height: 260px; overflow-y: auto; padding: 3px 0; }
.ui-search-select__option { width: 100%; min-height: 36px; display: flex; align-items: center; gap: 10px; padding: 7px 12px; border: 0; border-radius: 0; background: #fff; color: #454d59; font: inherit; font-weight: 400; text-align: left; cursor: pointer; }
.ui-search-select__option:hover { filter: none; background: #f7f9f9; }
.ui-search-select__option.selected { color: #25313b; }
.ui-search-select__marker { flex: 0 0 20px; width: 20px; height: 20px; display: grid; place-items: center; border: 2px solid #d9dde1; border-radius: 50%; background: #fff; }
.ui-search-select__marker.multiple { border-radius: 5px; }
.ui-search-select__marker i { width: 10px; height: 10px; display: block; border-radius: inherit; background: var(--irlix-color-primary); }
.ui-search-select__empty { padding: 13px 12px; color: #8b939d; font-size: 12px; text-align: center; }
</style>
