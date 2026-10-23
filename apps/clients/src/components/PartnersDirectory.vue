<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { UiButton, UiDrawer, UiFilterBar, UiSearchSelect, UiTreeToggle } from '@irlix/ui';

const props = defineProps({
  canManage: { type: Boolean, default: false },
  canConnect: { type: Boolean, default: false },
  clients: { type: Array, default: () => [] },
  technologies: { type: Array, default: () => [] },
  levels: { type: Array, default: () => [] },
});
const emit = defineEmits(['changed']);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const search = ref('');
const partnerFilter = ref('');
const partners = ref([]);
const unassigned = ref([]);
const expanded = reactive(new Set());
const kind = ref('');
const item = ref(null);
const creating = ref(false);
const editing = ref('');
const editValue = ref('');
const form = reactive({});
const connecting = ref(false);
const connection = reactive({ client_id:'', technology:'', level:'', hourly_rate:'', hours_per_day:8, valid_from:'', valid_to:'' });
const clientOptions = computed(() => props.clients.map(c => ({ value:String(c.id), label:c.name })).sort((a,b)=>a.label.localeCompare(b.label,'ru')));
const partnerFields = [
  { key: 'name', label: 'Название', required: true },
  { key: 'contact_name', label: 'Контактное лицо' },
  { key: 'phone', label: 'Телефон' },
  { key: 'email', label: 'E-mail', type: 'email' },
  { key: 'comment', label: 'Комментарий', multiline: true },
];
const specialistFields = [
  { key: 'full_name', label: 'ФИО', required: true },
  { key: 'partner_id', label: 'Партнёр', select: true },
  { key: 'specialization', label: 'Специализация' },
  { key: 'comment', label: 'Комментарий', multiline: true },
];
const fields = computed(() => kind.value === 'partner' ? partnerFields : specialistFields);
const title = computed(() => creating.value
  ? (kind.value === 'partner' ? 'Новый партнёр' : 'Новый специалист')
  : (kind.value === 'partner' ? item.value?.name : item.value?.full_name) || 'Карточка');
const options = computed(() => partners.value.map(p => ({ value: String(p.id), label: p.name })));
const lower = value => String(value || '').toLocaleLowerCase('ru');
const visible = computed(() => {
  const query = lower(search.value.trim());
  const result = partners.value.map(p => {
    const matchesCompany = lower([p.name, p.contact_name, p.email].join(' ')).includes(query);
    const specialists = (p.specialists || []).filter(s => matchesCompany ||
      lower([s.full_name, s.specialization].join(' ')).includes(query));
    return { ...p, specialists, visible: matchesCompany || specialists.length > 0 };
  }).filter(p => p.visible && (!partnerFilter.value || String(p.id) === partnerFilter.value));
  const orphan = unassigned.value.filter(s => (!partnerFilter.value || partnerFilter.value === 'unassigned')
      && lower([s.full_name, s.specialization].join(' ')).includes(query));
  return { result, orphan };
});
const groupOptions = computed(() => [
  ...options.value,
  ...(unassigned.value.length ? [{ value: 'unassigned', label: 'Без партнёра' }] : []),
]);
async function api(url, opts = {}) {
  const response = await fetch(url, { ...opts, headers: {
    Accept: 'application/json', 'Content-Type': 'application/json', ...(opts.headers || {}),
  } });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(', ') || 'HTTP ' + response.status);
  return data.data;
}
async function load() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api('/api/clients/partners');
    partners.value = data.partners || [];
    unassigned.value = data.unassigned || [];
  } catch (e) { error.value = e.message || String(e); }
  finally { loading.value = false; }
}
function toggle(id) { expanded.has(id) ? expanded.delete(id) : expanded.add(id); }
function open(kindValue, record) {
  kind.value = kindValue;
  item.value = record;
  creating.value = false;
  editing.value = '';
  connecting.value = false;
  error.value = '';
}
function close() { item.value = null; kind.value = ''; creating.value = false; editing.value = ''; connecting.value = false; }
function create(kindValue, partnerId = '') {
  kind.value = kindValue;
  item.value = null;
  creating.value = true;
  connecting.value = false;
  editing.value = '';
  error.value = '';
  Object.keys(form).forEach(key => delete form[key]);
  Object.assign(form, kindValue === 'partner' ? {
    name: '', contact_name: '', phone: '', email: '', comment: '',
  } : { partner_id: String(partnerId), full_name: '', specialization: '', comment: '' });
}
function edit(field) {
  if (!props.canManage) return;
  editing.value = field.key;
  editValue.value = item.value?.[field.key] == null ? '' : String(item.value[field.key]);
}
async function saveField(field) {
  if (!props.canManage || !item.value || saving.value) return;
  saving.value = true;
  error.value = '';
  try {
    const path = kind.value === 'partner'
      ? `/api/clients/partners/${item.value.id}`
      : `/api/clients/partner-specialists/${item.value.id}`;
    const value = field.select ? Number(editValue.value) : (editValue.value.trim() || null);
    const data = await api(path, { method: 'PATCH', body: JSON.stringify({ [field.key]: value }) });
    item.value = { ...item.value, ...data };
    editing.value = '';
    await load();
    emit('changed');
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}
async function submitCreate() {
  if (!props.canManage || saving.value) return;
  saving.value = true;
  error.value = '';
  try {
    const path = kind.value === 'partner'
      ? '/api/clients/partners'
      : `/api/clients/partners/${Number(form.partner_id)}/specialists`;
    const payload = { ...form };
    delete payload.partner_id;
    const created = await api(path, { method: 'POST', body: JSON.stringify(payload) });
    if (kind.value === 'partner') expanded.add(Number(created.id));
    else expanded.add(Number(form.partner_id));
    creating.value = false;
    item.value = created;
    await load();
    emit('changed');
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}
function beginConnection() {
  connecting.value = true;
  error.value = '';
  Object.assign(connection, {
    client_id:'', technology:'', level:'', hourly_rate:'', hours_per_day:8,
    valid_from:new Date().toISOString().slice(0,10), valid_to:'',
  });
}
async function attachToClient() {
  if (!props.canConnect || !item.value || !Number(connection.client_id)) return;
  saving.value = true;
  error.value = '';
  try {
    await api(`/api/clients/clients/${Number(connection.client_id)}/client-members`, {
      method:'POST',
      body:JSON.stringify({
        specialist_type:'partner', partner_specialist_id:Number(item.value.id),
        technology:connection.technology, level:connection.level,
        hourly_rate:connection.hourly_rate, hours_per_day:connection.hours_per_day,
        valid_from:connection.valid_from, valid_to:connection.valid_to||null,
      }),
    });
    connecting.value = false;
    emit('changed');
  } catch(e) { error.value = e.message||String(e); }
  finally { saving.value = false; }
}
watch(search, () => { if (search.value.trim()) partners.value.forEach(p => expanded.add(Number(p.id))); });
onMounted(load);
</script>

<template>
  <section class="partners-directory">
    <div class="partners-directory__filters">
      <UiFilterBar>
        <input v-model="search" type="search" class="registry-search" placeholder="Поиск по партнёрам и специалистам" aria-label="Поиск партнёров"/>
        <UiSearchSelect v-model="partnerFilter" :options="groupOptions" placeholder="Все партнёры" search-placeholder="Поиск партнёра"/>
      </UiFilterBar>
      <UiButton v-if="canManage" compact @click="create('partner')">＋ Партнёр</UiButton>
    </div>
    <p v-if="error && !kind" role="alert" class="partner-error">{{ error }} <button @click="load">Повторить</button></p>
    <p v-if="loading" role="status">Загрузка партнёров…</p>
    <div v-else class="partners-directory__tree" role="tree" aria-label="Партнёры и специалисты">
      <template v-for="partner in visible.result" :key="partner.id">
        <div class="partners-directory__partner" role="treeitem" :aria-expanded="expanded.has(Number(partner.id))" tabindex="0"
          @click="open('partner', partner)" @keydown.enter.prevent="open('partner', partner)">
          <UiTreeToggle :expanded="expanded.has(Number(partner.id))" :label="expanded.has(Number(partner.id))?'Свернуть':'Развернуть'"
            @click.stop="toggle(Number(partner.id))"/>
          <strong>{{ partner.name }}</strong>
          <span class="partner-meta">{{ partner.specialists.length }} специалистов</span>
          <UiButton v-if="canManage" compact variant="secondary" @click.stop="create('specialist', partner.id)">＋ Специалист</UiButton>
        </div>
        <div v-if="expanded.has(Number(partner.id))" role="group">
          <div v-for="specialist in partner.specialists" :key="specialist.id" role="treeitem" tabindex="0"
            class="partners-directory__specialist"
            @click="open('specialist',specialist)" @keydown.enter.prevent="open('specialist',specialist)">
            <span>{{ specialist.full_name }}</span>
            <span class="partner-meta">{{ specialist.specialization || '—' }}</span>
          </div>
          <p v-if="!partner.specialists.length" class="partners-directory__empty">Специалистов пока нет</p>
        </div>
      </template>
      <template v-if="visible.orphan.length">
        <div class="partners-directory__partner" role="treeitem" aria-expanded="true">
          <strong>Без партнёра</strong><span class="partner-meta">Ранее созданные специалисты</span>
        </div>
        <div role="group">
          <div v-for="specialist in visible.orphan" :key="specialist.id" role="treeitem" tabindex="0"
            class="partners-directory__specialist" @click="open('specialist', specialist)"
            @keydown.enter.prevent="open('specialist', specialist)">
            {{ specialist.full_name }}
          </div>
        </div>
      </template>
      <p v-if="!visible.result.length && !visible.orphan.length" class="partners-directory__empty">По заданным фильтрам ничего не найдено</p>
    </div>
    <UiDrawer :open="!!kind" :title="title" width="520px" @close="close">
      <form v-if="creating" class="partners-directory__form" @submit.prevent="submitCreate">
        <p v-if="error" class="partner-error" role="alert">{{ error }}</p>
        <label v-for="field in fields" :key="field.key">
          {{ field.label }}
          <UiSearchSelect v-if="field.select" v-model="form.partner_id" :options="options" :clearable="false" placeholder="Выберите партнёра" />
          <textarea v-else-if="field.multiline" v-model="form[field.key]" rows="3"></textarea>
          <input v-else v-model="form[field.key]" :type="field.type || 'text'" :required="!!field.required" :maxlength="field.key==='phone'?100:255"/>
        </label>
        <UiButton :disabled="saving || (kind==='specialist'&&!Number(form.partner_id))" type="submit">
          {{ saving?'Сохранение…':'Создать' }}
        </UiButton>
      </form>
      <section v-else-if="item" class="partners-directory__card">
        <p v-if="error" class="partner-error" role="alert">{{ error }}</p>
        <div v-for="field in fields" :key="field.key" class="partners-directory__field">
          <span class="partners-directory__label">{{ field.label }}</span>
          <template v-if="editing===field.key">
            <UiSearchSelect v-if="field.select" v-model="editValue" :options="options" :clearable="false" placeholder="Выберите партнёра"/>
            <textarea v-else-if="field.multiline" v-model="editValue" rows="3"></textarea>
            <input v-else v-model="editValue" :type="field.type || 'text'"/>
            <div class="partners-directory__field-actions">
              <button type="button" aria-label="Сохранить" :disabled="saving" @click="saveField(field)">✓</button>
              <button type="button" aria-label="Отменить" :disabled="saving" @click="editing=''">✕</button>
            </div>
          </template>
          <template v-else>
            <span>{{ field.select ? (partners.find(p=>Number(p.id)===Number(item[field.key]))?.name||'Не назначен')
              : item[field.key] || '—' }}</span>
            <button v-if="canManage" class="partners-directory__pencil" type="button" :aria-label="'Редактировать '+field.label"
              @click="edit(field)">✎</button>
          </template>
        </div>
        <UiButton v-if="kind==='partner' && canManage" compact @click="create('specialist',item.id)">＋ Добавить специалиста</UiButton>
        <UiButton v-if="kind==='specialist' && canConnect && !connecting" compact @click="beginConnection">＋ Подключить к клиенту</UiButton>
        <form v-if="kind==='specialist' && connecting" class="partners-directory__form partners-directory__connect" @submit.prevent="attachToClient">
          <strong>Подключение к клиенту</strong>
          <p>Проект не обязателен. Ставка и загрузка действуют для клиента в целом.</p>
          <label>Клиент<UiSearchSelect v-model="connection.client_id" :options="clientOptions" :clearable="false" placeholder="Выберите клиента" /></label>
          <label>Технология<UiSearchSelect v-model="connection.technology" :options="technologies" :clearable="false" placeholder="Выберите технологию" /></label>
          <label>Уровень<UiSearchSelect v-model="connection.level" :options="levels" :clearable="false" placeholder="Выберите уровень" /></label>
          <label>Ставка, ₽/ч<input v-model="connection.hourly_rate" type="number" min="0" max="9999999999.99" step="0.01" required/></label>
          <label>Загрузка, ч/д<input v-model="connection.hours_per_day" type="number" min="0" max="24" step="0.25" required/></label>
          <label>Дата начала<input v-model="connection.valid_from" type="date" required/></label>
          <label>Дата окончания<input v-model="connection.valid_to" type="date" :min="connection.valid_from"/></label>
          <div class="partners-directory__field-actions">
            <UiButton :disabled="saving || !Number(connection.client_id) || !connection.technology || !connection.level" type="submit">Сохранить подключение</UiButton>
            <UiButton variant="secondary" type="button" @click="connecting=false">Отменить</UiButton>
          </div>
        </form>
      </section>
    </UiDrawer>
  </section>
</template>

<style scoped>
.partners-directory { min-height: 0; display:flex; flex-direction:column; height:100%; }
.partners-directory__filters { display:flex; flex-wrap:wrap; gap:12px; justify-content:space-between; align-items:center; padding:8px 4px 12px; }
.partners-directory__tree { flex:1; min-height:0; overflow-y:auto; }
.partners-directory__partner, .partners-directory__specialist { display:flex; align-items:center; gap:12px; min-height:43px; padding:7px 10px; border-bottom:1px solid var(--irlix-border-color, #e2e5e9); cursor:pointer; }
.partners-directory__partner:hover,.partners-directory__specialist:hover { background:var(--irlix-hover-bg,rgba(100,110,130,.06)); }
.partners-directory__partner strong { flex:1; }
.partners-directory__specialist { padding-left:56px; justify-content:space-between; }
.partner-meta, .partners-directory__label { color:var(--irlix-text-muted,#79818a); font-size:12px; }
.partners-directory__empty { color:var(--irlix-text-muted,#79818a); padding:12px 32px; }
.partners-directory__form, .partners-directory__card { padding:18px; display:flex; flex-direction:column; gap:16px; }
.partners-directory__form label { display:flex; flex-direction:column; gap:6px; }
.partners-directory__form input,.partners-directory__form textarea,.partners-directory__card input,.partners-directory__card textarea { width:100%; box-sizing:border-box; font:inherit; padding:8px; border:1px solid var(--irlix-border-color,#ccd1d8); border-radius:6px; background:var(--irlix-surface,#fff); color:inherit; }
.partners-directory__field { display:grid; grid-template-columns:120px 1fr auto; align-items:center; gap:8px; min-height:36px; }
.partners-directory__pencil,.partners-directory__field-actions button { border:0; background:transparent; color:inherit; cursor:pointer; padding:6px; }
.partners-directory__field-actions { display:flex; }
.partner-error { color:#b42318; }
@media(max-width:650px) { .partners-directory__field { grid-template-columns:1fr auto; } .partners-directory__label { grid-column:1 / -1; } .partners-directory__specialist { padding-left:28px; } }
</style>
