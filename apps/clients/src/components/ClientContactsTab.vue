<script setup>
import { reactive } from 'vue';
import { UiIcon } from '@irlix/ui';
import ContactPersonCard from './ContactPersonCard.vue';

const props = defineProps({
  clientId: { type: Number, required: true },
  contacts: { type: Array, default: () => [] },
  clients: { type: Array, default: () => [] },
});
const emit = defineEmits(['changed']);
const card = reactive({ open: false, contactId: null, create: false });

function openContact(contact) {
  card.open = true;
  card.contactId = Number(contact.id);
  card.create = false;
}
function createContact() {
  card.open = true;
  card.contactId = null;
  card.create = true;
}
function closeCard() {
  card.open = false;
  card.contactId = null;
  card.create = false;
}
</script>

<template>
  <div class="client-contacts-tab">
    <div class="tab-toolbar"><button type="button" class="client-link-action" @click="createContact">＋ Новый контакт</button></div>
    <div class="contact-table">
      <div class="contact-head"><span>ФИО</span><span>Должность</span><span></span></div>
      <button v-for="contact in contacts" :key="contact.id" type="button" class="contact-row" @click="openContact(contact)">
        <strong>{{ contact.full_name }}</strong>
        <span>{{ contact.relation_role || contact.position || '—' }}</span><span class="contact-edit"><UiIcon name="pencil" /></span>
      </button>
      <div v-if="!contacts.length" class="contact-empty">У клиента пока нет контактных лиц</div>
    </div>
    <ContactPersonCard
      :open="card.open"
      :contact-id="card.contactId"
      :clients="clients"
      :initial-client-id="card.create ? clientId : null"
      @close="closeCard"
      @changed="emit('changed', $event)"
    />
  </div>
</template>

<style scoped>
.tab-toolbar{display:flex;justify-content:flex-end;margin-bottom:10px}
.client-link-action{border:0;padding:4px 0;background:transparent;color:var(--irlix-color-primary-text);font:inherit;font-size:var(--irlix-font-size-table);font-weight:600;cursor:pointer}
.client-link-action:hover{text-decoration:underline}
.contact-table{border:1px solid var(--irlix-color-border);border-radius:8px;overflow:hidden}
.contact-head,.contact-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.6fr) 42px;align-items:center;gap:10px}
.contact-head{padding:9px 12px;background:var(--irlix-color-surface-muted);font-size:var(--irlix-font-size-caption);font-weight:700}
.contact-row{width:100%;padding:10px 12px;border:0;border-top:1px solid var(--irlix-color-border);background:var(--irlix-color-surface);text-align:left;font:inherit;cursor:pointer}
.contact-row:hover{background:var(--irlix-color-surface-muted)}
.contact-edit{text-align:right}
.contact-empty{padding:26px 14px;color:var(--irlix-color-text-muted);text-align:center}
@media(max-width:700px){.contact-head,.contact-row{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 28px}}
</style>