<script setup>
import { computed } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from '../auth';

const props = defineProps({
  section: { type: String, required: true },
  allowedSections: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:section']);

const items = computed(() => {
  const visible = [
  { id: 'requests', label: 'Запросы', icon: 'target' },
  { id: 'positions', label: 'Позиции', icon: 'list' },
  { id: 'attempts', label: 'Попытки подключения', icon: 'rocket' },
  { id: 'leads', label: 'Лиды', icon: 'crown', groupStart: true },
  { id: 'clients', label: 'Клиенты', icon: 'briefcase' },
  { id: 'contacts', label: 'Контактные лица', icon: 'contact' },
  { id: 'members', label: 'Участники проектов', icon: 'members' },
  { id: 'reports', label: 'Отчётные периоды', icon: 'reports', groupStart: true },
  { id: 'cashflow', label: 'ДДС', icon: 'cash' },
  ].filter((item) => props.allowedSections.includes(item.id));
  const leadIndex = visible.findIndex(item => item.id === 'leads');
  if (leadIndex >= 0) visible.splice(leadIndex + 1, 0, { id:'tenders', label:'Тендеры', icon:'reports', disabled:true });
  return visible;
});
const bottomItems = computed(() => [
  { id: 'permissions', label: 'Настройки разрешений', icon: 'roles' },
].filter((item) => props.allowedSections.includes(item.id)));
</script>

<template>
  <UiAppSidebar
    :section="props.section"
    :items="items"
    :bottom-items="bottomItems"
    current-service="clients"
    :current-user="auth.user"
    :platform-access="() => auth.fetch('/api/employees/access/me')"
    aria-label="Навигация сервиса клиентов"
    @update:section="emit('update:section', $event)"
    @logout="auth.logout"
  />
</template>
