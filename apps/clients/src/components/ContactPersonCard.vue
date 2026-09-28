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
const saving = ref(false);
const error = ref('');
const data = reactive({ id: null, full_name: '', methods: [], client_relations: [] });
const createMethods = reactive([]);
const createRelations = reactive([]);
const newMethod = reactive({ type: '', contact: '' });
const newRelation = reactive({ client_id: '', position: '' });

const isCreate = computed(() => !props.contactId);
const title = computed(() => isCreate.value ? 'Новый контакт' : data.full_name || 'Контакт');
const clientOptions = computed(() => props.clients.map(client => ({ value: String(client.id), label: client.name })).sort((a, b) => a.label.localeCompare(b.label, 'ru')));

async function api(url, options = {}) {
  const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

function reset() {
  data.id = null;
  data.full_name = '';
  data.methods = [];
  data.client_relations = [];
  createMethods.splice(0);
  createRelations.splice(0);
  Object.assign(newMethod, { type: '', contact: '' });
  Object.assign(newRelation, { client_id: '', position: '' });
  if (props.initialClientId) createRelations.push({ client_id: String(props.initialClientId), position: '' });
  error.value = '';
}

async function load() {
  reset();
  if (!props.open || !props.contactId) return;
  loading.value = true;
  try {
    const result = await api(`/api/clients/contacts/${props.contactId}`);
    Object.assign(data, result.data || {});
    data.methods = (data.methods || []).map(method => ({ ...method, editing: false, draftType: method.type, draftContact: method.contact }));
    data.client_relations = (data.client_relations || []).map(relation => ({ ...relation, editing: false, draftPosition: relation.position || '' }));
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    loading.value = false;
  }
}

function addCreateMethod() {
  const type = newMethod.type.trim();
  const contact = newMethod.contact.trim();
  if (!type || !contact) return;
  createMethods.push({ type, contact, is_active: true });
  Object.assign(newMethod, { type: '', contact: '' });
}

function addCreateRelation() {
  if (!newRelation.client_id) return;
  const index = createRelations.findIndex(item => String(item.client_id) === String(newRelation.client_id));
  const relation = { client_id: String(newRelation.client_id), position: newRelation.position.trim() };
  if (index >= 0) createRelations.splice(index, 1, relation);
  else createRelations.push(relation);
  Object.assign(newRelation, { client_id: '', position: '' });
}

async function saveContact() {
  const fullName = data.full_name.trim();
  if (!fullName) return;
  saving.value = true;
  error.value = '';
  try {
    if (isCreate.value) {
      const response = await api('/api/clients/contacts', {
        method: 'POST',
        body: JSON.stringify({
          full_name: fullName,
          methods: createMethods,
          client_relations: createRelations.map(item => ({ client_id: Number(item.client_id), position: item.position || null })),
        }),
      });
      emit('created', response.data);
      emit('changed');
      emit('close');
      return;
    }
    await api(`/api/clients/contacts/${props.contactId}`, { method: 'PATCH', body: JSON.stringify({ full_name: fullName }) });
    emit('changed');
    await load();
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

async function addMethod() {
  const type = newMethod.type.trim();
  const contact = newMethod.contact.trim();
  if (!type || !contact || !props.contactId) return;
  saving.value = true;
  try {
    await api(`/api/clients/contacts/${props.contactId}/methods`, { method: 'POST', body: JSON.stringify({ type, contact }) });
    Object.assign(newMethod, { type: '', contact: '' });
    emit('changed');
    await load();
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

async function saveMethod(method) {
  saving.value = true;
  try {
    await api(`/api/clients/contacts/${props.contactId}/methods/${method.id}`, { method: 'PATCH', body: JSON.stringify({ type: method.draftType, contact: method.draftContact }) });
    emit('changed');
    await load();
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

async function toggleMethod(method) {
  saving.value = true;
  try {
    await api(`/api/clients/contacts/${props.contactId}/methods/${method.id}`, { method: 'PATCH', body: JSON.stringify({ is_active: !method.is_active }) });
    emit('changed');
    await load();
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

async function addRelation() {
  if (!newRelation.client_id || !props.contactId) return;
  saving.value = true;
  try {
    await api(`/api/clients/contacts/${props.contactId}/client-relations`, { method: 'POST', body: JSON.stringify({ client_id: Number(newRelation.client_id), position: newRelation.position || null }) });
    Object.assign(newRelation, { client_id: '', position: '' });
    emit('changed');
    await load();
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

async function saveRelation(relation) {
  saving.value = true;
  try {
    await api(`/api/clients/contacts/${props.contactId}/client-relations/${relation.id}`, { method: 'PATCH', body: JSON.stringify({ position: relation.draftPosition || null }) });
    emit('changed');
    await load();
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

async function toggleRelation(relation) {
  saving.value = true;
  try {
    await api(`/api/clients/contacts/${props.contactId}/client-relations/${relation.id}`, { method: 'PATCH', body: JSON.stringify({ active: !relation.active }) });
    emit('changed');
    await load();
  } catch (e) { error.value = e.message || String(e); }
  finally { saving.value = false; }
}

watch(() => [props.open, props.contactId, props.initialClientId], load, { immediate: true });
</script>

<template>
  <UiDrawer :open="open" :title="title" width="760px" :min-width="560" @close="emit('close')">
    <div v-if="error" class="contact-error">{{ error }}</div>
    <div v-if="loading" class="contact-loading">Загрузка контакта…</div>
    <form v-else class="contact-card" @submit.prevent="saveContact">
      <section class="contact-section">
        <h3>Контакт</h3>
        <label>ФИО<span>*</span><input v-model="data.full_name" required placeholder="Иван Иванов"></label>
      </section>

      <section class="contact-section">
        <div class="section-title"><h3>Привязки к клиентам</h3><small>Должность задаётся отдельно для каждого клиента</small></div>
        <div v-if="isCreate" class="entity-list">
          <div v-for="(relation, index) in createRelations" :key="`${relation.client_id}-${index}`" class="entity-row">
            <strong>{{ clients.find(c => String(c.id) === String(relation.client_id))?.name || `#${relation.client_id}` }}</strong>
            <span>{{ relation.position || 'Должность не указана' }}</span>
            <button type="button" class="link-button danger" @click="createRelations.splice(index,1)">Убрать</button>
          </div>
        </div>
        <div v-else class="entity-list">
          <div v-for="relation in data.client_relations" :key="relation.id" class="entity-row" :class="{ inactive: !relation.active }">
            <strong>{{ relation.client_name }}</strong>
            <template v-if="relation.editing">
              <input v-model="relation.draftPosition" placeholder="Должность">
              <div class="row-actions"><button type="button" class="link-button" @click="saveRelation(relation)">Сохранить</button><button type="button" class="link-button" @click="relation.editing=false">Отмена</button></div>
            </template>
            <template v-else>
              <span>{{ relation.position || 'Должность не указана' }}</span>
              <div class="row-actions"><button type="button" class="link-button" @click="relation.editing=true">Изменить</button><button type="button" class="link-button" @click="toggleRelation(relation)">{{ relation.active ? 'Сделать неактуальной' : 'Вернуть' }}</button></div>
            </template>
          </div>
          <div v-if="!data.client_relations.length" class="empty-state">Нет привязок к клиентам</div>
        </div>
        <div class="add-row">
          <UiSearchSelect v-model="newRelation.client_id" :options="clientOptions" placeholder="Клиент" search-placeholder="Поиск клиента"/>
          <input v-model="newRelation.position" placeholder="Должность у клиента">
          <UiButton type="button" variant="secondary" :disabled="!newRelation.client_id" @click="isCreate ? addCreateRelation() : addRelation()">＋ Добавить привязку</UiButton>
        </div>
      </section>

      <section class="contact-section">
        <div class="section-title"><h3>Способы связи</h3><small>Тип связи + контакт. Неограниченное количество записей.</small></div>
        <div v-if="isCreate" class="entity-list">
          <div v-for="(method, index) in createMethods" :key="`${method.type}-${method.contact}-${index}`" class="method-row">
            <strong>{{ method.type }}</strong><span>{{ method.contact }}</span><button type="button" class="link-button danger" @click="createMethods.splice(index,1)">Убрать</button>
          </div>
        </div>
        <div v-else class="entity-list">
          <div v-for="method in data.methods" :key="method.id" class="method-row" :class="{ inactive: !method.is_active }">
            <template v-if="method.editing">
              <input v-model="method.draftType" placeholder="Тип связи"><input v-model="method.draftContact" placeholder="Контакт"><div class="row-actions"><button type="button" class="link-button" @click="saveMethod(method)">Сохранить</button><button type="button" class="link-button" @click="method.editing=false">Отмена</button></div>
            </template>
            <template v-else>
              <strong>{{ method.type }}</strong><span>{{ method.contact }}</span><div class="row-actions"><button type="button" class="link-button" @click="method.editing=true">Изменить</button><button type="button" class="link-button" @click="toggleMethod(method)">{{ method.is_active ? 'Неактуальный' : 'Вернуть' }}</button></div>
            </template>
          </div>
          <div v-if="!data.methods.length" class="empty-state">Способы связи не добавлены</div>
        </div>
        <div class="add-row methods-add"><input v-model="newMethod.type" placeholder="Тип связи, например телефон"><input v-model="newMethod.contact" placeholder="Контакт, например +79050000001"><UiButton type="button" variant="secondary" :disabled="!newMethod.type.trim()||!newMethod.contact.trim()" @click="isCreate ? addCreateMethod() : addMethod()">＋ Добавить способ связи</UiButton></div>
      </section>

      <div class="contact-actions"><UiButton type="submit" :disabled="saving || !data.full_name.trim()">{{ isCreate ? 'Создать контакт' : 'Сохранить ФИО' }}</UiButton></div>
    </form>
  </UiDrawer>
</template>

<style scoped>
.contact-card{display:grid;gap:20px}.contact-error{padding:10px 12px;margin-bottom:12px;border-radius:8px;background:#fff2f1;color:#b42318}.contact-loading,.empty-state{padding:18px 0;color:#858d96}.contact-section{display:grid;gap:10px}.contact-section h3{margin:0;font-size:15px}.contact-section label{display:grid;gap:6px;color:#5d6670;font-size:13px}.contact-section label span{color:#d92d20}.contact-section input{min-height:38px;padding:8px 10px;border:1px solid #d8dde3;border-radius:9px;font:inherit}.section-title{display:flex;align-items:baseline;justify-content:space-between;gap:12px}.section-title small{color:#8a929c}.entity-list{display:grid;border-top:1px solid #eceff2}.entity-row,.method-row{display:grid;grid-template-columns:minmax(160px,.8fr) minmax(180px,1fr) auto;gap:12px;align-items:center;min-height:52px;border-bottom:1px solid #eceff2}.entity-row.inactive,.method-row.inactive{opacity:.5}.row-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}.link-button{border:0;background:transparent;color:#078d6c;font-weight:600;cursor:pointer}.link-button.danger{color:#b42318}.add-row{display:grid;grid-template-columns:minmax(200px,.9fr) minmax(180px,1fr) auto;gap:10px;align-items:center}.methods-add{grid-template-columns:minmax(170px,.55fr) minmax(220px,1fr) auto}.contact-actions{display:flex;justify-content:flex-end;padding-top:4px}@media(max-width:760px){.entity-row,.method-row,.add-row,.methods-add{grid-template-columns:1fr}.row-actions{justify-content:flex-start}.section-title{align-items:flex-start;flex-direction:column}}
</style>
