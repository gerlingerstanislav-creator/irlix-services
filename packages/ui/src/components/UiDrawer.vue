<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
  inactive: { type: Boolean, default: false },
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  width: { type: String, default: '520px' },
  minWidth: { type: Number, default: null },
  resizable: { type: Boolean, default: true },
  zIndex: { type: Number, default: 1000 },
});
const emit = defineEmits(['close']);

const drawer = ref(null);
const resizedWidth = ref(null);
let stopResize = null;

const minimumWidth = computed(() => {
  if (Number.isFinite(props.minWidth) && props.minWidth > 0) return props.minWidth;
  const px = String(props.width || '').match(/^([0-9.]+)px$/);
  return px ? Math.max(320, Math.round(Number(px[1]) * 0.7)) : 360;
});

function startResize(event) {
  if (props.inactive || !props.resizable || !drawer.value || window.innerWidth <= 720) return;
  event.preventDefault();
  const startX = event.clientX;
  const startWidth = drawer.value.getBoundingClientRect().width;
  const pointerId = event.pointerId;
  event.currentTarget?.setPointerCapture?.(pointerId);
  document.body.classList.add('irlix-drawer-resizing');

  const move = (moveEvent) => {
    const maximum = Math.floor(window.innerWidth * 0.9);
    const minimum = Math.min(minimumWidth.value, maximum);
    const nextWidth = startWidth + (startX - moveEvent.clientX);
    resizedWidth.value = Math.max(minimum, Math.min(maximum, nextWidth));
  };
  const end = () => {
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', end);
    window.removeEventListener('pointercancel', end);
    document.body.classList.remove('irlix-drawer-resizing');
    stopResize = null;
  };

  stopResize = end;
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', end);
  window.addEventListener('pointercancel', end);
}

watch(() => props.open, (open) => {
  if (!open) {
    stopResize?.();
    resizedWidth.value = null;
  }
});
onBeforeUnmount(() => stopResize?.());
</script>

<template>
  <Teleport to="body">
    <div v-if="props.open" class="irlix-drawer-layer" :style="{ zIndex: props.zIndex }">
      <button class="irlix-drawer-backdrop" type="button" :disabled="props.inactive" @wheel.prevent @touchmove.prevent aria-label="Закрыть" @click="emit('close')" />
      <aside
        ref="drawer"
        class="irlix-drawer"
        :inert="props.inactive || undefined"
        :class="{ 'irlix-drawer--resizable': props.resizable }"
        :style="{ width: resizedWidth ? `${resizedWidth}px` : props.width, minWidth: `${minimumWidth}px` }"
      >
        <div v-if="props.resizable" class="irlix-drawer-resize-handle" role="separator" aria-orientation="vertical" aria-label="Изменить ширину окна" @pointerdown="startResize" />
        <header class="irlix-drawer-header">
          <slot name="title"><strong>{{ props.title }}</strong></slot>
          <div class="irlix-drawer-actions">
            <slot name="actions" />
            <button type="button" class="irlix-icon-button" aria-label="Закрыть" @click="emit('close')">×</button>
          </div>
        </header>
        <div class="irlix-drawer-body" :style="props.inactive ? {overflow: 'hidden'} : undefined"><slot /></div>
      </aside>
    </div>
  </Teleport>
</template>
