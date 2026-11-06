<script setup>
import { computed } from 'vue';
import UiSearchSelect from './UiSearchSelect.vue';
import { departmentTreeOptions } from '../departmentTree.js';
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Подразделения' },
  ariaLabel: { type: String, default: 'Фильтр подразделений' },
});
const emit = defineEmits(['update:modelValue']);
const options = computed(() => departmentTreeOptions(props.departments));
</script>
<template>
  <UiSearchSelect :model-value="modelValue" :options="options" multiple teleport compact
    :placeholder="placeholder" search-placeholder="Поиск в оргструктуре" :aria-label="ariaLabel"
    @update:model-value="emit('update:modelValue', $event)" />
</template>
