<script setup>
import { computed } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from '../auth';

const props = defineProps({
  section: { type: String, required: true },
  items: { type: Array, default: () => [] },
  canReadHistory: { type: Boolean, default: true },
});
const emit = defineEmits(['update:section']);

const bottomItems = computed(() => props.canReadHistory
  ? [{ id: 'history', label: 'История действий', icon: 'audit' }]
  : []);
</script>

<template>
  <UiAppSidebar
    :section="section"
    :items="items"
    current-service="vacations"
    :current-user="auth.user"
    :platform-access="() => auth.fetch('/api/employees/access/me')"
    :bottom-items="bottomItems"
    aria-label="Навигация сервиса отпусков"
    @update:section="emit('update:section', $event)"
    @logout="auth.logout"
  />
</template>
