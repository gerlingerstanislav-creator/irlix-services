<script setup>
import { UiSearchSelect } from '@irlix/ui';

const props = defineProps({
  view: { type: String, required: true },
  title: { type: String, required: true },
  loading: { type: Boolean, default: false },
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
  <div class="topbar-context">
    <nav class="clients-breadcrumbs" aria-label="Навигация раздела">
      <span class="clients-breadcrumbs__service">Клиентский сервис</span>
      <span class="clients-breadcrumbs__separator" aria-hidden="true">—</span>
      <span class="clients-breadcrumbs__section">{{ title }}</span>

      <template v-if="view === 'requests'">
        <span class="clients-breadcrumbs__separator" aria-hidden="true">—</span>
        <UiSearchSelect
          class="breadcrumb-mode"
          :model-value="requestMode"
          :options="requestModes"
          :clearable="false"
          aria-label="Вид страницы запросов"
          search-placeholder="Выберите вид"
          @update:model-value="emit('update:requestMode', $event)"
        />
      </template>

      <template v-else-if="view === 'reports'">
        <span class="clients-breadcrumbs__separator" aria-hidden="true">—</span>
        <UiSearchSelect
          class="breadcrumb-mode"
          :model-value="reportMode"
          :options="reportModes"
          :clearable="false"
          aria-label="Вид отчётных периодов"
          search-placeholder="Выберите вид"
          @update:model-value="emit('update:reportMode', $event)"
        />
      </template>
    </nav>
    <span v-if="loading" class="loading-inline">Обновление…</span>
  </div>
</template>
