<script setup>
import { computed } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from '../auth';

const props = defineProps({
  section: { type: String, required: true },
  canReadAudit: { type: Boolean, default: false },
  canManageRoles: { type: Boolean, default: false },
  canManageSystemAccounts: { type: Boolean, default: false },
  canManagePermissions: { type: Boolean, default: false },
  canReadFot: { type: Boolean, default: false },
});
const emit = defineEmits(['update:section']);

/**
 * Add dividers only between groups that actually have visible pages.
 * Permission filtering can otherwise leave a separator above an empty group.
 */
const items = computed(() => [
  [
    { id: 'employees', label: 'Сотрудники', icon: 'users' },
    { id: 'fot', label: 'ФОТ и пересмотры', icon: 'chart', visible: props.canReadFot },
  ],
  [
    { id: 'positions', label: 'Орг. структура', icon: 'org' },
    { id: 'staffPositions', label: 'Должности', icon: 'briefcase' },
  ],
  [
    { id: 'systemAccounts', label: 'Системные аккаунты', icon: 'user-badge', visible: props.canManageSystemAccounts },
    { id: 'roles', label: 'Роли', icon: 'user-badge', visible: props.canManageRoles },
    { id: 'permissions', label: 'Разрешения', icon: 'permissions', visible: props.canManagePermissions },
    { id: 'audit', label: 'История действий', icon: 'audit', visible: props.canReadAudit },
  ],
].map(group => group.filter(item => item.visible !== false))
  .filter(group => group.length > 0)
  .flatMap((group, index) => group.map((item, itemIndex) => ({
    ...item, groupStart: index > 0 && itemIndex === 0,
  }))));
</script>

<template>
  <UiAppSidebar
    :section="section"
    :items="items"
    current-service="employees"
    :current-user="auth.user"
    :platform-access="() => auth.fetch('/api/employees/access/me')"
    :report-submit="(url, options) => auth.fetch(url, options)"
    aria-label="Навигация сервиса сотрудников"
    @update:section="emit('update:section', $event)"
    @logout="auth.logout"
  />
</template>
