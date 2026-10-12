<script setup>
import UiIcon from './UiIcon.vue';
import UiStatusCheck from './UiStatusCheck.vue';
const props = defineProps({
  modelValue: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  variant: { type: String, default: 'default' },
});
const emit = defineEmits(['update:modelValue']);
const valueOf = (item) => typeof item === 'string' ? item : item.value;
const labelOf = (item) => typeof item === 'string' ? item : item.label;
</script>

<template>
  <div class="irlix-tabs" :class="{ 'irlix-tabs--card': variant === 'card' }" role="tablist">
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
      <UiIcon v-if="typeof item !== 'string' && item.iconName" :name="item.iconName" class="irlix-tab-icon" />
      <span v-else-if="typeof item !== 'string' && item.icon" class="irlix-tab-icon">{{ item.icon }}</span>
      {{ labelOf(item) }}
      <span v-if="typeof item !== 'string' && item.count !== undefined" class="irlix-tab-count">{{ item.count }}</span>
      <UiStatusCheck v-if="typeof item !== 'string' && item.complete" />
    </button>
  </div>
</template>
