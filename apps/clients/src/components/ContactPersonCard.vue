<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UiButton, UiDrawer, UiSearchSelect } from '@irlix/ui';

const props = defineProps({
  open: { type: Boolean, default: false },
  contactId: { type: Number, default: null },
  clients: { type: Array, default: () => [] },
  initialClientId: { type: Number, default: null },
});
const emit = defineEmits(['close', 'changed', 'created']);

const loading = ref(false);
const creating = ref(false);
const error = ref('');
const nameEditing = ref(false);
const nameDraft = ref('');
const addMethodOpen = ref(false);
const addRelationOpen = ref(false);
const data = reactive({ id: null, full_name: '', methods: [], client_relations: [] });
const createMethods = reactive([]);
const createRelations = reactive([]);
const newMethod = reactive({ type: '', contact: '' });
const newRelation = reactive({ client_id: '', position: '' });

const isCreate = computed(() => !props.contactId);
const clientOptions = computed(() => props.clients.map(client => ({ value: String(client.id), label: client.name })).sort((a, b) => a.label.localeCompare(b.label, 'ru')));

async function api(url, options = {}) {
  const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

function normalizeMethod(method) {
  return {
    ...method,
    is_active: method.is_active === true || Number(method.is_active) === 1,
    is_preferred: method.is_preferred === true || Number(method.is_preferred) === 1,
    editing: false,
    draftType: method.type,
    draftContact: method.contact,
  };
}
function normalizeRelation(relation) {
  return {
    ...relation,
    active: relation.active === true || Number(relation.active) === 1,
    editing: false,
    draftPosition: relation.position || '',
  };
}
function sortMethods() {
  data.methods.sort((a, b) => Number(b.is_active) - Number(a.is_active) || Number(b.is_preferred) - Number(a.is_preferred) || Number(a.id) - Number(b.id));
}
function sortRelations() {
  data.client_relations.sort((a, b) => Number(b.active) - Number(a.active) || String(a.client_name || '').localeCompare(String(b.client_name || ''), 'ru'));
}
function snapshot() {
  return {
    id: data.id,
    full_name: data.full_name,
    methods: data.methods.map(({ editing, draftType, draftContact, ...method }) => ({ ...method })),
    client_relations: data.client_relations.map(({ editing, draftPosition, ...relation }) => ({ ...relation })),
  };
}
function notifyChanged() {
  emit('changed', snapshot());
}
function applyContact(contact) {
  data.id = contact?.id ?? null;
  data.full_name = contact?.full_name || '';
  nameDraft.value = data.full_name;
  data.methods = (contact?.methods || []).map(normalizeMethod);
  data.client_relations = (contact?.client_relations || []).map(normalizeRelation);
  sortMethods();
  sortRelations();
}
function reset() {
  applyContact(null);
  createMethods.splice(0);
  createRelations.splice(0);
  Object.assign(newMethod, { type: '', contact: '' });
  Object.assign(newRelation, { client_id: '', position: '' });
  if (props.initialClientId) createRelations.push({ client_id: String(props.initialClientId), position: '', active: true, editing: false, draftPosition: '' });
  nameEditing.value = false;
  addMethodOpen.value = false;
  addRelationOpen.value = false;
  error.value = '';
}
async function load() {
  reset();
  if (!props.open || !props.contactId) return;
  loading.value = true;
  try {
    const result = await api(`/api/clients/contacts/${props.contactId}`);
    applyContact(result.data || {});
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    loading.value = false;
  }
}

function startNameEdit() {
  nameDraft.value = data.full_name;
  nameEditing.value = true;
}
function cancelNameEdit() {
  nameDraft.value = data.full_name;
  nameEditing.value = false;
}
async function saveName() {
  const fullName = nameDraft.value.trim();
  if (!fullName || !props.contactId) return;
  try {
    const response = await api(`/api/clients/contacts/${props.contactId}`, { method: 'PATCH', body: JSON.stringify({ full_name: fullName }) });
    data.full_name = response.data?.full_name || fullName;
    nameDraft.value = data.full_name;
    nameEditing.value = false;
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}

function addCreateMethod() {
  const type = newMethod.type.trim();
  const contact = newMethod.contact.trim();
  if (!type || !contact) return;
  createMethods.push({ type, contact, is_active: true, is_preferred: false, editing: false, draftType: type, draftContact: contact });
  Object.assign(newMethod, { type: '', contact: '' });
  addMethodOpen.value = false;
}
function addCreateRelation() {
  if (!newRelation.client_id) return;
  const index = createRelations.findIndex(item => String(item.client_id) === String(newRelation.client_id));
  const position = newRelation.position.trim();
  const relation = { client_id: String(newRelation.client_id), position, active: true, editing: false, draftPosition: position };
  if (index >= 0) createRelations.splice(index, 1, relation);
  else createRelations.push(relation);
  Object.assign(newRelation, { client_id: '', position: '' });
  addRelationOpen.value = false;
}
function localPreferred(method) {
  const next = !method.is_preferred;
  createMethods.forEach(item => { item.is_preferred = false; });
  method.is_preferred = next;
  if (next) method.is_active = true;
}
function localMethodActive(method) {
  method.is_active = !method.is_active;
  if (!method.is_active) method.is_preferred = false;
}
function saveCreateMethod(method) {
  const type = String(method.draftType || '').trim();
  const contact = String(method.draftContact || '').trim();
  if (!type || !contact) return;
  method.type = type;
  method.contact = contact;
  method.editing = false;
}
function saveCreateRelation(relation) {
  relation.position = String(relation.draftPosition || '').trim();
  relation.editing = false;
}

async function createContact() {
  const fullName = nameDraft.value.trim();
  if (!fullName) return;
  creating.value = true;
  error.value = '';
  try {
    const response = await api('/api/clients/contacts', {
      method: 'POST',
      body: JSON.stringify({
        full_name: fullName,
        methods: createMethods.map(item => ({ type: item.type, contact: item.contact, is_active: item.is_active, is_preferred: item.is_preferred })),
        client_relations: createRelations.map(item => ({ client_id: Number(item.client_id), position: item.position || null })),
      }),
    });
    applyContact(response.data || {});
    emit('created', snapshot());
    notifyChanged();
    emit('close');
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    creating.value = false;
  }
}

async function addMethod() {
  const type = newMethod.type.trim();
  const contact = newMethod.contact.trim();
  if (!type || !contact || !props.contactId) return;
  try {
    const response = await api(`/api/clients/contacts/${props.contactId}/methods`, { method: 'POST', body: JSON.stringify({ type, contact }) });
    data.methods.push(normalizeMethod(response.data || {}));
    sortMethods();
    Object.assign(newMethod, { type: '', contact: '' });
    addMethodOpen.value = false;
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}
async function saveMethod(method) {
  const type = String(method.draftType || '').trim();
  const contact = String(method.draftContact || '').trim();
  if (!type || !contact) return;
  try {
    const response = await api(`/api/clients/contacts/${props.contactId}/methods/${method.id}`, { method: 'PATCH', body: JSON.stringify({ type, contact }) });
    Object.assign(method, normalizeMethod(response.data || { ...method, type, contact }));
    sortMethods();
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}
async function toggleMethod(method) {
  try {
    const response = await api(`/api/clients/contacts/${props.contactId}/methods/${method.id}`, { method: 'PATCH', body: JSON.stringify({ is_active: !method.is_active }) });
    Object.assign(method, normalizeMethod(response.data || { ...method, is_active: !method.is_active }));
    sortMethods();
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}
async function togglePreferred(method) {
  const next = !method.is_preferred;
  try {
    const response = await api(`/api/clients/contacts/${props.contactId}/methods/${method.id}`, { method: 'PATCH', body: JSON.stringify({ is_preferred: next }) });
    if (next) data.methods.forEach(item => { item.is_preferred = false; });
    Object.assign(method, normalizeMethod(response.data || { ...method, is_preferred: next, is_active: next ? true : method.is_active }));
    sortMethods();
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}
async function deleteMethod(method) {
  if (!window.confirm(`Удалить способ связи «${method.type}: ${method.contact}»?`)) return;
  try {
    await api(`/api/clients/contacts/${props.contactId}/methods/${method.id}`, { method: 'DELETE' });
    const index = data.methods.findIndex(item => Number(item.id) === Number(method.id));
    if (index >= 0) data.methods.splice(index, 1);
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}

async function addRelation() {
  if (!newRelation.client_id || !props.contactId) return;
  try {
    const response = await api(`/api/clients/contacts/${props.contactId}/client-relations`, { method: 'POST', body: JSON.stringify({ client_id: Number(newRelation.client_id), position: newRelation.position || null }) });
    const next = normalizeRelation(response.data || {});
    const existing = data.client_relations.findIndex(item => Number(item.client_id) === Number(next.client_id));
    if (existing >= 0) data.client_relations.splice(existing, 1, next);
    else data.client_relations.push(next);
    sortRelations();
    Object.assign(newRelation, { client_id: '', position: '' });
    addRelationOpen.value = false;
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}
async function saveRelation(relation) {
  try {
    const response = await api(`/api/clients/contacts/${props.contactId}/client-relations/${relation.id}`, { method: 'PATCH', body: JSON.stringify({ position: relation.draftPosition || null }) });
    Object.assign(relation, normalizeRelation(response.data || { ...relation, position: relation.draftPosition || null }));
    sortRelations();
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}
async function toggleRelation(relation) {
  try {
    const response = await api(`/api/clients/contacts/${props.contactId}/client-relations/${relation.id}`, { method: 'PATCH', body: JSON.stringify({ active: !relation.active }) });
    Object.assign(relation, normalizeRelation(response.data || { ...relation, active: !relation.active }));
    sortRelations();
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}
async function deleteRelation(relation) {
  if (!window.confirm(`Удалить привязку к клиенту «${relation.client_name}»?`)) return;
  try {
    await api(`/api/clients/contacts/${props.contactId}/client-relations/${relation.id}`, { method: 'DELETE' });
    const index = data.client_relations.findIndex(item => Number(item.id) === Number(relation.id));
    if (index >= 0) data.client_relations.splice(index, 1);
    notifyChanged();
  } catch (e) { error.value = e.message || String(e); }
}

watch(() => [props.open, props.contactId, props.initialClientId], load, { immediate: true });
</script>

<template>
  <UiDrawer :open="open" title="" width="720px" :min-width="520" @close="emit('close')">
    <template #title>
      <div class="contact-title">
        <template v-if="isCreate">
          <input v-model="nameDraft" class="title-input" placeholder="ФИО контактного лица" @keyup.enter="createContact">
        </template>
        <template v-else-if="nameEditing">
          <input v-model="nameDraft" class="title-input" @keyup.enter="saveName" @keyup.esc="cancelNameEdit">
          <button type="button" class="header-icon save" title="Сохранить ФИО" @click="saveName">✓</button>
          <button type="button" class="header-icon" title="Отменить" @click="cancelNameEdit">×</button>
        </template>
        <template v-else>
          <strong>{{ data.full_name || 'Контакт' }}</strong>
          <button type="button" class="header-icon" title="Редактировать ФИО" @click="startNameEdit">✎</button>
        </template>
      </div>
    </template>

    <div v-if="error" class="contact-error">{{ error }}<button type="button" @click="error=''">×</button></div>
    <div v-if="loading" class="contact-loading">Загрузка контакта…</div>
    <div v-else class="contact-card">
      <section class="contact-section">
        <div class="section-title">
          <h3>Способы связи</h3>
          <button type="button" class="section-add" @click="addMethodOpen=!addMethodOpen">＋ способ связи</button>
        </div>
        <div v-if="isCreate" class="entity-list">
          <div v-for="(method, index) in createMethods" :key="`${method.type}-${method.contact}-${index}`" class="method-row" :class="{ inactive: !method.is_active }">
            <template v-if="method.editing">
              <input v-model="method.draftType" placeholder="Тип связи">
              <input v-model="method.draftContact" placeholder="Контакт">
              <div class="row-actions"><button type="button" class="icon-action save" title="Сохранить" @click="saveCreateMethod(method)">✓</button><button type="button" class="icon-action" title="Отмена" @click="method.editing=false">×</button></div>
            </template>
            <template v-else>
              <strong><span v-if="method.is_preferred" class="preferred-indicator">✓</span>{{ method.type }}</strong>
              <span>{{ method.contact }}</span>
              <div class="row-actions">
                <button type="button" class="icon-action preferred" :class="{ active: method.is_preferred }" :title="method.is_preferred?'Убрать предпочтительный':'Сделать предпочтительным'" @click="localPreferred(method)">✓</button>
                <button type="button" class="icon-action state" :class="{ active: method.is_active }" :title="method.is_active?'Сделать неактуальным':'Сделать актуальным'" @click="localMethodActive(method)">{{method.is_active?'●':'○'}}</button>
                <button type="button" class="icon-action" title="Редактировать" @click="method.editing=true">✎</button>
                <button type="button" class="icon-action danger" title="Удалить" @click="createMethods.splice(index,1)">⌫</button>
              </div>
            </template>
          </div>
          <div v-if="!createMethods.length" class="empty-state">Способы связи не добавлены</div>
        </div>
        <div v-else class="entity-list">
          <div v-for="method in data.methods" :key="method.id" class="method-row" :class="{ inactive: !method.is_active }">
            <template v-if="method.editing">
              <input v-model="method.draftType" placeholder="Тип связи">
              <input v-model="method.draftContact" placeholder="Контакт">
              <div class="row-actions"><button type="button" class="icon-action save" title="Сохранить" @click="saveMethod(method)">✓</button><button type="button" class="icon-action" title="Отмена" @click="method.editing=false">×</button></div>
            </template>
            <template v-else>
              <strong><span v-if="method.is_preferred" class="preferred-indicator">✓</span>{{ method.type }}</strong>
              <span>{{ method.contact }}</span>
              <div class="row-actions">
                <button type="button" class="icon-action preferred" :class="{ active: method.is_preferred }" :title="method.is_preferred?'Убрать предпочтительный':'Сделать предпочтительным'" @click="togglePreferred(method)">✓</button>
                <button type="button" class="icon-action state" :class="{ active: method.is_active }" :title="method.is_active?'Сделать неактуальным':'Сделать актуальным'" @click="toggleMethod(method)">{{method.is_active?'●':'○'}}</button>
                <button type="button" class="icon-action" title="Редактировать" @click="method.editing=true">✎</button>
                <button type="button" class="icon-action danger" title="Удалить" @click="deleteMethod(method)">⌫</button>
              </div>
            </template>
          </div>
          <div v-if="!data.methods.length" class="empty-state">Способы связи не добавлены</div>
        </div>
        <div v-if="addMethodOpen" class="add-row methods-add">
          <input v-model="newMethod.type" placeholder="Тип связи, например телефон">
          <input v-model="newMethod.contact" placeholder="Контакт, например +79050000001">
          <UiButton type="button" compact :disabled="!newMethod.type.trim()||!newMethod.contact.trim()" @click="isCreate ? addCreateMethod() : addMethod()">Добавить</UiButton>
          <button type="button" class="text-cancel" @click="addMethodOpen=false">Отмена</button>
        </div>
      </section>

      <section class="contact-section">
        <div class="section-title">
          <h3>Клиенты</h3>
          <button type="button" class="section-add" @click="addRelationOpen=!addRelationOpen">＋ Клиент</button>
        </div>
        <div v-if="isCreate" class="entity-list">
          <div v-for="(relation, index) in createRelations" :key="`${relation.client_id}-${index}`" class="entity-row" :class="{ inactive: !relation.active }">
            <strong>{{ clients.find(c => String(c.id) === String(relation.client_id))?.name || `#${relation.client_id}` }}</strong>
            <template v-if="relation.editing">
              <input v-model="relation.draftPosition" placeholder="Должность">
              <div class="row-actions"><button type="button" class="icon-action save" title="Сохранить" @click="saveCreateRelation(relation)">✓</button><button type="button" class="icon-action" title="Отмена" @click="relation.editing=false">×</button></div>
            </template>
            <template v-else>
              <span>{{ relation.position || '—' }}</span>
              <div class="row-actions">
                <button type="button" class="icon-action state" :class="{ active: relation.active }" :title="relation.active?'Сделать неактуальным':'Сделать актуальным'" @click="relation.active=!relation.active">{{relation.active?'●':'○'}}</button>
                <button type="button" class="icon-action" title="Редактировать" @click="relation.editing=true">✎</button>
                <button type="button" class="icon-action danger" title="Удалить" @click="createRelations.splice(index,1)">⌫</button>
              </div>
            </template>
          </div>
          <div v-if="!createRelations.length" class="empty-state">Клиенты не добавлены</div>
        </div>
        <div v-else class="entity-list">
          <div v-for="relation in data.client_relations" :key="relation.id" class="entity-row" :class="{ inactive: !relation.active }">
            <strong>{{ relation.client_name }}</strong>
            <template v-if="relation.editing">
              <input v-model="relation.draftPosition" placeholder="Должность">
              <div class="row-actions"><button type="button" class="icon-action save" title="Сохранить" @click="saveRelation(relation)">✓</button><button type="button" class="icon-action" title="Отмена" @click="relation.editing=false">×</button></div>
            </template>
            <template v-else>
              <span>{{ relation.position || '—' }}</span>
              <div class="row-actions">
                <button type="button" class="icon-action state" :class="{ active: relation.active }" :title="relation.active?'Сделать неактуальным':'Сделать актуальным'" @click="toggleRelation(relation)">{{relation.active?'●':'○'}}</button>
                <button type="button" class="icon-action" title="Редактировать" @click="relation.editing=true">✎</button>
                <button type="button" class="icon-action danger" title="Удалить" @click="deleteRelation(relation)">⌫</button>
              </div>
            </template>
          </div>
          <div v-if="!data.client_relations.length" class="empty-state">Клиенты не добавлены</div>
        </div>
        <div v-if="addRelationOpen" class="add-row">
          <UiSearchSelect v-model="newRelation.client_id" :options="clientOptions" placeholder="Клиент" search-placeholder="Поиск клиента"/>
          <input v-model="newRelation.position" placeholder="Должность у клиента">
          <UiButton type="button" compact :disabled="!newRelation.client_id" @click="isCreate ? addCreateRelation() : addRelation()">Добавить</UiButton>
          <button type="button" class="text-cancel" @click="addRelationOpen=false">Отмена</button>
        </div>
      </section>

      <div v-if="isCreate" class="contact-actions">
        <UiButton type="button" :disabled="creating || !nameDraft.trim()" @click="createContact">{{ creating ? 'Создаю…' : 'Создать контакт' }}</UiButton>
      </div>
    </div>
  </UiDrawer>
</template>

<style scoped>
.contact-title{display:flex;align-items:center;gap:5px;min-width:0}.contact-title strong{font-size:15px;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.title-input{width:min(380px,55vw);height:30px;padding:4px 8px;border:1px solid #d7dce1;border-radius:7px;font:inherit;font-weight:600}.header-icon,.icon-action{width:25px;height:25px;padding:0;border:0;border-radius:6px;background:transparent;color:#7c858f;font:inherit;font-weight:700;cursor:pointer;display:inline-grid;place-items:center}.header-icon:hover,.icon-action:hover{background:#f1f4f5;color:#26313a}.header-icon.save,.icon-action.save{color:#078d6c}.contact-card{display:grid;gap:14px;font-size:13px}.contact-error{display:flex;justify-content:space-between;gap:8px;padding:7px 9px;margin-bottom:8px;border-radius:7px;background:#fff2f1;color:#b42318;font-size:12px}.contact-error button{border:0;background:transparent;color:inherit}.contact-loading,.empty-state{padding:10px 0;color:#858d96;font-size:12px}.contact-section{display:grid;gap:6px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:30px}.contact-section h3{margin:0;font-size:13px}.section-add{border:0;background:transparent;color:#078d6c;font:inherit;font-size:12px;font-weight:700;cursor:pointer;padding:4px 6px;border-radius:6px}.section-add:hover{background:#edf8f5}.entity-list{display:grid;border-top:1px solid #eceff2}.entity-row,.method-row{display:grid;grid-template-columns:minmax(150px,.75fr) minmax(180px,1fr) auto;gap:8px;align-items:center;min-height:42px;padding:3px 0;border-bottom:1px solid #eceff2}.entity-row.inactive,.method-row.inactive{opacity:.48}.entity-row input,.method-row input,.add-row>input{min-height:31px;padding:5px 8px;border:1px solid #d8dde3;border-radius:7px;font:inherit;font-size:12px}.method-row strong,.entity-row strong{font-size:12px}.method-row>span,.entity-row>span{font-size:12px}.preferred-indicator{display:inline-block;margin-right:5px;color:#06a77d;font-size:11px;font-weight:800}.row-actions{display:flex;justify-content:flex-end;gap:2px;white-space:nowrap}.icon-action.preferred{color:#c3c8ce}.icon-action.preferred.active{color:#06a77d}.icon-action.state{color:#aab0b7}.icon-action.state.active{color:#078d6c}.icon-action.danger{color:#a4abb2}.icon-action.danger:hover{color:#b42318;background:#fff1f0}.add-row{display:grid;grid-template-columns:minmax(180px,.85fr) minmax(180px,1fr) auto auto;gap:7px;align-items:center;padding:7px;border:1px solid #e3e7ea;border-radius:8px;background:#fafbfb}.methods-add{grid-template-columns:minmax(150px,.65fr) minmax(220px,1fr) auto auto}.text-cancel{border:0;background:transparent;color:#757e88;font:inherit;font-size:12px;cursor:pointer}.contact-actions{display:flex;justify-content:flex-end;padding-top:2px}@media(max-width:760px){.entity-row,.method-row,.add-row,.methods-add{grid-template-columns:1fr}.row-actions{justify-content:flex-start}.title-input{width:55vw}}
</style>
