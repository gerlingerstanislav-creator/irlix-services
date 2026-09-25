<script setup>
import { UiBadge } from '@irlix/ui';
import AbsenceActions from './AbsenceActions.vue';
import { formatDate, statusLabels, typeLabels } from '../constants';

defineProps({
  items: { type: Array, default: () => [] },
  showEmployee: { type: Boolean, default: false },
  showStage: { type: Boolean, default: false },
  showProgress: { type: Boolean, default: false },
});
const emit = defineEmits(['action']);

const actionsOf = (item) => item.available_actions || [];
const absenceId = (item) => item.absence_id || item.id;
const stageLabel = (task) => ({
  hr_review: 'Кадровик',
  account_manager_review: 'Аккаунт-менеджер',
  manager_review: 'Руководитель',
  hr_final_review: 'Кадровик · финал',
}[task.stage] || statusLabels[task.stage] || task.stage || 'Этап');
const taskClass = (task) => task.status === 'approved' ? 'done' : task.status === 'pending' ? 'current' : 'waiting';
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
          <th v-if="showProgress" class="progress-cell">Согласование</th>
          <th class="actions-cell"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="`${absenceId(item)}-${item.id}`" class="clickable-row" @click="emit('action', { action: 'view', item })">
          <td v-if="showEmployee">
            <strong>{{ item.employee_name || `#${item.employee_id}` }}</strong>
            <small>{{ item.department_name || '—' }}</small>
          </td>
          <td><span class="period-text">{{ formatDate(item.starts_on) }} — {{ formatDate(item.ends_on) }}</span></td>
          <td>{{ item.calendar_days ?? '—' }}</td>
          <td>{{ typeLabels[item.type] || item.type }}</td>
          <td><UiBadge tone="info">{{ statusLabels[item.absence_status || item.status] || item.absence_status || item.status }}</UiBadge></td>
          <td v-if="showStage">{{ statusLabels[item.stage] || item.stage || '—' }}</td>
          <td @click.stop>
            <button v-if="Number(item.attachment_count || 0) > 0" type="button" class="document-link" @click="emit('action', { action: 'view_attachments', item })">
              {{ Number(item.attachment_count) === 1 ? 'Открыть' : `${item.attachment_count} файла` }}
            </button>
            <span v-else>—</span>
          </td>
          <td v-if="showProgress" class="progress-cell" @click.stop>
            <div v-if="item.approval_progress?.length" class="approval-progress" :title="item.requires_my_action ? 'Есть этап, требующий вашего действия' : 'Прогресс согласования'">
              <span v-for="task in item.approval_progress" :key="task.id" class="approval-step" :class="taskClass(task)" :title="`${stageLabel(task)} · ${task.status}`">
                <svg v-if="task.status === 'approved'" viewBox="0 0 16 16" aria-hidden="true"><path d="m3 8 3 3 7-7" /></svg>
                <span v-else class="approval-dot"></span>
              </span>
            </div>
            <div v-else-if="item.status === 'confirmed'" class="approval-progress"><span class="approval-step done" title="Предоставлено"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3 8 3 3 7-7" /></svg></span></div>
            <span v-else class="progress-empty">—</span>
          </td>
          <td class="actions-cell" @click.stop>
            <AbsenceActions :actions="actionsOf(item)" @action="action => emit('action', { action, item })" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
