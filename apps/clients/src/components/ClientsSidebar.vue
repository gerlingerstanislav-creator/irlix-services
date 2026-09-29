<script setup>
import { computed } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from '../auth';

const props = defineProps({
  section: { type: String, required: true },
  accounting: { type: Boolean, default: false },
});
const emit = defineEmits(['update:section']);

const items = computed(() => [
  { id: 'requests', label: 'Запросы', icon: 'target' },
  { id: 'positions', label: 'Позиции', icon: 'list' },
  { id: 'attempts', label: 'Попытки подключения', icon: 'rocket' },
  { id: 'leads', label: 'Лиды', icon: 'crown', groupStart: true },
  { id: 'clients', label: 'Клиенты', icon: 'briefcase' },
  { id: 'contacts', label: 'Контактные лица', icon: 'contact' },
  { id: 'members', label: 'Участники проектов', icon: 'members' },
  { id: 'reports', label: 'Отчётные периоды', icon: 'reports', groupStart: true },
  { id: 'cashflow', label: 'ДДС', icon: 'cash' },
].filter((item) => !props.accounting || item.id !== 'leads'));
</script>

<template>
  <UiAppSidebar
    :section="props.section"
    :items="items"
    current-service="clients"
    :current-user="auth.user"
    aria-label="Навигация сервиса клиентов"
    @update:section="emit('update:section', $event)"
    @logout="auth.logout"
  />
</template>
