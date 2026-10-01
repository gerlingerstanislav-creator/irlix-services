<script setup>
import { ref } from 'vue';
import { UiButton, UiSearchSelect } from '@irlix/ui';

const props = defineProps({ clientId: { type: Number, required: true }, options: { type: Array, default: () => [] } });
const emit = defineEmits(['changed']);
const open = ref(false), value = ref(''), saving = ref(false), error = ref('');
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
    <UiButton variant="secondary" @click="open=!open">Передать проект аккаунт-менеджеру</UiButton>
    <form v-if="open" class="transfer-popover" @submit.prevent="submit">
      <UiSearchSelect v-model="value" :options="options" placeholder="Выберите аккаунт-менеджера" :clearable="false" />
      <div v-if="error" class="transfer-error">{{error}}</div>
      <div class="transfer-actions"><button type="button" @click="open=false">Отмена</button><button type="submit" :disabled="saving||!value">Передать</button></div>
    </form>
  </div>
</template>

<style scoped>
.client-transfer-control{position:fixed;right:28px;bottom:24px;z-index:1201}.transfer-popover{position:absolute;right:0;bottom:44px;width:300px;padding:12px;display:grid;gap:9px;border:1px solid #dfe3e7;border-radius:10px;background:#fff;box-shadow:0 12px 30px rgba(20,30,40,.16)}.transfer-actions{display:flex;justify-content:flex-end;gap:6px}.transfer-actions button{border:1px solid #dfe3e7;border-radius:7px;padding:6px 10px;background:#fff}.transfer-actions button[type=submit]{border-color:#087f67;background:#087f67;color:#fff}.transfer-error{color:#b42318;font-size:12px}
</style>
