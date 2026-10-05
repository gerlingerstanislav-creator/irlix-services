<script setup>
import { computed, ref, watch } from 'vue';
import { UiBadge, UiButton } from '@irlix/ui';
import AbsenceActions from './AbsenceActions.vue';
import { api, downloadFile } from '../api';
import { formatDate, formatDateTime, statusLabels, typeLabels } from '../constants';

const props = defineProps({
  open: Boolean,
  loading: Boolean,
  detail: { type: Object, default: null },
});
const emit = defineEmits(['close', 'action', 'changed', 'error']);
const attachments = ref([]);
const documentsLoading = ref(false);
const uploading = ref(false);
const fileInput = ref(null);

const absence = computed(() => props.detail?.absence || null);
const actions = computed(() => absence.value?.available_actions || []);
const topActions = computed(() => actions.value.filter((action) => !['view', 'history', 'view_attachments', 'approve', 'provide'].includes(action)));
const canReadDocuments = computed(() => actions.value.includes('view_attachments') || actions.value.includes('upload_attachment'));
const canUpload = computed(() => actions.value.includes('upload_attachment'));
const approvalCount = computed(() => props.detail?.approvals?.length || 0);
const historyCount = computed(() => props.detail?.history?.length || 0);

const stageLabel = (stage) => ({
  hr_review: 'Первичная проверка кадровиком',
  account_manager_review: 'Согласование с аккаунт-менеджером',
  manager_review: 'Согласование с руководителем',
  hr_final_review: 'Итоговое подтверждение кадровиком',
}[stage] || statusLabels[stage] || stage || 'Этап согласования');
const approvalStatusLabel = (step) => step.status === 'approved'
  ? 'Согласовано'
  : step.status === 'pending'
    ? 'Ожидает действия'
    : 'Ожидает этапа';
const isCurrentApproval = (step) => Number(step.id) === Number(absence.value?.pending_approval_id || 0) && step.status === 'pending';
const approvalAction = (step) => step.stage === 'hr_final_review' && actions.value.includes('provide') ? 'provide' : 'approve';
const approvalActionLabel = (step) => approvalAction(step) === 'provide' ? 'Предоставить отпуск' : 'Согласовать';
const triggerApproval = (step) => emit('action', {
  action: approvalAction(step),
  item: { ...absence.value, pending_approval_id: step.id },
});

const loadAttachments = async () => {
  if (!absence.value || !canReadDocuments.value) { attachments.value = []; return; }
  documentsLoading.value = true;
  try {
    const payload = await api(`/api/vacations/absences/${absence.value.id}/attachments`);
    attachments.value = payload.data || [];
  } catch (error) {
    emit('error', error.message);
  } finally {
    documentsLoading.value = false;
  }
};

watch(() => [props.open, absence.value?.id], ([isOpen]) => {
  if (isOpen) loadAttachments();
}, { immediate: true });

const upload = async () => {
  const file = fileInput.value?.files?.[0];
  if (!file || !absence.value) return;
  uploading.value = true;
  try {
    const form = new FormData();
    form.append('file', file);
    form.append('kind', 'application');
    await api(`/api/vacations/absences/${absence.value.id}/attachments`, { method: 'POST', body: form });
    fileInput.value.value = '';
    await loadAttachments();
    emit('changed');
  } catch (error) {
    emit('error', error.message);
  } finally {
    uploading.value = false;
  }
};

const download = async (item) => {
  try {
    await downloadFile(`/api/vacations/absences/${absence.value.id}/attachments/${item.id}/download`, item.original_name || 'document');
  } catch (error) {
    emit('error', error.message);
  }
};

const remove = async (item) => {
  if (!confirm(`Удалить документ «${item.original_name}»?`)) return;
  try {
    await api(`/api/vacations/absences/${absence.value.id}/attachments/${item.id}`, { method: 'DELETE' });
    await loadAttachments();
    emit('changed');
  } catch (error) {
    emit('error', error.message);
  }
};
</script>

<template>
  <div v-if="open" class="drawer-overlay" @click.self="emit('close')">
    <aside class="absence-drawer compact-absence-drawer">
      <div class="drawer-head compact-drawer-head">
        <div>
          <div class="eyebrow">КАРТОЧКА ОТСУТСТВИЯ</div>
          <h2>{{ absence ? typeLabels[absence.type] || absence.type : 'Загрузка…' }}</h2>
          <p v-if="absence">{{ absence.employee_name || 'Сотрудник' }} · {{ absence.department_name || 'Без подразделения' }}</p>
        </div>
        <div class="drawer-head-actions">
          <AbsenceActions v-if="absence && topActions.length" :actions="topActions" @action="action => emit('action', { action, item: absence })" />
          <button class="close" type="button" aria-label="Закрыть" @click="emit('close')">×</button>
        </div>
      </div>

      <div v-if="loading" class="empty compact">Загрузка карточки…</div>
      <template v-else-if="absence">
        <section class="compact-fields" aria-label="Основные данные отсутствия">
          <div class="compact-field-row">
            <span>Период</span>
            <strong>{{ formatDate(absence.starts_on) }} — {{ formatDate(absence.ends_on) }}</strong>
          </div>
          <div class="compact-field-row">
            <span>Длительность</span>
            <strong>{{ absence.entitlement_days ?? absence.calendar_days ?? '—' }} дн.</strong>
          </div>
          <div class="compact-field-row">
            <span>Статус</span>
            <UiBadge tone="info">{{ statusLabels[absence.status] || absence.status }}</UiBadge>
          </div>
          <div class="compact-field-row compact-field-row-top">
            <span>Комментарий</span>
            <strong>{{ absence.comment || '—' }}</strong>
          </div>
          <div class="compact-field-row compact-field-row-top compact-documents-field">
            <span>Документы</span>
            <div class="compact-documents-value">
              <div v-if="!canReadDocuments" class="compact-muted">{{ Number(absence.attachment_count || 0) ? `${absence.attachment_count} файл(а)` : '—' }}</div>
              <div v-else-if="documentsLoading" class="compact-muted">Загрузка…</div>
              <div v-else-if="!attachments.length" class="compact-muted">—</div>
              <div v-else class="compact-document-list">
                <div v-for="item in attachments" :key="item.id" class="compact-document-row">
                  <button type="button" class="compact-document-name" @click="download(item)">{{ item.original_name }}</button>
                  <small>{{ Math.ceil(Number(item.size_bytes || 0) / 1024) }} КБ · {{ formatDateTime(item.created_at) }}</small>
                  <button v-if="canUpload" type="button" class="danger-text compact-document-remove" @click="remove(item)">Удалить</button>
                </div>
              </div>
              <div v-if="canUpload" class="compact-upload-row">
                <input ref="fileInput" type="file" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" />
                <UiButton size="sm" :disabled="uploading" @click="upload">{{ uploading ? 'Загрузка…' : 'Загрузить' }}</UiButton>
              </div>
            </div>
          </div>
        </section>

        <section class="drawer-section compact-history-section">
          <div class="section-title compact-section-title">
            <h3>История</h3>
            <span>{{ approvalCount + historyCount }}</span>
          </div>

          <div v-if="!approvalCount && !historyCount" class="compact-muted">История пока пуста.</div>
          <div v-else class="compact-history-list">
            <div v-for="step in detail?.approvals || []" :key="`approval-${step.id}`" class="compact-history-row approval-history-row" :class="step.status">
              <div class="compact-history-marker" aria-hidden="true"></div>
              <div class="compact-history-content">
                <strong>{{ stageLabel(step.stage) }}</strong>
                <small>{{ step.approver_name || 'Согласующий не назначен' }} · {{ approvalStatusLabel(step) }}</small>
              </div>
              <UiButton v-if="isCurrentApproval(step)" size="sm" @click="triggerApproval(step)">{{ approvalActionLabel(step) }}</UiButton>
            </div>

            <div v-for="event in [...(detail?.history || [])].reverse()" :key="`history-${event.id}`" class="compact-history-row status-history-row">
              <div class="compact-history-date">{{ formatDateTime(event.created_at) }}</div>
              <div class="compact-history-content">
                <strong>{{ statusLabels[event.from_status] || event.from_status || 'Создание' }} → {{ statusLabels[event.to_status] || event.to_status }}</strong>
                <small>{{ event.reason || 'Изменение статуса' }}</small>
              </div>
            </div>
          </div>
        </section>
      </template>
    </aside>
  </div>
</template>
