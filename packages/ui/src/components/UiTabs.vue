<script setup>
const props = defineProps({
  modelValue: { type: String, default: '' },
  items: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);
</script>

<template>
  <div class="irlix-tabs" role="tablist">
    <button
      v-for="item in props.items"
      :key="item.value"
      type="button"
      class="irlix-tab"
      :class="{ active: item.value === props.modelValue }"
      role="tab"
      :aria-selected="item.value === props.modelValue"
      @click="emit('update:modelValue', item.value)"
    >
      <span v-if="item.icon" class="irlix-tab-icon">{{ item.icon }}</span>
      {{ item.label }}
      <span v-if="item.count !== undefined" class="irlix-tab-count">{{ item.count }}</span>
    </button>
  </div>
</template>
