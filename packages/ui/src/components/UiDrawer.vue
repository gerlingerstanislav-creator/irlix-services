<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  width: { type: String, default: '520px' },
  minWidth: { type: Number, default: 360 },
  resizable: { type: Boolean, default: true },
});
const emit = defineEmits(['close']);

const drawer = ref(null);
const resizedWidth = ref(null);
let stopResize = null;

function startResize(event) {
  if (!props.resizable || !drawer.value || window.innerWidth <= 720) return;
  event.preventDefault();
  const startX = event.clientX;
  const startWidth = drawer.value.getBoundingClientRect().width;
  const pointerId = event.pointerId;
  event.currentTarget?.setPointerCapture?.(pointerId);
  document.body.classList.add('irlix-drawer-resizing');

  const move = (moveEvent) => {
    const maximum = Math.floor(window.innerWidth * 0.9);
    const minimum = Math.min(props.minWidth, maximum);
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
    <div v-if="props.open" class="irlix-drawer-layer">
      <button class="irlix-drawer-backdrop" type="button" aria-label="Закрыть" @click="emit('close')" />
      <aside
        ref="drawer"
        class="irlix-drawer"
        :class="{ 'irlix-drawer--resizable': props.resizable }"
        :style="{ width: resizedWidth ? `${resizedWidth}px` : props.width, minWidth: `${props.minWidth}px` }"
      >
        <div v-if="props.resizable" class="irlix-drawer-resize-handle" role="separator" aria-orientation="vertical" aria-label="Изменить ширину окна" @pointerdown="startResize" />
        <header class="irlix-drawer-header">
          <strong>{{ props.title }}</strong>
          <div class="irlix-drawer-actions">
            <slot name="actions" />
            <button type="button" class="irlix-icon-button" aria-label="Закрыть" @click="emit('close')">×</button>
          </div>
        </header>
        <div class="irlix-drawer-body"><slot /></div>
      </aside>
    </div>
  </Teleport>
</template>
