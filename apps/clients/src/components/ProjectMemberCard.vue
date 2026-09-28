<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UiBadge, UiButton, UiDrawer, UiSearchSelect } from '@irlix/ui';
import MemberFeedbacks from './MemberFeedbacks.vue';

const props = defineProps({
  memberId: { type: Number, default: null },
  clients: { type: Array, default: () => [] },
  technologyOptions: { type: Array, default: () => [] },
  levels: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'changed']);

const loading = ref(false);
const saving = ref(false);
const error = ref('');
const member = ref(null);
const editing = reactive({});
const draft = reactive({});
const addingTerms = ref(false);
const newTerms = reactive({ technology:'', level:'', hourly_rate:'', hours_per_day:8, valid_from:'', valid_to:'' });

const projectOptions = computed(() => {
  const client = props.clients.find((item) => Number(item.id) === Number(member.value?.client_id));
  return (client?.projects || []).map((project) => ({
    value: String(project.id),
    label: project.displayName || project.name || 'Основной проект',
  }));
});

const levelOptions = computed(() => props.levels.map((level) => ({ value: level, label: level })));
const headerTitle = computed(() => {
  if (!member.value) return 'Подключение';
  return `${member.value.client_name} — ${member.value.project_display_name} — ${member.value.specialist_name}`;
});
const sortedTerms = computed(() => [...(member.value?.terms || [])].sort((a,b) => {
  const byDate = String(b.valid_from || '').localeCompare(String(a.valid_from || ''));
  return byDate || Number(b.id || 0) - Number(a.id || 0);
}));
const today = () => new Date().toISOString().slice(0,10);
const status = computed(() => {
  const now = today();
  return sortedTerms.value.some((term) => String(term.valid_from).slice(0,10) <= now && (!term.valid_to || String(term.valid_to).slice(0,10) >= now)) ? 'На проекте' : 'Работал ранее';
});

async function api(url, options = {}) {
  const response = await fetch(url, { ...options, headers:{ Accept:'application/json', 'Content-Type':'application/json', ...(options.headers || {}) } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

async function load() {
  if (!props.memberId) { member.value = null; return; }
  loading.value = true;
  error.value = '';
  try {
    member.value = (await api(`/api/clients/members/${props.memberId}`)).data;
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    loading.value = false;
  }
}

function begin(key, value) {
  editing[key] = true;
  draft[key] = value ?? '';
}
function cancel(key) {
  delete editing[key];
  delete draft[key];
}

async function saveMember(key) {
  if (!member.value || key !== 'project_id') return;
  await persist(`/api/clients/members/${member.value.id}`, { project_id:Number(draft[key]) }, key);
}

async function saveTerm(term, key) {
  let value = draft[`term:${term.id}:${key}`];
  if (['hourly_rate','hours_per_day'].includes(key)) value = Number(value);
  if (key === 'valid_to' && value === '') value = null;
  await persist(`/api/clients/terms/${term.id}`, { [key]: value }, `term:${term.id}:${key}`);
}

async function persist(url, payload, editKey) {
  saving.value = true;
  error.value = '';
  try {
    await api(url, { method:'PATCH', body:JSON.stringify(payload) });
    cancel(editKey);
    await load();
    emit('changed');
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

async function createTerms() {
  if (!member.value) return;
  saving.value = true;
  error.value = '';
  try {
    await api(`/api/clients/members/${member.value.id}/terms`, {
      method:'POST',
      body:JSON.stringify({
        technology:newTerms.technology,
        level:newTerms.level,
        hourly_rate:Number(newTerms.hourly_rate),
        hours_per_day:Number(newTerms.hours_per_day),
        valid_from:newTerms.valid_from,
        valid_to:newTerms.valid_to || null,
      }),
    });
    Object.assign(newTerms, { technology:'', level:'', hourly_rate:'', hours_per_day:8, valid_from:'', valid_to:'' });
    addingTerms.value = false;
    await load();
    emit('changed');
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

const formatDate = (value) => value ? new Date(`${String(value).slice(0,10)}T00:00:00`).toLocaleDateString('ru-RU') : '—';

watch(() => props.memberId, load, { immediate:true });
</script>

<template>
  <UiDrawer :open="!!memberId" :title="headerTitle" width="720px" @close="emit('close')">
    <div v-if="loading" class="member-card-state">Загрузка…</div>
    <template v-else-if="member">
      <div v-if="error" class="member-card-error">{{ error }}</div>

      <section class="member-card-section">
        <div class="section-head">
          <strong>Подключение</strong>
          <UiBadge :tone="status === 'На проекте' ? 'success' : 'neutral'">{{ status }}</UiBadge>
        </div>

        <div class="editable-row">
          <span class="editable-row__label">Проект</span>
          <template v-if="editing.project_id">
            <UiSearchSelect v-model="draft.project_id" class="editable-row__control" :options="projectOptions" :clearable="false" placeholder="Выберите проект" />
            <button class="row-action row-action--save" type="button" :disabled="saving" @click="saveMember('project_id')">✓</button>
            <button class="row-action" type="button" @click="cancel('project_id')">×</button>
          </template>
          <template v-else>
            <span class="editable-row__value">{{ member.project_display_name }}</span>
            <button class="row-action" type="button" aria-label="Редактировать проект" @click="begin('project_id', String(member.project_id))">✎</button>
          </template>
        </div>
      </section>

      <section class="member-card-section">
        <div class="section-head">
          <strong>Условия</strong>
          <button class="section-action" type="button" @click="addingTerms = !addingTerms">＋ новые условия</button>
        </div>

        <form v-if="addingTerms" class="terms-create" @submit.prevent="createTerms">
          <UiSearchSelect v-model="newTerms.technology" :options="technologyOptions" :clearable="false" placeholder="Технология" />
          <UiSearchSelect v-model="newTerms.level" :options="levelOptions" :clearable="false" placeholder="Грейд" />
          <input v-model="newTerms.hourly_rate" class="form-control" type="number" min="0" step="0.01" placeholder="Ставка, ₽/ч" required />
          <input v-model="newTerms.hours_per_day" class="form-control" type="number" min="0" max="24" step="0.5" placeholder="Загрузка, ч/д" required />
          <input v-model="newTerms.valid_from" class="form-control" type="date" required />
          <input v-model="newTerms.valid_to" class="form-control" type="date" />
          <div class="terms-create__actions"><UiButton type="submit" :disabled="saving">Сохранить</UiButton><UiButton type="button" variant="secondary" @click="addingTerms=false">Отмена</UiButton></div>
        </form>

        <article v-for="term in sortedTerms" :key="term.id" class="term-card">
          <div class="term-card__title"><strong>{{ formatDate(term.valid_from) }} — {{ term.valid_to ? formatDate(term.valid_to) : 'по настоящее время' }}</strong></div>

          <div class="term-pair">
            <div class="editable-field">
              <span class="editable-field__label">Технология</span>
              <template v-if="editing[`term:${term.id}:technology`]">
                <div class="editable-field__edit"><UiSearchSelect v-model="draft[`term:${term.id}:technology`]" :options="technologyOptions" :clearable="false" placeholder="Технология" /><button class="row-action row-action--save" type="button" :disabled="saving" @click="saveTerm(term,'technology')">✓</button><button class="row-action" type="button" @click="cancel(`term:${term.id}:technology`)">×</button></div>
              </template>
              <template v-else><div class="editable-field__display"><span>{{ term.technology }}</span><button class="row-action" type="button" aria-label="Редактировать технологию" @click="begin(`term:${term.id}:technology`,term.technology)">✎</button></div></template>
            </div>
            <div class="editable-field">
              <span class="editable-field__label">Грейд</span>
              <template v-if="editing[`term:${term.id}:level`]">
                <div class="editable-field__edit"><UiSearchSelect v-model="draft[`term:${term.id}:level`]" :options="levelOptions" :clearable="false" placeholder="Грейд" /><button class="row-action row-action--save" type="button" :disabled="saving" @click="saveTerm(term,'level')">✓</button><button class="row-action" type="button" @click="cancel(`term:${term.id}:level`)">×</button></div>
              </template>
              <template v-else><div class="editable-field__display"><span>{{ term.level }}</span><button class="row-action" type="button" aria-label="Редактировать грейд" @click="begin(`term:${term.id}:level`,term.level)">✎</button></div></template>
            </div>
          </div>

          <div class="term-pair">
            <div class="editable-field">
              <span class="editable-field__label">Ставка, ₽/ч</span>
              <template v-if="editing[`term:${term.id}:hourly_rate`]">
                <div class="editable-field__edit"><input v-model="draft[`term:${term.id}:hourly_rate`]" class="form-control" type="number" min="0" step="0.01" /><button class="row-action row-action--save" type="button" :disabled="saving" @click="saveTerm(term,'hourly_rate')">✓</button><button class="row-action" type="button" @click="cancel(`term:${term.id}:hourly_rate`)">×</button></div>
              </template>
              <template v-else><div class="editable-field__display"><span>{{ term.hourly_rate }}</span><button class="row-action" type="button" aria-label="Редактировать ставку" @click="begin(`term:${term.id}:hourly_rate`,term.hourly_rate)">✎</button></div></template>
            </div>
            <div class="editable-field">
              <span class="editable-field__label">Загрузка, ч/д</span>
              <template v-if="editing[`term:${term.id}:hours_per_day`]">
                <div class="editable-field__edit"><input v-model="draft[`term:${term.id}:hours_per_day`]" class="form-control" type="number" min="0" max="24" step="0.5" /><button class="row-action row-action--save" type="button" :disabled="saving" @click="saveTerm(term,'hours_per_day')">✓</button><button class="row-action" type="button" @click="cancel(`term:${term.id}:hours_per_day`)">×</button></div>
              </template>
              <template v-else><div class="editable-field__display"><span>{{ term.hours_per_day }}</span><button class="row-action" type="button" aria-label="Редактировать загрузку" @click="begin(`term:${term.id}:hours_per_day`,term.hours_per_day)">✎</button></div></template>
            </div>
          </div>

          <div class="term-pair">
            <div class="editable-field">
              <span class="editable-field__label">Начало</span>
              <template v-if="editing[`term:${term.id}:valid_from`]">
                <div class="editable-field__edit"><input v-model="draft[`term:${term.id}:valid_from`]" class="form-control" type="date" /><button class="row-action row-action--save" type="button" :disabled="saving" @click="saveTerm(term,'valid_from')">✓</button><button class="row-action" type="button" @click="cancel(`term:${term.id}:valid_from`)">×</button></div>
              </template>
              <template v-else><div class="editable-field__display"><span>{{ formatDate(term.valid_from) }}</span><button class="row-action" type="button" aria-label="Редактировать дату начала" @click="begin(`term:${term.id}:valid_from`,String(term.valid_from).slice(0,10))">✎</button></div></template>
            </div>
            <div class="editable-field">
              <span class="editable-field__label">Окончание</span>
              <template v-if="editing[`term:${term.id}:valid_to`]">
                <div class="editable-field__edit"><input v-model="draft[`term:${term.id}:valid_to`]" class="form-control" type="date" /><button class="row-action row-action--save" type="button" :disabled="saving" @click="saveTerm(term,'valid_to')">✓</button><button class="row-action" type="button" @click="cancel(`term:${term.id}:valid_to`)">×</button></div>
              </template>
              <template v-else><div class="editable-field__display"><span>{{ term.valid_to ? formatDate(term.valid_to) : 'Без окончания' }}</span><button class="row-action" type="button" aria-label="Редактировать дату окончания" @click="begin(`term:${term.id}:valid_to`,term.valid_to ? String(term.valid_to).slice(0,10) : '')">✎</button></div></template>
            </div>
          </div>
        </article>
      </section>

      <MemberFeedbacks :member-id="Number(member.id)" />
    </template>
  </UiDrawer>
</template>

<style scoped>
.member-card-state{padding:18px;color:#737b85}.member-card-error{margin:0 0 12px;padding:10px 12px;border:1px solid #f0b7b7;border-radius:8px;background:#fff5f5;color:#b42318;font-size:12px}.member-card-section{margin:22px 0 0}.section-head{min-height:34px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 0 8px;border-bottom:1px solid #dfe3e7}.section-head strong{font-size:14px;font-weight:600;color:#303841}.section-action{border:0;background:transparent;color:#078d6c;font-size:11px;font-weight:600;cursor:pointer}.editable-row{min-height:42px;display:grid;grid-template-columns:150px minmax(0,1fr) 28px 28px;gap:6px;align-items:center;border-bottom:1px solid #eef0f2}.editable-row__label,.editable-field__label{font-size:11px;color:#737b85}.editable-row__value{min-width:0;font-size:13px;color:#222a32}.editable-row__control{grid-column:2;min-width:0}.row-action{width:26px;height:26px;padding:0;border:0;border-radius:6px;background:transparent;color:#69727c;cursor:pointer;flex:0 0 26px}.row-action:hover{background:#f1f4f5}.row-action--save{color:#078d6c}.form-control{height:var(--irlix-control-height,32px);width:100%;padding:0 var(--irlix-control-padding-x,12px);border:1px solid var(--irlix-control-border,#dde1e7);border-radius:var(--irlix-control-radius,10px);background:#fff;color:var(--irlix-control-text,#4f5967);font:inherit;outline:0;box-sizing:border-box}.form-control:focus{border-color:var(--irlix-color-primary);box-shadow:0 0 0 2px rgba(18,184,144,.1)}.terms-create{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 0;border-bottom:1px solid #e6e9ec}.terms-create__actions{grid-column:1/-1;display:flex;gap:8px}.term-card{margin-top:10px;border:1px solid #e3e6e9;border-radius:9px;overflow:hidden;background:#fff}.term-card__title{min-height:34px;display:flex;align-items:center;padding:0 10px;background:#f7f8f9;border-bottom:1px solid #e8eaed;font-size:12px}.term-pair{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #eef0f2}.term-pair:last-child{border-bottom:0}.editable-field{min-width:0;padding:9px 10px}.editable-field+ .editable-field{border-left:1px solid #eef0f2}.editable-field__label{display:block;margin-bottom:4px}.editable-field__display,.editable-field__edit{min-height:30px;display:flex;align-items:center;gap:4px}.editable-field__display>span{min-width:0;flex:1;font-size:13px;color:#222a32}.editable-field__edit>:first-child{min-width:0;flex:1}@media(max-width:720px){.editable-row{grid-template-columns:110px minmax(0,1fr) 28px 28px}.terms-create,.term-pair{grid-template-columns:1fr}.editable-field+ .editable-field{border-left:0;border-top:1px solid #eef0f2}}
</style>
