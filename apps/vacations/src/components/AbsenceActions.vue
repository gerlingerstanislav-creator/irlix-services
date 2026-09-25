<script setup>
import { ref } from 'vue';
import { actionLabels } from '../constants';

const props = defineProps({ actions: { type: Array, default: () => [] } });
const emit = defineEmits(['action']);
const open = ref(false);

const choose = (action) => {
  open.value = false;
  emit('action', action);
};
</script>

<template>
  <div class="context-menu" @mouseleave="open = false">
    <button class="context-trigger" type="button" aria-label="Действия" title="Действия" @click.stop="open = !open">⋮</button>
    <div v-if="open" class="context-popover" @click.stop>
      <button v-for="action in props.actions" :key="action" type="button" :class="{ danger: action === 'return_to_planned' }" @click="choose(action)">
        {{ actionLabels[action] || action }}
      </button>
    </div>
  </div>
</template>
