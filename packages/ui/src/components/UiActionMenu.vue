<script setup>
import { nextTick, ref, useId, watch } from 'vue';
import { useAnchoredPopover } from '../useAnchoredPopover';

const props = defineProps({
  items: { type: Array, default: () => [] },
  ariaLabel: { type: String, default: 'Действия' },
});
const emit = defineEmits(['select']);
const trigger = ref(null);
const menu = ref(null);
const id = useId();
const { open, style, close } = useAnchoredPopover(trigger, menu, { width: 272, align: 'right' });
const focusOption = (index) => {
  const buttons = [...(menu.value?.querySelectorAll('button:not(:disabled)') ?? [])];
  if (!buttons.length) return;
  const next = buttons[(index + buttons.length) % buttons.length];
  next?.focus({ preventScroll: true });
  next?.scrollIntoView({ block: 'nearest' });
};
const show = async () => {
  if (!props.items.length) return;
  open.value = true;
  await nextTick();
  focusOption(0);
};
const choose = (item) => {
  if (item.disabled) return;
  close(true);
  emit('select', item.id);
};
const navigate = (event) => {
  const buttons = [...(menu.value?.querySelectorAll('button:not(:disabled)') ?? [])];
  const current = buttons.indexOf(document.activeElement);
  if (event.key === 'ArrowDown') { event.preventDefault(); focusOption(current + 1); }
  if (event.key === 'ArrowUp') { event.preventDefault(); focusOption(current < 0 ? buttons.length - 1 : current - 1); }
  if (event.key === 'Home') { event.preventDefault(); focusOption(0); }
  if (event.key === 'End') { event.preventDefault(); focusOption(buttons.length - 1); }
  if (event.key === 'Tab') close();
};
watch(() => props.items.length, count => { if (!count) close(); });
</script>

<template>
  <button ref="trigger" type="button" class="ui-action-menu__trigger" :aria-label="ariaLabel" aria-haspopup="menu" :aria-expanded="open" :aria-controls="id + '-menu'" @click="open ? close() : show()" @keydown.down.prevent="show" @keydown.up.prevent="show">
    <span aria-hidden="true">⋮</span>
  </button>
  <Teleport to="body">
    <div v-if="open" :id="id + '-menu'" ref="menu" class="ui-action-menu__panel irlix-ui" :style="style" role="menu" :aria-label="ariaLabel" @keydown="navigate">
      <button v-for="item in items" :key="item.id" type="button" role="menuitem" :class="{ 'ui-action-menu__danger': item.danger }" :disabled="item.disabled" @click="choose(item)">{{ item.label }}</button>
    </div>
  </Teleport>
</template>

<style>
.ui-action-menu__trigger { display:inline-grid; place-items:center; width:32px; height:32px; padding:0; border:0; border-radius:var(--irlix-radius-sm); background:transparent; color:var(--irlix-color-text); cursor:pointer; }
.ui-action-menu__trigger > span { font-size:24px; line-height:1; }
.ui-action-menu__trigger:hover, .ui-action-menu__trigger[aria-expanded="true"] { background:var(--irlix-color-surface-muted); }
.ui-action-menu__trigger:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:2px; }
.ui-action-menu__panel { z-index:var(--irlix-popover-z-index); overflow:auto; padding:5px; border:1px solid var(--irlix-color-border); border-radius:var(--irlix-control-radius); background:var(--irlix-color-surface); box-shadow:var(--irlix-popover-shadow); }
.ui-action-menu__panel > button { display:block; width:100%; padding:9px 10px; border:0; border-radius:var(--irlix-radius-sm); background:transparent; color:var(--irlix-color-text); font-size:var(--irlix-font-size-caption); text-align:left; line-height:1.3; cursor:pointer; }
.ui-action-menu__panel > button:hover { background:var(--irlix-color-surface-muted); }
.ui-action-menu__panel > button:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:-2px; }
.ui-action-menu__panel > button.ui-action-menu__danger { color:var(--irlix-status-danger-text); }
.ui-action-menu__panel > button:disabled { opacity:.5; cursor:not-allowed; }
</style>
