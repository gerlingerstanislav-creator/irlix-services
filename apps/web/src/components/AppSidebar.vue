<script setup>
import { computed } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from '../auth';

const props = defineProps({
  section: { type: String, required: true },
  canReadAudit: { type: Boolean, default: false },
  canManageRoles: { type: Boolean, default: false },
});
const emit = defineEmits(['update:section']);

const items = computed(() => [
  { id: 'employees', label: 'Сотрудники', icon: 'users' },
  { id: 'departments', label: 'Подразделения', icon: 'org' },
  { id: 'positions', label: 'Орг. структура', icon: 'org' },
  { id: 'roles', label: 'Роли', icon: 'roles', visible: props.canManageRoles },
].filter((item) => item.visible !== false));

const bottomItems = computed(() => props.canReadAudit
  ? [{ id: 'audit', label: 'История действий', icon: 'audit' }]
  : []);
</script>

<template>
  <UiAppSidebar
    :section="section"
    :items="items"
    current-service="employees"
    :current-user="auth.user"
    :bottom-items="bottomItems"
    aria-label="Навигация сервиса сотрудников"
    @update:section="emit('update:section', $event)"
    @logout="auth.logout"
  />
</template>
