<script setup>
const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  width: { type: String, default: '520px' },
});
const emit = defineEmits(['close']);
</script>

<template>
  <Teleport to="body">
    <div v-if="props.open" class="irlix-drawer-layer">
      <button class="irlix-drawer-backdrop" type="button" aria-label="Закрыть" @click="emit('close')" />
      <aside class="irlix-drawer" :style="{ width: props.width }">
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
