<script setup>
import { computed } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from '../auth';

const props = defineProps({
  section: { type: String, required: true },
  canReadAudit: { type: Boolean, default: false },
  canManageRoles: { type: Boolean, default: false },
  canManagePermissions: { type: Boolean, default: false },
});
const emit = defineEmits(['update:section']);

const items = computed(() => [
  { id: 'employees', label: 'Сотрудники', icon: 'users' },
  { id: 'positions', label: 'Орг. структура', icon: 'org' },
  { id: 'staffPositions', label: 'Должности', icon: 'briefcase' },
  { id: 'roles', label: 'Роли', icon: 'user-badge', visible: props.canManageRoles },
].filter((item) => item.visible !== false));

const bottomItems = computed(() => [
  { id: 'permissions', label: 'Разрешения', icon: 'permissions', visible: props.canManagePermissions },
  { id: 'audit', label: 'История действий', icon: 'audit', visible: props.canReadAudit },
].filter((item) => item.visible));
</script>

<template>
  <UiAppSidebar
    :section="section"
    :items="items"
    current-service="employees"
    :current-user="auth.user"
    :platform-access="() => auth.fetch('/api/employees/access/me')"
    :bottom-items="bottomItems"
    aria-label="Навигация сервиса сотрудников"
    @update:section="emit('update:section', $event)"
    @logout="auth.logout"
  />
</template>
