<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { UiButton, UiDrawer, UiFilterBar, UiIcon, UiSearchSelect, UiTreeToggle, UiLoader } from '@irlix/ui';
import PartnerProfileSections from './PartnerProfileSections.vue';

const props = defineProps({ canManage: { type: Boolean, default: false } });
const data = ref({ partners: [], unassigned: [] });
const query = ref('');
const activity = ref('all');
const expanded = reactive(new Set());
const selected = ref(null);
const editingField = ref('');
const draft = reactive({ name: '', location: '', vat_payer: false, description: '', full_name: '', partner_id: '', comment: '', active: true });
const error = ref('');
const loading = ref(false);
const saving = ref(false);
const isNew = computed(() => selected.value && !selected.value.id);
const isPartner = computed(() => selected.value?.mode === 'partner');
const title = computed(() => !selected.value ? '' : isPartner.value
  ? (isNew.value ? 'Новый партнёр' : 'Карточка партнёра')
  : (isNew.value ? 'Новый специалист' : 'Карточка специалиста'));
const activityOptions = [{ value: 'all', label: 'Все статусы' }, { value: 'active', label: 'Активные' }, { value: 'inactive', label: 'Неактивные' }];
const partnerOptions = computed(() => data.value.partners.map(p => ({ value: String(p.id), label: p.name })));
const partnerFields = [
 { key: 'name', label: 'Наименование партнёра' },
 { key: 'location', label: 'Локация (город)' },
 { key: 'vat_payer', label: 'НДС', kind: 'boolean' },
 { key: 'description', label: 'Описание / комментарий', kind: 'textarea' },
 { key: 'active', label: 'Активность', kind: 'boolean' },
];
const specialistFields = [
 { key: 'partner_id', label: 'Партнёр', kind: 'partner' },
 { key: 'full_name', label: 'ФИО' },
 { key: 'comment', label: 'Комментарий', kind: 'textarea' },
 { key: 'active', label: 'Активность', kind: 'boolean' },
];
const fields = computed(() => isPartner.value ? partnerFields : specialistFields);
const groups = computed(() => {
 const all = [...data.value.partners];
 if (data.value.unassigned?.length) all.push({ id: 'unassigned', name: 'Без партнёра', active: true, specialists: data.value.unassigned });
 const needle = query.value.trim().toLocaleLowerCase('ru');
 const statusMatches = row => activity.value === 'all' || Boolean(Number(row.active)) === (activity.value === 'active');
 return all.map(p => {
  const people = (p.specialists || []).filter(s => statusMatches(s));
  const partnerMatches = statusMatches(p) && (!needle || p.name.toLocaleLowerCase('ru').includes(needle));
  const matchingPeople = people.filter(s => !needle || s.full_name.toLocaleLowerCase('ru').includes(needle));
  return { ...p, specialists: partnerMatches ? people : matchingPeople, visible: partnerMatches || matchingPeople.length > 0 };
 }).filter(p => p.visible);
});
async function api(url, options = {}) {
 const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) } });
 const body = await response.json().catch(() => ({}));
 if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || 'HTTP ' + response.status);
 return body.data;
}
async function load() {
 loading.value = true;
 try { data.value = await api('/api/clients/partners'); }
 catch (e) { error.value = e.message; }
 finally { loading.value = false; }
}
function open(mode, row = null, partnerId = null) {
 selected.value = { mode, id: row?.id ?? null };
 editingField.value = '';
 error.value = '';
 Object.assign(draft, {
  name: row?.name || '', location: row?.location || '', vat_payer: Boolean(Number(row?.vat_payer || 0)),
  description: row?.description || '', full_name: row?.full_name || '',
  partner_id: String(row?.partner_id ?? partnerId ?? ''), comment: row?.comment || '',
  active: row?.active === undefined ? true : Boolean(Number(row.active)),
 });
}
function fieldValue(field) {
 if (field.key === 'partner_id') return partnerOptions.value.find(p => p.value === draft.partner_id)?.label || '—';
 if (field.key === 'vat_payer') return draft.vat_payer ? 'Да' : 'Нет';
 if (field.key === 'active') return draft.active ? 'Активен' : 'Неактивен';
 return draft[field.key] || '—';
}
function normalizeField(key) {
 if (key === 'name' || key === 'full_name') return String(draft[key] || '').trim();
 if (key === 'partner_id') return Number(draft[key]);
 if (key === 'active' || key === 'vat_payer') return Boolean(draft[key]);
 return String(draft[key] || '').trim() || null;
}
async function save(key = null) {
 if (!selected.value || !props.canManage || saving.value) return;
 const mode = selected.value.mode;
 const partner = mode === 'partner';
 const base = partner ? '/api/clients/partners' : '/api/clients/partner-specialists';
 const id = selected.value.id;
 const keys = !id
  ? (partner ? partnerFields : specialistFields).map(field => field.key)
  : [key || editingField.value];
 if (!keys[0]) return;
 const payload = Object.fromEntries(keys.map(field => [field, normalizeField(field)]));
 saving.value = true;
 error.value = '';
 try {
  const saved = await api(id ? base + '/' + id : base, { method: id ? 'PATCH' : 'POST', body: JSON.stringify(payload) });
  await load();
  const current = partner
   ? data.value.partners.find(p => Number(p.id) === Number(saved.id))
   : [...data.value.partners.flatMap(p => p.specialists || []), ...data.value.unassigned].find(s => Number(s.id) === Number(saved.id));
  open(mode, current || saved);
  if (partner) expanded.add(saved.id);
 } catch (e) { error.value = e.message; }
 finally { saving.value = false; }
}
function cancel() {
 if (!selected.value?.id) { selected.value = null; return; }
 const id = selected.value.id;
 const row = isPartner.value ? data.value.partners.find(p => Number(p.id) === Number(id))
   : [...data.value.partners.flatMap(p => p.specialists || []), ...data.value.unassigned].find(s => Number(s.id) === Number(id));
 if (row) open(selected.value.mode, row);
 else selected.value = null;
}
onMounted(load);
</script>

<template>
 <section class="partners-registry">
  <UiFilterBar class="partners-filterbar">
   <input v-model="query" class="registry-search" type="search" placeholder="Поиск партнёра или специалиста" aria-label="Поиск партнёров">
   <UiSearchSelect v-model="activity" :options="activityOptions" placeholder="Активность" :clearable="false" aria-label="Статус партнёра или специалиста" />
   <UiButton v-if="canManage" @click="open('partner')">＋ Партнёр</UiButton>
  </UiFilterBar>
  <div v-if="error && !selected" class="error-banner">{{ error }} <button @click="load">Повторить</button></div>
  <UiLoader v-if="loading" page label="Загрузка партнёров" />
  <div v-else class="partner-tree">
   <div v-for="partner in groups" :key="partner.id" class="partner-group">
    <div class="partner-parent">
     <UiTreeToggle variant="plus" :expanded="expanded.has(partner.id)" :label="expanded.has(partner.id) ? 'Свернуть' : 'Развернуть'" @click="expanded.has(partner.id) ? expanded.delete(partner.id) : expanded.add(partner.id)" />
     <button type="button" class="partner-name" :disabled="partner.id === 'unassigned'" @click="open('partner', partner)"><strong>{{ partner.name }}</strong> <small>({{ partner.specialists.length }})</small></button>
     <span v-if="!Number(partner.active)" class="partner-muted">Неактивен</span>
     <UiButton v-if="canManage && partner.id !== 'unassigned'" compact @click="open('specialist', null, partner.id)">＋ Специалист</UiButton>
    </div>
    <template v-if="expanded.has(partner.id) || query.trim()">
     <div v-for="s in partner.specialists" :key="s.id" class="partner-child">
      <button class="partner-name" type="button" @click="open('specialist', s)">{{ s.full_name }}</button>
      <span v-if="!Number(s.active)" class="partner-muted">Неактивен</span>
     </div>
     <div v-if="!partner.specialists.length" class="partner-child partner-muted">Специалистов пока нет</div>
    </template>
   </div>
   <p v-if="!groups.length" class="partner-muted partner-empty">Ничего не найдено</p>
  </div>
  <UiDrawer :open="!!selected" :title="title" width="560px" @close="selected=null">
   <div v-if="selected" class="partner-card">
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <div class="partner-fields">
     <article v-for="field in fields" :key="field.key" class="partner-field">
      <span class="partner-field__label">{{ field.label }}</span>
      <div class="partner-field__value">
       <template v-if="isNew || editingField === field.key">
        <label v-if="field.kind === 'boolean'" class="partner-field__check">
         <input v-model="draft[field.key]" type="checkbox" :disabled="saving">
         {{ draft[field.key] ? 'Да' : 'Нет' }}
        </label>
        <UiSearchSelect v-else-if="field.kind === 'partner'" v-model="draft.partner_id" aria-label="Партнёр" :options="partnerOptions" :clearable="false" placeholder="Партнёр" />
        <textarea v-else-if="field.kind === 'textarea'" v-model="draft[field.key]" rows="3" :aria-label="field.label" :disabled="saving" />
        <input v-else v-model="draft[field.key]" type="text" maxlength="255" :disabled="saving" :aria-label="field.label" />
        <span v-if="!isNew" class="partner-field__actions">
         <button type="button" aria-label="Сохранить поле" :disabled="saving" @click="save(field.key)">✓</button>
         <button type="button" aria-label="Отменить изменение" :disabled="saving" @click="cancel">×</button>
        </span>
       </template>
       <template v-else>
        <span class="partner-field__text">{{ fieldValue(field) }}</span>
        <button v-if="canManage" class="partner-field__edit" type="button" :aria-label="'Редактировать: ' + field.label" :disabled="saving" @click="editingField = field.key"><UiIcon name="pencil" /></button>
       </template>
      </div>
     </article>
    </div>
    <PartnerProfileSections v-if="isPartner && !isNew" :key="selected.id" :partner-id="Number(selected.id)" :can-manage="canManage" />
    <div v-if="isNew && canManage" class="partner-actions">
     <UiButton :disabled="saving" @click="save()">Сохранить</UiButton>
     <UiButton variant="secondary" :disabled="saving" @click="cancel()">Отмена</UiButton>
    </div>
   </div>
  </UiDrawer>
 </section>
</template>

<style scoped>
.partners-registry { padding:0; min-width:0; }
.partners-filterbar { padding:8px 12px; }
.partner-tree { margin-top:0; border-top:1px solid var(--irlix-color-border); }
.partner-parent,.partner-child { display:flex; align-items:center; gap:12px; min-height:44px; padding:6px 12px; border-bottom:1px solid var(--irlix-color-border); }
.partner-parent { background:transparent; }
.partner-child { padding-left:48px; }
.partner-name { border:0; background:none; text-align:left; cursor:pointer; color:inherit; font:inherit; }
.partner-name:hover { text-decoration:underline; }
.partner-name:disabled { cursor:default; text-decoration:none; }
.partner-parent .partner-name { flex:1; }
.partner-muted,.partner-name small { color:var(--irlix-color-text-muted); font-size:var(--irlix-font-size-caption,12px); }
.partner-empty { padding:14px; }
.partner-card { padding:12px 16px; }
.partner-fields { display:grid; gap:0; }
.partner-field { display:grid; grid-template-columns:170px minmax(0,1fr); gap:14px; align-items:start; padding:12px 0; border-bottom:1px solid var(--irlix-color-border); }
.partner-field__label { color:var(--irlix-color-text-muted); font-size:var(--irlix-font-size-caption,12px); padding-top:7px; }
.partner-field__value { display:flex; align-items:center; gap:8px; min-width:0; min-height:32px; }
.partner-field__text { flex:1; white-space:pre-wrap; overflow-wrap:anywhere; }
.partner-field__value > input,.partner-field__value > textarea { flex:1; width:100%; min-width:0; padding:7px 9px; border:1px solid var(--irlix-color-border); border-radius:var(--irlix-control-radius,8px); background:var(--irlix-color-surface); color:var(--irlix-color-text); font:inherit; }
.partner-field__check { display:flex; gap:8px; align-items:center; }
.partner-field__edit,.partner-field__actions button { border:0; background:none; padding:4px; border-radius:6px; cursor:pointer; color:var(--irlix-color-text-muted); }
.partner-field__edit:hover,.partner-field__actions button:hover { background:var(--irlix-color-surface-muted); }
.partner-field__edit :deep(svg) { width:15px; height:15px; }
.partner-field__actions { display:flex; flex:none; gap:4px; }
.partner-field__actions button { font-size:18px; }
.partner-field__actions button:first-child { color:var(--irlix-color-primary-text); }
.partner-actions { display:flex; gap:8px; padding:16px 0; }
@media(max-width:520px) { .partner-field { grid-template-columns:1fr; gap:2px; } .partner-field__label { padding:0; } }
</style>
