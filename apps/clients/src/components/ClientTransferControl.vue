<script setup>
import { computed, ref } from 'vue';
import { UiButton, UiSearchSelect } from '@irlix/ui';

const props = defineProps({ clientId: { type: Number, required: true }, options: { type: Array, default: () => [] } });
const emit = defineEmits(['changed']);
const open = ref(false), value = ref(''), saving = ref(false), loading = ref(false), error = ref(''), accounts = ref([]);
const options = computed(() => accounts.value.length ? accounts.value : props.options);

async function loadAccounts() {
  if (accounts.value.length || loading.value) return;
  loading.value = true; error.value = '';
  try {
    const [accessResponse, directoryResponse] = await Promise.all([
      fetch('/api/clients/permissions/me', { headers: { Accept: 'application/json' } }),
      fetch('/api/employees/clients-directory', { headers: { Accept: 'application/json' } }),
    ]);
    const access = await accessResponse.json().catch(() => ({}));
    const directory = await directoryResponse.json().catch(() => ({}));
    if (!accessResponse.ok || !directoryResponse.ok) throw new Error('Не удалось загрузить список аккаунт-менеджеров');
    const ids = new Set((access.data?.account_employee_ids || []).map(Number));
    accounts.value = (directory.data?.employees || [])
      .filter(employee => ids.has(Number(employee.id)))
      .map(employee => ({ value: String(employee.id), label: employee.full_name || `#${employee.id}` }))
      .sort((a, b) => a.label.localeCompare(b.label, 'ru'));
    if (!accounts.value.length) throw new Error('В оргструктуре не найдены доступные аккаунт-менеджеры');
  } catch (e) { error.value = e.message || String(e); }
  finally { loading.value = false; }
}
async function toggle() {
  open.value = !open.value;
  if (open.value) await loadAccounts();
}
async function submit() {
  if (!value.value) return;
  saving.value = true; error.value = '';
  try {
    const response = await fetch(`/api/clients/clients/${props.clientId}/transfer`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ account_employee_id: Number(value.value) }) });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || 'Не удалось передать клиента');
    open.value = false; value.value = ''; emit('changed');
  } catch (e) { error.value = e.message || String(e); } finally { saving.value = false; }
}
</script>

<template>
  <div class="client-transfer-control">
    <UiButton variant="secondary" @click="toggle">Передать проект аккаунт-менеджеру</UiButton>
    <form v-if="open" class="transfer-popover" @submit.prevent="submit">
      <div class="transfer-title">Передача клиента</div>
      <UiSearchSelect v-model="value" :options="options" placeholder="Выберите аккаунт-менеджера" search-placeholder="Поиск аккаунт-менеджера" :clearable="false" :disabled="loading" />
      <div v-if="loading" class="transfer-hint">Загрузка аккаунт-менеджеров…</div>
      <div v-if="error" class="transfer-error">{{error}}</div>
      <div class="transfer-actions"><button type="button" @click="open=false">Отмена</button><button type="submit" :disabled="saving||loading||!value">Передать</button></div>
    </form>
  </div>
</template>

<style scoped>
.client-transfer-control{position:fixed;right:28px;bottom:24px;z-index:1201}.transfer-popover{position:absolute;right:0;bottom:44px;width:320px;padding:12px;display:grid;gap:9px;border:1px solid var(--irlix-color-border);border-radius:10px;background:var(--irlix-color-surface);box-shadow:0 12px 30px color-mix(in srgb, var(--irlix-color-shadow-base) 16%, transparent)}.transfer-title{font-size:var(--irlix-font-size-table);font-weight:700;color:var(--irlix-color-text)}.transfer-actions{display:flex;justify-content:flex-end;gap:6px}.transfer-actions button{border:1px solid var(--irlix-color-border);border-radius:7px;padding:6px 10px;background:var(--irlix-color-surface)}.transfer-actions button[type=submit]{border-color:var(--irlix-status-success-border);background:var(--irlix-color-primary);color:var(--irlix-color-on-accent)}.transfer-error{color:var(--irlix-status-danger-text);font-size:var(--irlix-font-size-caption)}.transfer-hint{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}
</style>
