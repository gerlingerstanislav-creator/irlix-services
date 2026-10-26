<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { UiButton, UiDrawer, UiFilterBar, UiIcon, UiSearchSelect, UiTreeToggle, UiLoader } from '@irlix/ui';
import PartnerProfileSections from './PartnerProfileSections.vue';

const props = defineProps({ canManage: { type: Boolean, default: false }, canManageContacts: { type: Boolean, default: false } });
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
 { key: 'active', label: 'Активность', kind: 'boolean' },
 { key: 'description', label: 'Описание / комментарий', kind: 'textarea' },
];
const specialistFields = [
 { key: 'full_name', label: 'ФИО' },
 { key: 'partner_id', label: 'Партнёр', kind: 'partner' },
 { key: 'active', label: 'Активность', kind: 'boolean' },
 { key: 'comment', label: 'Комментарий', kind: 'textarea' },
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
defineExpose({ openCreatePartner: () => open('partner') });
</script>

<template>
 <section class="partners-registry">
  <UiFilterBar class="partners-filterbar">
   <input v-model="query" class="registry-search partner-search" type="search" placeholder="Поиск партнёра или специалиста" aria-label="Поиск партнёров">
   <div class="partners-filterbar__activity"><UiSearchSelect v-model="activity" :options="activityOptions" placeholder="Активность" :clearable="false" aria-label="Статус партнёра или специалиста" /></div>
  </UiFilterBar>
  <div v-if="error && !selected" class="error-banner">{{error}} <button @click="load">Повторить</button></div>
  <UiLoader v-if="loading" page label="Загрузка партнёров" />
  <div v-else class="partner-tree clients-registry">
   <div v-for="partner in groups" :key="partner.id" class="partner-group" :class="{'partner-group--expanded': expanded.has(partner.id) || !!query.trim()}">
    <div class="partner-parent client-row">
     <span class="partner-parent__main">
      <UiTreeToggle :expanded="expanded.has(partner.id) || !!query.trim()" :label="expanded.has(partner.id) ? 'Свернуть партнёра' : 'Развернуть партнёра'" @click="expanded.has(partner.id) ? expanded.delete(partner.id) : expanded.add(partner.id)" />
      <button type="button" class="partner-name" :disabled="partner.id === 'unassigned'" @click="open('partner',partner)"><strong>{{partner.name}}</strong></button>
      <span v-if="partner.location" class="partner-meta">{{partner.location}}</span>
      <span class="partner-count">{{partner.specialists.length}} специалистов</span>
     </span>
     <span v-if="!Number(partner.active)" class="partner-muted">Неактивен</span>
     <UiButton v-if="canManage && partner.id !== 'unassigned'" compact @click="open('specialist',null,partner.id)">＋ Специалист</UiButton>
    </div>
    <template v-if="expanded.has(partner.id) || query.trim()">
     <div v-for="s in partner.specialists" :key="s.id" class="partner-child partner-child--connected client-row">
      <span class="partner-child__name"><button class="partner-name" type="button" @click="open('specialist',s)">{{s.full_name}}</button></span>
      <span v-if="s.comment" class="partner-muted partner-comment">{{s.comment}}</span>
      <span v-if="!Number(s.active)" class="partner-muted">Неактивен</span>
     </div>
     <div v-if="!partner.specialists.length" class="partner-child partner-child--empty partner-muted">Специалистов пока нет</div>
    </template>
   </div>
   <p v-if="!groups.length" class="partner-muted partner-empty">Ничего не найдено</p>
  </div>
  <div v-if="selected && isPartner && !isNew" class="partner-card-overlay" @mousedown.self="selected=null">
   <section class="partner-card-drawer" role="dialog" aria-modal="true" aria-label="Карточка партнёра">
    <header class="partner-card-breadcrumbs">
     <span>Партнёры</span><span class="partner-card-separator">›</span>
     <strong>{{draft.name || 'Партнёр'}}</strong>
     <button class="partner-card-close" type="button" aria-label="Закрыть карточку партнёра" @click="selected=null">×</button>
    </header>
    <div class="partner-card-hero">
     <div class="partner-card-hero__identity"><span class="partner-card-avatar">{{(draft.name||'П').slice(0,1).toUpperCase()}}</span>
      <div><strong>{{draft.name}}</strong><small>{{draft.location || 'Локация не указана'}}</small></div>
     </div>
    </div>
    <p v-if="error" class="error-banner" role="alert">{{error}}</p>
    <p v-if="!canManage" class="partner-readonly">Только просмотр: для редактирования карточки требуется право управления справочником.</p>
    <PartnerProfileSections :key="selected.id" :partner-id="Number(selected.id)" :can-manage="canManage" :can-manage-contacts="canManageContacts">
     <template #about><div class="partner-fields" :class="{'partner-about-fields':isPartner}">
      <article v-for="field in fields" :key="field.key" class="partner-field" :class="{'partner-field--wide':field.kind==='textarea'}">
       <span class="partner-field__label">{{field.label}}</span>
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
     </div></template>
    </PartnerProfileSections>
   </section>
  </div>
  <UiDrawer :open="!!selected && (!isPartner || isNew)" :title="title" width="560px" @close="selected=null">
   <div v-if="selected && (!isPartner || isNew)" class="partner-card partner-card--compact">
    <p v-if="error" class="error-banner" role="alert">{{error}}</p>
    <p v-if="!canManage && !isNew" class="partner-readonly">Только просмотр: для редактирования карточки требуется право управления справочником.</p>
    <div class="partner-fields" :class="{'partner-about-fields':isPartner,'partner-fields--specialist':!isPartner}">
      <article v-for="field in fields" :key="field.key" class="partner-field" :class="{'partner-field--wide':field.kind==='textarea' || (!isPartner && field.key==='full_name')}">
       <span class="partner-field__label">{{field.label}}</span>
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
    <div v-if="isNew && canManage" class="partner-actions">
     <UiButton :disabled="saving" @click="save()">Сохранить</UiButton>
     <UiButton variant="secondary" :disabled="saving" @click="cancel()">Отмена</UiButton>
    </div>
   </div>
  </UiDrawer>
 </section>
</template>
<style scoped>
.partners-registry{padding:0;min-width:0}
.partners-filterbar{display:flex;align-items:center;gap:8px;padding:8px 12px}
.partner-search{box-sizing:border-box;flex:0 0 240px;width:240px;min-width:0;max-width:240px}
.partners-filterbar__activity{flex:0 0 165px;width:165px;min-width:0}
.partners-filterbar__activity :deep(.ui-search-select){width:100%;min-width:0}
.partner-tree{width:100%;min-width:0;overflow:auto;border-top:1px solid var(--irlix-color-border)}
.partner-group{margin:0;border-bottom:1px solid var(--irlix-color-border)}
.partner-parent.client-row{position:relative;display:flex;align-items:center;gap:12px;padding:4px 12px;min-height:45px;background:var(--irlix-color-surface);border-bottom:1px solid var(--irlix-color-border)}
.partner-parent__main{display:flex;align-items:center;gap:8px;flex:1;min-width:0}
.partner-parent__main .partner-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.partner-group--expanded:has(>.partner-child--connected)>.partner-parent:after{content:'';position:absolute;left:24px;top:50%;bottom:0;border-left:1px dashed var(--irlix-color-border);pointer-events:none}
.partner-child.client-row{position:relative;display:flex;align-items:center;gap:12px;min-height:40px;padding:5px 12px 5px 46px;background:var(--irlix-color-surface);border-bottom:1px solid var(--irlix-color-border)}
.partner-child--connected:before{content:'';position:absolute;left:24px;top:0;bottom:0;border-left:1px dashed var(--irlix-color-border);pointer-events:none}
.partner-child--connected:last-child:before{bottom:50%}
.partner-child--connected:after{content:'';position:absolute;left:24px;top:50%;width:16px;border-top:1px dashed var(--irlix-color-border);pointer-events:none}
.partner-child--empty{min-height:44px;padding:11px 12px 11px 46px}
.partner-child__name{display:flex;align-items:center;flex:1;min-width:0}
.partner-child__name .partner-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.partner-name{border:0;background:none;text-align:left;cursor:pointer;color:inherit;font:inherit}
.partner-name:hover{text-decoration:underline}
.partner-name:disabled{cursor:default;text-decoration:none}
.partner-meta,.partner-count,.partner-muted{font-size:var(--irlix-font-size-caption,12px);color:var(--irlix-color-text-muted);white-space:nowrap}
.partner-count{margin-left:4px}
.partner-comment{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:25%}
.partner-empty{padding:14px}
.partner-card-overlay{position:fixed;inset:0;z-index:1150;background:color-mix(in srgb,var(--irlix-color-surface-muted) 28%,transparent);display:flex;justify-content:flex-end}
.partner-card-drawer{position:relative;width:50vw;max-width:90vw;height:100%;overflow:hidden;font-size:var(--irlix-font-size-body,14px);background:var(--irlix-color-surface);border-left:1px solid var(--irlix-color-border);box-shadow:-18px 0 50px color-mix(in srgb,var(--irlix-color-shadow-base) 18%,transparent);display:flex;flex-direction:column;container-type:inline-size}
.partner-card-breadcrumbs{flex:none;display:flex;align-items:center;gap:10px;padding:0 16px;min-height:var(--irlix-topbar-height);border-bottom:1px solid var(--irlix-color-border);font-size:var(--irlix-font-size-caption)}
.partner-card-breadcrumbs strong{padding:4px 8px;border-radius:5px;background:var(--irlix-color-surface-muted);color:var(--irlix-color-primary-text)}
.partner-card-separator{color:var(--irlix-color-text-muted)}
.partner-card-close{margin-left:auto;width:30px;height:30px;border:0;background:transparent;font-size:var(--irlix-font-size-page-title);cursor:pointer}
.partner-card-hero{flex:none;margin:12px 10px 10px;padding:0;background:transparent;min-height:0}
.partner-card-hero__identity{display:flex;align-items:center;gap:14px;min-height:55px;padding:0 16px}
.partner-card-hero__identity>div{display:grid;gap:4px}
.partner-card-hero__identity strong{font-size:var(--irlix-font-size-section-title)}
.partner-card-hero__identity small{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption)}
.partner-card-avatar{width:38px;height:38px;border-radius:9px;background:var(--irlix-color-surface-muted);display:grid;place-items:center;font-weight:700}
.partner-readonly{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-caption);padding:7px 18px;margin:0}
.partner-card--compact{padding:0 0 16px}
.partner-fields{display:grid;gap:0;align-content:start}
.partner-about-fields{grid-template-columns:repeat(2,minmax(0,1fr));gap:0 24px}
.partner-fields--specialist{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px}
.partner-fields--specialist .partner-field{min-height:0;padding:0;gap:4px}
.partner-fields--specialist .partner-field__value{min-height:30px;line-height:1.35}
.partner-field--wide{grid-column:1/-1}
.partner-field{display:grid;grid-template-columns:minmax(0,1fr);gap:5px;min-width:0;min-height:58px;padding:10px 0}
.partner-field__label{color:var(--irlix-color-text-muted);font-size:var(--irlix-font-size-table,12px)}
.partner-field__value{display:flex;align-items:center;gap:8px;min-width:0;min-height:34px}
.partner-field__text{overflow-wrap:anywhere;white-space:pre-wrap}
.partner-field__value>input,.partner-field__value>textarea{box-sizing:border-box;flex:1;width:100%;min-width:0;min-height:34px;padding:7px 10px;border:1px solid var(--irlix-color-border);border-radius:8px;background:var(--irlix-color-surface);color:var(--irlix-color-text);font:inherit}
.partner-field__check{display:flex;align-items:center;gap:8px}
.partner-field__edit{display:inline-flex;align-items:center;justify-content:center;flex:none;width:28px;height:28px;padding:4px;border:0;background:transparent;color:var(--irlix-color-text);cursor:pointer}
.partner-field__edit :deep(svg){width:16px;height:16px;stroke:currentColor;stroke-width:1.8}
.partner-field__actions{display:flex;flex:none;gap:4px}
.partner-field__actions button{width:27px;height:27px;border:1px solid var(--irlix-color-border);border-radius:6px;background:var(--irlix-color-surface);color:var(--irlix-color-primary-text);cursor:pointer}
.partner-actions{display:flex;gap:8px;padding:16px 0}
@container(max-width:680px){.partner-about-fields{grid-template-columns:1fr}}
@media(max-width:700px){.partner-card-drawer{width:100vw;max-width:100vw}}
@media(max-width:520px){.partner-fields--specialist{grid-template-columns:1fr}}
</style>
