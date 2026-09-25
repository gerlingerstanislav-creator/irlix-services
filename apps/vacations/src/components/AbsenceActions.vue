<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { actionLabels } from '../constants';

const props = defineProps({ actions: { type: Array, default: () => [] } });
const emit = defineEmits(['action']);
const open = ref(false);
const trigger = ref(null);
const popoverStyle = ref({ top: '0px', left: '0px' });

const positionPopover = () => {
  if (!trigger.value || !open.value) return;
  const rect = trigger.value.getBoundingClientRect();
  const width = 230;
  const estimatedHeight = Math.max(46, props.actions.length * 37 + 10);
  const gap = 4;
  const viewportPadding = 8;
  const left = Math.min(
    Math.max(viewportPadding, rect.right - width),
    window.innerWidth - width - viewportPadding,
  );
  const spaceBelow = window.innerHeight - rect.bottom;
  const top = spaceBelow >= estimatedHeight + gap
    ? rect.bottom + gap
    : Math.max(viewportPadding, rect.top - estimatedHeight - gap);
  popoverStyle.value = { top: `${top}px`, left: `${left}px`, width: `${width}px` };
};

const toggle = async () => {
  open.value = !open.value;
  if (open.value) {
    await nextTick();
    positionPopover();
  }
};

const choose = (action) => {
  open.value = false;
  emit('action', action);
};

const handlePointerDown = (event) => {
  if (!open.value) return;
  if (trigger.value?.contains(event.target) || event.target.closest?.('.context-popover')) return;
  open.value = false;
};
const handleViewportChange = () => {
  if (open.value) positionPopover();
};

onMounted(() => {
  document.addEventListener('pointerdown', handlePointerDown);
  window.addEventListener('resize', handleViewportChange);
  window.addEventListener('scroll', handleViewportChange, true);
});
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', handlePointerDown);
  window.removeEventListener('resize', handleViewportChange);
  window.removeEventListener('scroll', handleViewportChange, true);
});
</script>

<template>
  <div class="context-menu">
    <button ref="trigger" class="context-trigger" type="button" aria-label="Действия" title="Действия" @click.stop="toggle">⋮</button>
    <Teleport to="body">
      <div v-if="open" class="context-popover context-popover-fixed" :style="popoverStyle" @click.stop>
        <button v-for="action in props.actions" :key="action" type="button" :class="{ danger: action === 'return_to_planned' }" @click="choose(action)">
          {{ actionLabels[action] || action }}
        </button>
      </div>
    </Teleport>
  </div>
</template>
