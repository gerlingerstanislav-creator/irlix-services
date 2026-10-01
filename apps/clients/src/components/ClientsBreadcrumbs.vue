<script setup>
import { UiSearchSelect } from '@irlix/ui';

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
    <UiSearchSelect class="breadcrumb-mode" :model-value="requestMode" :options="requestModes" :clearable="false" aria-label="Вид страницы запросов" search-placeholder="Выберите вид" @update:model-value="emit('update:requestMode', $event)" />
  </template>
  <template v-else-if="view === 'reports'">
    <span class="irlix-breadcrumbs__separator" aria-hidden="true">—</span>
    <UiSearchSelect class="breadcrumb-mode" :model-value="reportMode" :options="reportModes" :clearable="false" aria-label="Вид отчётных периодов" search-placeholder="Выберите вид" @update:model-value="emit('update:reportMode', $event)" />
  </template>
</template>
