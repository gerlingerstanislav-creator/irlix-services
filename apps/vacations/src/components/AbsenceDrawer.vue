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
const canReadDocuments = computed(() => actions.value.includes('view_attachments') || actions.value.includes('upload_attachment'));
const canUpload = computed(() => actions.value.includes('upload_attachment'));

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
    <aside class="absence-drawer">
      <div class="drawer-head">
        <div>
          <div class="eyebrow">КАРТОЧКА ОТСУТСТВИЯ</div>
          <h2>{{ absence ? typeLabels[absence.type] || absence.type : 'Загрузка…' }}</h2>
          <p v-if="absence">{{ absence.employee_name || 'Сотрудник' }} · {{ absence.department_name || 'Без подразделения' }}</p>
        </div>
        <div class="drawer-head-actions">
          <AbsenceActions v-if="absence" :actions="actions" @action="action => emit('action', { action, item: absence })" />
          <button class="close" type="button" aria-label="Закрыть" @click="emit('close')">×</button>
        </div>
      </div>

      <div v-if="loading" class="empty compact">Загрузка карточки…</div>
      <template v-else-if="absence">
        <section class="drawer-summary">
          <div><span>Период</span><strong>{{ formatDate(absence.starts_on) }} — {{ formatDate(absence.ends_on) }}</strong></div>
          <div><span>Длительность</span><strong>{{ absence.entitlement_days ?? absence.calendar_days ?? '—' }} дн.</strong></div>
          <div><span>Статус</span><UiBadge tone="info">{{ statusLabels[absence.status] || absence.status }}</UiBadge></div>
          <div><span>Комментарий</span><strong>{{ absence.comment || '—' }}</strong></div>
        </section>

        <section class="drawer-section">
          <div class="section-title"><h3>Документы</h3><span>{{ absence.attachment_count || attachments.length || 0 }}</span></div>
          <div v-if="!canReadDocuments" class="privacy-note">Есть возможность видеть факт наличия документов, но их содержимое доступно только сотруднику и кадровому специалисту.</div>
          <template v-else>
            <div v-if="documentsLoading" class="muted">Загрузка документов…</div>
            <div v-else-if="!attachments.length" class="muted">Документы пока не загружены.</div>
            <div v-else class="document-list">
              <div v-for="item in attachments" :key="item.id" class="document-row">
                <div><strong>{{ item.original_name }}</strong><small>{{ Math.ceil(Number(item.size_bytes || 0) / 1024) }} КБ · {{ formatDateTime(item.created_at) }}</small></div>
                <div class="document-actions">
                  <button type="button" @click="download(item)">Скачать</button>
                  <button v-if="canUpload" type="button" class="danger-text" @click="remove(item)">Удалить</button>
                </div>
              </div>
            </div>
            <div v-if="canUpload" class="upload-row">
              <input ref="fileInput" type="file" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" />
              <UiButton :disabled="uploading" @click="upload">{{ uploading ? 'Загрузка…' : 'Загрузить' }}</UiButton>
            </div>
          </template>
        </section>

        <section class="drawer-section">
          <div class="section-title"><h3>Согласование</h3><span>{{ detail?.approvals?.length || 0 }}</span></div>
          <div v-if="!detail?.approvals?.length" class="muted">Цепочка согласования ещё не создана.</div>
          <ol v-else class="approval-timeline">
            <li v-for="step in detail.approvals" :key="step.id" :class="step.status">
              <div><strong>{{ statusLabels[step.stage] || step.stage }}</strong><small>{{ step.required_role || '—' }}</small></div>
              <span>{{ step.status === 'approved' ? 'Согласовано' : step.status === 'pending' ? 'Ожидает действия' : 'Ожидает этапа' }}</span>
            </li>
          </ol>
        </section>

        <section class="drawer-section">
          <div class="section-title"><h3>История</h3><span>{{ detail?.history?.length || 0 }}</span></div>
          <div v-if="!detail?.history?.length" class="muted">История пока пуста.</div>
          <div v-else class="history-list">
            <div v-for="event in [...detail.history].reverse()" :key="event.id" class="history-row">
              <span>{{ formatDateTime(event.created_at) }}</span>
              <strong>{{ statusLabels[event.from_status] || event.from_status || 'Создание' }} → {{ statusLabels[event.to_status] || event.to_status }}</strong>
              <small>{{ event.reason || 'Изменение статуса' }}</small>
            </div>
          </div>
        </section>
      </template>
    </aside>
  </div>
</template>
