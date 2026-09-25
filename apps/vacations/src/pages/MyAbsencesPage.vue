<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { UiButton, UiPageHeader, UiPanel } from '@irlix/ui';
import { api } from '../api';
import { ownActions, typeLabels } from '../constants';
import AbsenceTable from '../components/AbsenceTable.vue';

const props = defineProps({ profile: { type: Object, default: null }, refreshToken: { type: Number, default: 0 } });
const emit = defineEmits(['action', 'error', 'changed']);
const year = ref(new Date().getFullYear());
const absences = ref([]);
const loading = ref(false);
const showForm = ref(false);
const saving = ref(false);
const form = ref({ type: 'paid_vacation', starts_on: '', ends_on: '', comment: '' });

const paidDays = computed(() => absences.value
  .filter((item) => item.type === 'paid_vacation' && !['cancelled', 'rejected'].includes(item.status))
  .reduce((sum, item) => sum + Number(item.entitlement_days ?? item.calendar_days ?? 0), 0));

const rows = computed(() => absences.value.map((item) => ({ ...item, available_actions: ownActions(item) })));

const load = async () => {
  loading.value = true;
  try {
    const payload = await api(`/api/vacations/absences?year=${year.value}`);
    absences.value = payload.data || [];
  } catch (error) {
    emit('error', error.message);
  } finally {
    loading.value = false;
  }
};

const save = async () => {
  saving.value = true;
  try {
    await api('/api/vacations/absences', { method: 'POST', body: form.value });
    showForm.value = false;
    form.value = { type: 'paid_vacation', starts_on: '', ends_on: '', comment: '' };
    await load();
    emit('changed');
  } catch (error) {
    emit('error', error.message);
  } finally {
    saving.value = false;
  }
};

onMounted(load);
watch(() => props.refreshToken, load);
</script>

<template>
  <UiPageHeader
    eyebrow="VACATIONS / ABSENCES"
    title="Мои отпуска"
    :description="profile ? `${profile.full_name} · ${profile.department_name || 'Без подразделения'}` : 'Личное планирование отсутствий'"
  >
    <template #actions><UiButton @click="showForm = true">+ Запланировать отсутствие</UiButton></template>
  </UiPageHeader>

  <section class="stats">
    <div><strong>{{ paidDays }}</strong><span>Оплачиваемых дней в {{ year }} году</span></div>
    <div><strong>{{ absences.length }}</strong><span>Всего отсутствий в {{ year }} году</span></div>
    <div><strong>—</strong><span>Кадровый остаток будет подключён из 1С</span></div>
  </section>

  <UiPanel>
    <div class="toolbar">
      <label>Год
        <select v-model="year" @change="load">
          <option v-for="item in [year - 1, year, year + 1]" :key="item" :value="item">{{ item }}</option>
        </select>
      </label>
      <UiButton variant="secondary" @click="load">Обновить</UiButton>
    </div>
    <div v-if="loading" class="empty">Загрузка…</div>
    <div v-else-if="!rows.length" class="empty"><strong>На {{ year }} год отсутствий пока нет</strong><span>Создайте первое отсутствие.</span></div>
    <AbsenceTable v-else :items="rows" @action="emit('action', $event)" />
  </UiPanel>

  <div v-if="showForm" class="overlay" @click.self="showForm = false">
    <form class="modal" @submit.prevent="save">
      <div class="modal-head"><div><div class="eyebrow">НОВОЕ ОТСУТСТВИЕ</div><h2>Запланировать отсутствие</h2></div><button class="close" type="button" @click="showForm = false">×</button></div>
      <label class="irlix-field">Тип
        <select v-model="form.type"><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select>
      </label>
      <div class="date-grid">
        <label class="irlix-field">С<input v-model="form.starts_on" type="date" required /></label>
        <label class="irlix-field">По <small v-if="form.type === 'maternity_leave'">(можно оставить пустым)</small><input v-model="form.ends_on" type="date" :required="form.type !== 'maternity_leave'" /></label>
      </div>
      <label class="irlix-field">Комментарий<textarea v-model="form.comment" rows="4" maxlength="2000" placeholder="Необязательно" /></label>
      <p class="hint">После создания заявление и другие документы загружаются через меню ⋮ у отпуска. Отправка на согласование выполняется там же.</p>
      <div class="actions"><UiButton type="button" variant="secondary" @click="showForm = false">Отмена</UiButton><UiButton type="submit" :disabled="saving">{{ saving ? 'Сохраняем…' : 'Запланировать' }}</UiButton></div>
    </form>
  </div>
</template>
