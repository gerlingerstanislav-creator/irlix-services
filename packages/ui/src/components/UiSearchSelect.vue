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
  teleport: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change', 'open', 'close']);
const root = ref(null);
const menu = ref(null);
const searchInput = ref(null);
const open = ref(false);
const query = ref('');
const menuStyle = ref({});

const normalized = computed(() => props.options.map((option) => {
  if (option !== null && typeof option === 'object') {
    return {
      value: option[props.valueKey],
      label: String(option[props.labelKey] ?? option[props.valueKey] ?? ''),
      disabled: Boolean(option.disabled),
      kind: option.kind === 'group' ? 'group' : 'option',
      depth: Math.max(0, Number(option.depth || 0)),
      meta: String(option.meta || ''),
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

  const matched = new Set();
  const ancestors = [];
  for (const option of normalized.value) {
    if (option.kind === 'group') {
      while (ancestors.length && ancestors.at(-1).depth >= option.depth) ancestors.pop();
      ancestors.push(option);
      continue;
    }
    if (option.label.toLocaleLowerCase('ru').includes(needle) || ancestors.some(group => group.label.toLocaleLowerCase('ru').includes(needle))) {
      matched.add(option);
      ancestors.forEach(group => matched.add(group));
    }
  }
  return normalized.value.filter(option => matched.has(option));
});

const updateMenuPosition = () => {
  if (!props.teleport || !open.value || !root.value) return;
  const rect = root.value.getBoundingClientRect();
  const viewportPadding = 12;
  const menuWidth = Math.min(Math.max(rect.width, 220), Math.max(220, window.innerWidth - viewportPadding * 2));
  const left = Math.min(Math.max(rect.left, viewportPadding), Math.max(viewportPadding, window.innerWidth - menuWidth - viewportPadding));
  const below = Math.max(0, window.innerHeight - rect.bottom - viewportPadding);
  const above = Math.max(0, rect.top - viewportPadding);
  const openUp = below < 180 && above > below;
  const available = Math.max(120, openUp ? above : below);
  const optionsMaxHeight = Math.max(80, Math.min(260, available - 45));

  menuStyle.value = {
    position: 'fixed',
    left: `${left}px`,
    width: `${menuWidth}px`,
    maxWidth: `${menuWidth}px`,
    top: openUp ? 'auto' : `${rect.bottom + 5}px`,
    bottom: openUp ? `${window.innerHeight - rect.top + 5}px` : 'auto',
    '--ui-search-select-options-max-height': `${optionsMaxHeight}px`,
  };
};

const setOpen = async (value) => {
  if (props.disabled) return;
  open.value = value;
  if (value) {
    query.value = '';
    emit('open');
    await nextTick();
    updateMenuPosition();
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
  if (!open.value) return;
  if (root.value?.contains(event.target) || menu.value?.contains(event.target)) return;
  setOpen(false);
};
const onViewportChange = () => updateMenuPosition();
const onKeydown = (event) => {
  if (event.key === 'Escape' && open.value) {
    event.preventDefault();
    setOpen(false);
  }
};

watch(() => props.disabled, (value) => { if (value && open.value) setOpen(false); });
document.addEventListener('pointerdown', onDocumentPointerDown);
document.addEventListener('keydown', onKeydown);
window.addEventListener('resize', onViewportChange);
window.addEventListener('scroll', onViewportChange, true);
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown);
  document.removeEventListener('keydown', onKeydown);
  window.removeEventListener('resize', onViewportChange);
  window.removeEventListener('scroll', onViewportChange, true);
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
    <Teleport to="body" :disabled="!teleport">
      <div v-if="open" ref="menu" class="ui-search-select__menu" :class="{ 'ui-search-select__menu--teleported': teleport }" :style="teleport ? menuStyle : undefined">
        <div class="ui-search-select__search-wrap"><input ref="searchInput" v-model="query" class="ui-search-select__search" type="search" :placeholder="searchPlaceholder" @click.stop /></div>
        <div class="ui-search-select__options" role="listbox" :aria-multiselectable="multiple || undefined">
          <template v-for="option in filtered" :key="`${option.kind}-${String(option.value)}-${option.label}`">
            <div v-if="option.kind === 'group'" class="ui-search-select__group" :style="{ paddingLeft: `${12 + option.depth * 18}px` }">{{ option.label }}</div>
            <button v-else type="button" class="ui-search-select__option" :class="{ selected: isSelected(option.value) }" :style="{ paddingLeft: `${12 + option.depth * 18}px` }" :disabled="option.disabled" role="option" :aria-selected="isSelected(option.value)" @click="select(option)">
              <span class="ui-search-select__marker" :class="{ multiple }" aria-hidden="true"><i v-if="isSelected(option.value)"></i></span>
              <span class="ui-search-select__option-label">{{ option.label }}</span><span v-if="option.meta" class="ui-search-select__option-meta">{{option.meta}}</span>
            </button>
          </template>
          <div v-if="!filtered.length" class="ui-search-select__empty">{{ emptyText }}</div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.ui-search-select { position:relative; width:100%; min-width:0; font:inherit; }
.ui-search-select__trigger {
  position:relative;
  width:100%;
  height:var(--irlix-control-height, 32px);
  min-height:var(--irlix-control-height, 32px);
  display:flex;
  align-items:center;
  gap:7px;
  padding:0 50px 0 var(--irlix-control-padding-x, 12px);
  border:1px solid var(--irlix-control-border, var(--irlix-color-border));
  border-radius:var(--irlix-control-radius, 10px);
  background:var(--irlix-color-surface);
  color:var(--irlix-control-placeholder, var(--irlix-control-placeholder));
  font:inherit;
  font-weight:400;
  text-align:left;
  cursor:pointer;
  box-shadow:none;
}
.ui-search-select__trigger:hover { filter:none; border-color:var(--irlix-color-control-hover-border); background:var(--irlix-color-surface); }
.ui-search-select.open .ui-search-select__trigger { border-color:var(--irlix-color-primary); box-shadow:0 0 0 2px color-mix(in srgb, var(--irlix-color-shadow-base) 10%, transparent); }
.ui-search-select.disabled { opacity:.6; }
.ui-search-select__count { flex:0 0 auto; min-width:20px; height:20px; padding:0 5px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:var(--irlix-color-primary-soft, var(--irlix-color-surface-muted)); color:var(--irlix-color-primary-text, var(--irlix-color-primary-text)); font-size:var(--irlix-font-size-caption); font-weight:700; line-height:1; }
.ui-search-select__label { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ui-search-select__option-label{min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;text-align:left;white-space:nowrap}.ui-search-select__option-meta{margin-left:auto;color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption);font-weight:400}
.ui-search-select.filled .ui-search-select__label { color:var(--irlix-control-text, var(--irlix-control-text)); }
.ui-search-select__actions {
  position:absolute;
  right:10px;
  top:50%;
  width:38px;
  height:18px;
  display:inline-flex;
  align-items:center;
  justify-content:flex-end;
  gap:4px;
  transform:translateY(-50%);
  color:var(--irlix-color-text-muted);
}
.ui-search-select__clear { flex:0 0 16px; width:16px; height:16px; display:grid; place-items:center; border-radius:4px; font-size:var(--irlix-font-size-body); line-height:1; }
.ui-search-select__clear:hover { background:var(--irlix-color-surface-muted); color:var(--irlix-color-text-muted); }
.ui-search-select__chevron {
  flex:0 0 18px;
  width:18px;
  height:18px;
  display:grid;
  place-items:center;
  transform:rotate(0deg);
  transform-origin:50% 50%;
  transition:transform .12s ease;
}
.ui-search-select__chevron svg {
  width:14px;
  height:9px;
  display:block;
  overflow:visible;
  fill:none;
  stroke:currentColor;
  stroke-width:1.7;
  stroke-linecap:round;
  stroke-linejoin:round;
}
.ui-search-select.open .ui-search-select__chevron { transform:rotate(180deg); }
.ui-search-select__menu { position:absolute; z-index:120; top:calc(100% + 5px); left:0; width:max(100%, 220px); max-width:min(360px, calc(100vw - 24px)); overflow:hidden; border:1px solid var(--irlix-color-border); border-radius:var(--irlix-control-radius, 10px); background:var(--irlix-color-surface); box-shadow:0 8px 24px color-mix(in srgb, var(--irlix-color-shadow-base) 14%, transparent); }
.ui-search-select__menu--teleported { z-index:1000; }
.ui-search-select__search-wrap { border-bottom:1px solid var(--irlix-color-border); }
.ui-search-select__search { width:100%; height:var(--irlix-control-height, 32px); min-height:var(--irlix-control-height, 32px) !important; padding:0 12px !important; border:0 !important; border-radius:0 !important; outline:0; box-shadow:none !important; background:var(--irlix-color-surface); color:var(--irlix-control-text, var(--irlix-control-text)); font:inherit; }
.ui-search-select__options { max-height:var(--ui-search-select-options-max-height, 260px); overflow-y:auto; padding:3px 0; }
.ui-search-select__group { padding:9px 12px 5px; color:var(--irlix-color-text-muted); font-size:var(--irlix-font-size-caption); font-weight:700; text-transform:uppercase; letter-spacing:.025em; background:var(--irlix-color-surface); }
.ui-search-select__option { width:100%; min-height:34px; display:flex; align-items:center; gap:10px; padding:6px 12px; border:0; border-radius:0; background:var(--irlix-color-surface); color:var(--irlix-color-text); font:inherit; font-weight:400; text-align:left; cursor:pointer; }
.ui-search-select__option:hover { filter:none; background:var(--irlix-color-surface-muted); }
.ui-search-select__option.selected { color:var(--irlix-color-text); background:var(--irlix-color-surface-muted); }
.ui-search-select__option-label { display:block; min-width:0; line-height:1.15; white-space:normal; overflow-wrap:break-word; }
.ui-search-select__marker { flex:0 0 20px; width:20px; height:20px; display:grid; place-items:center; border:2px solid var(--irlix-color-border); border-radius:50%; background:var(--irlix-color-surface); }
.ui-search-select__marker.multiple { border-radius:5px; }
.ui-search-select__marker i { width:10px; height:10px; display:block; border-radius:inherit; background:var(--irlix-color-primary); }
.ui-search-select__empty { padding:13px 12px; color:var(--irlix-color-text-muted); font-size:var(--irlix-font-size-caption); text-align:center; }
</style>

