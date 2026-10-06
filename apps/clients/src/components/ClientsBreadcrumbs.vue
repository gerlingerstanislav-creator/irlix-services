<script setup>
import { UiViewSelect } from '@irlix/ui';

const props = defineProps({
  view: { type: String, required: true },
  requestMode: { type: String, default: 'tree' },
  reportMode: { type: String, default: 'kanban' },
});

const emit = defineEmits(['update:requestMode', 'update:reportMode']);

const requestModes = [
  { value: 'tree', label: 'Общий экран' },
  { value: 'list', label: 'Список' },
];

const reportModes = [
  { value: 'kanban', label: 'Канбан' },
  { value: 'gantt', label: 'Гант' },
];
</script>

<template>
  <template v-if="view === 'requests'">
    <span class="irlix-breadcrumbs__separator" aria-hidden="true">—</span>
    <UiViewSelect :model-value="requestMode" :options="requestModes" aria-label="Вид страницы запросов" @update:model-value="emit('update:requestMode', $event)" />
  </template>
  <template v-else-if="view === 'reports'">
    <span class="irlix-breadcrumbs__separator" aria-hidden="true">—</span>
    <UiViewSelect :model-value="reportMode" :options="reportModes" aria-label="Вид отчётных периодов" @update:model-value="emit('update:reportMode', $event)" />
  </template>
</template>
