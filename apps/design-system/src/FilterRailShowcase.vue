<script setup>
import { computed, ref } from 'vue';
import { UiButton, UiFilterRail, UiSearchSelect } from '@irlix/ui';

const visible = ref(false);
const query = ref('');
const department = ref('');
const status = ref('');
const grouping = ref('department');
const departments = [
  { value: 'backend', label: 'Backend' },
  { value: 'frontend', label: 'Frontend' },
  { value: 'qa', label: 'QA' },
];
const statuses = [
  { value: 'active', label: 'Активен' },
  { value: 'paused', label: 'Приостановлен' },
];
const groupings = [
  { value: 'department', label: 'По подразделению' },
  { value: 'status', label: 'По статусу' },
  { value: 'none', label: 'Без группировки' },
];
const items = computed(() => [
  { id: 'search', label: 'Поиск', icon: 'search', active: Boolean(query.value.trim()), valueLabel: query.value.trim() },
  { id: 'department', label: 'Подразделение', icon: 'building', active: Boolean(department.value), valueLabel: departments.find((item) => item.value === department.value)?.label || '' },
  { id: 'status', label: 'Статус', icon: 'status', active: Boolean(status.value), valueLabel: statuses.find((item) => item.value === status.value)?.label || '' },
]);
const groupingItems = computed(() => [
  { id: 'primary', label: 'Группировка', icon: 'list', active: grouping.value !== 'none', valueLabel: groupings.find((item) => item.value === grouping.value)?.label || '' },
]);

const reset = () => {
  query.value = '';
  department.value = '';
  status.value = '';
  grouping.value = 'department';
};
</script>

<template>
  <div class="filter-rail-showcase">
    <UiButton variant="secondary" compact @click="visible = !visible">
      {{ visible ? 'Скрыть UiFilterRail' : 'Показать UiFilterRail' }}
    </UiButton>
    <UiFilterRail v-if="visible" :items="items" :grouping-items="groupingItems" grouping-title="Группировки" @reset="reset">
      <template #filter-search><label class="irlix-field"><span>Поиск</span><input v-model="query" type="search" placeholder="Демо-поиск" /></label></template>
      <template #filter-department><label class="irlix-field"><span>Подразделение</span><UiSearchSelect v-model="department" :options="departments" placeholder="Все подразделения" /></label></template>
      <template #filter-status><label class="irlix-field"><span>Статус</span><UiSearchSelect v-model="status" :options="statuses" placeholder="Все статусы" /></label></template>
      <template #grouping-primary><label class="irlix-field"><span>Группировка</span><UiSearchSelect v-model="grouping" :options="groupings" :clearable="false" /></label></template>
    </UiFilterRail>
  </div>
</template>

<style scoped>
.filter-rail-showcase {
  position: fixed;
  right: 16px;
  bottom: 16px;
  z-index: 120;
}
.filter-rail-showcase :deep(.irlix-filter-rail) { z-index: 110; }
</style>
