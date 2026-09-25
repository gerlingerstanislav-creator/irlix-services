<script setup>
import { UiBadge } from '@irlix/ui';
import AbsenceActions from './AbsenceActions.vue';
import { formatDate, statusLabels, typeLabels } from '../constants';

defineProps({
  items: { type: Array, default: () => [] },
  showEmployee: { type: Boolean, default: false },
  showStage: { type: Boolean, default: false },
});
const emit = defineEmits(['action']);

const actionsOf = (item) => item.available_actions || [];
const absenceId = (item) => item.absence_id || item.id;
</script>

<template>
  <div class="table-wrap">
    <table class="irlix-data-table vacations-table">
      <thead>
        <tr>
          <th v-if="showEmployee">Сотрудник</th>
          <th>Период</th>
          <th>Дни</th>
          <th>Тип</th>
          <th>Статус</th>
          <th v-if="showStage">Этап</th>
          <th>Документы</th>
          <th class="actions-cell"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="`${absenceId(item)}-${item.id}`" class="clickable-row" @click="emit('action', { action: 'view', item })">
          <td v-if="showEmployee">
            <strong>{{ item.employee_name || `#${item.employee_id}` }}</strong>
            <small>{{ item.department_name || '—' }}</small>
          </td>
          <td><strong>{{ formatDate(item.starts_on) }}</strong><small> — {{ formatDate(item.ends_on) }}</small></td>
          <td>{{ item.calendar_days ?? '—' }}</td>
          <td>{{ typeLabels[item.type] || item.type }}</td>
          <td><UiBadge tone="info">{{ statusLabels[item.absence_status || item.status] || item.absence_status || item.status }}</UiBadge></td>
          <td v-if="showStage">{{ statusLabels[item.stage] || item.stage || '—' }}</td>
          <td>{{ Number(item.attachment_count || 0) > 0 ? `${item.attachment_count} файл(а)` : '—' }}</td>
          <td class="actions-cell" @click.stop>
            <AbsenceActions :actions="actionsOf(item)" @action="action => emit('action', { action, item })" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
