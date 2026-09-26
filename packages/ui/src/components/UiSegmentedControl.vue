<script setup>
const props = defineProps({
  modelValue: { type: String, default: '' },
  items: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);
</script>

<template>
  <div class="irlix-segmented" role="tablist">
    <button
      v-for="item in props.items"
      :key="item.value"
      type="button"
      class="irlix-segmented-item"
      :class="{ active: item.value === props.modelValue }"
      :aria-selected="item.value === props.modelValue"
      @click="emit('update:modelValue', item.value)"
    >{{ item.label }}</button>
  </div>
</template>
