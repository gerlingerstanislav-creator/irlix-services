<script setup>
import { onMounted, ref, watch } from 'vue';
import { UiButton, UiPageHeader, UiPanel } from '@irlix/ui';
import { api } from '../api';
import AbsenceTable from '../components/AbsenceTable.vue';

const props = defineProps({ refreshToken: { type: Number, default: 0 } });
const emit = defineEmits(['action', 'error']);
const items = ref([]);
const loading = ref(false);

const load = async () => {
  loading.value = true;
  try {
    const payload = await api('/api/vacations/approvals');
    items.value = payload.data || [];
  } catch (error) {
    emit('error', error.message);
  } finally {
    loading.value = false;
  }
};

onMounted(load);
watch(() => props.refreshToken, load);
</script>

<template>
  <UiPageHeader eyebrow="VACATIONS / ABSENCES" title="Согласования" description="Заявки, которые прямо сейчас требуют вашего решения.">
    <template #actions><UiButton variant="secondary" @click="load">Обновить</UiButton></template>
  </UiPageHeader>
  <section class="stats single-stat"><div><strong>{{ items.length }}</strong><span>Требуют моего действия</span></div></section>
  <UiPanel>
    <div v-if="loading" class="empty">Загрузка очереди…</div>
    <div v-else-if="!items.length" class="empty"><strong>Очередь пуста</strong><span>Сейчас нет заявок, требующих вашего решения.</span></div>
    <AbsenceTable v-else :items="items" show-employee show-stage @action="emit('action', $event)" />
  </UiPanel>
</template>
