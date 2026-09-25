<script setup>
import { onMounted, ref } from 'vue';
import { UiBadge, UiButton, UiPageHeader, UiPanel } from '@irlix/ui';
import { auth } from '../auth';

const props = defineProps({ employees: { type: Array, default: () => [] } });
const rows = ref([]);
const actions = ref([]);
const loading = ref(false);
const error = ref('');
const filters = ref({ employee_id: '', action: '', actor: '', from: '', to: '' });

const request = async () => {
  loading.value = true; error.value = '';
  try {
    const params = new URLSearchParams();
    Object.entries(filters.value).forEach(([key, value]) => { if (value) params.set(key, value); });
    params.set('limit', '200');
    const response = await auth.fetch(`/api/employees/audit?${params.toString()}`, { headers: { Accept: 'application/json' } });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`);
    rows.value = payload.data ?? [];
    actions.value = payload.meta?.actions ?? [];
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
};

const formatDateTime = (value) => value ? new Date(value).toLocaleString('ru-RU') : '—';
const changedFields = (row) => row.metadata?.changed_fields?.join(', ') || '—';
const pretty = (value) => JSON.stringify(value ?? null, null, 2);

onMounted(request);
</script>

<template>
  <div>
    <UiPageHeader eyebrow="AUDIT" title="История действий" description="Неизменяемый журнал чувствительных операций Employees и изменений прав." />
    <div v-if="error" class="alert">{{ error }}</div>
    <UiPanel>
      <div class="audit-filters">
        <select v-model="filters.employee_id"><option value="">Все сотрудники</option><option v-for="employee in props.employees" :key="employee.id" :value="employee.id">{{ employee.full_name }}</option></select>
        <select v-model="filters.action"><option value="">Все действия</option><option v-for="action in actions" :key="action" :value="action">{{ action }}</option></select>
        <input v-model="filters.actor" placeholder="Кто выполнил" />
        <input v-model="filters.from" type="date" aria-label="Дата с" />
        <input v-model="filters.to" type="date" aria-label="Дата по" />
        <UiButton variant="secondary" @click="request">Применить</UiButton>
      </div>
      <div v-if="loading" class="empty-state">Загрузка журнала…</div>
      <div v-else-if="!rows.length" class="empty-state"><strong>Записей не найдено</strong></div>
      <div v-else class="table-wrap">
        <table class="irlix-data-table audit-table">
          <thead><tr><th>Время</th><th>Действие</th><th>Объект</th><th>Кто</th><th>Изменено</th><th>Статус</th><th /></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td>{{ formatDateTime(row.occurred_at) }}</td>
              <td><code>{{ row.action }}</code></td>
              <td>{{ row.target_label || `${row.target_type || 'object'} #${row.target_id || '—'}` }}</td>
              <td>{{ row.actor_name || row.actor_sub || 'system' }}</td>
              <td>{{ changedFields(row) }}</td>
              <td><UiBadge :tone="row.status === 'success' ? 'success' : 'danger'">{{ row.status }}</UiBadge></td>
              <td><details class="audit-details"><summary>Детали</summary><div><strong>Before</strong><pre>{{ pretty(row.before) }}</pre><strong>After</strong><pre>{{ pretty(row.after) }}</pre><strong>Metadata</strong><pre>{{ pretty(row.metadata) }}</pre></div></details></td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiPanel>
  </div>
</template>
