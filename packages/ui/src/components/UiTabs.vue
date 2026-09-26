<script setup>
const props = defineProps({
  modelValue: { type: String, default: '' },
  items: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);
const valueOf = (item) => typeof item === 'string' ? item : item.value;
const labelOf = (item) => typeof item === 'string' ? item : item.label;
</script>

<template>
  <div class="irlix-tabs" role="tablist">
    <button
      v-for="item in props.items"
      :key="valueOf(item)"
      type="button"
      class="irlix-tab"
      :class="{ active: valueOf(item) === props.modelValue }"
      role="tab"
      :aria-selected="valueOf(item) === props.modelValue"
      @click="emit('update:modelValue', valueOf(item))"
    >
      <span v-if="typeof item !== 'string' && item.icon" class="irlix-tab-icon">{{ item.icon }}</span>
      {{ labelOf(item) }}
      <span v-if="typeof item !== 'string' && item.count !== undefined" class="irlix-tab-count">{{ item.count }}</span>
    </button>
  </div>
</template>
