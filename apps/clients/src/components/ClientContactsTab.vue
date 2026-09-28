<script setup>
import { reactive } from 'vue';
import { UiButton } from '@irlix/ui';
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
    <div class="tab-toolbar"><UiButton @click="createContact">＋ Новый контакт</UiButton></div>
    <div class="contact-table">
      <div class="contact-head"><span>ФИО</span><span>Должность</span></div>
      <button v-for="contact in contacts" :key="contact.id" type="button" class="contact-row" @click="openContact(contact)">
        <strong>{{ contact.full_name }}</strong>
        <span>{{ contact.relation_role || contact.position || '—' }}</span>
      </button>
      <div v-if="!contacts.length" class="contact-empty">У клиента пока нет контактных лиц</div>
    </div>
    <ContactPersonCard
      :open="card.open"
      :contact-id="card.contactId"
      :clients="clients"
      :initial-client-id="card.create ? clientId : null"
      @close="closeCard"
      @changed="emit('changed')"
      @created="emit('changed')"
    />
  </div>
</template>

<style scoped>
.tab-toolbar{display:flex;margin-bottom:12px}.contact-table{border-top:1px solid #e2e6ea}.contact-head,.contact-row{display:grid;grid-template-columns:minmax(220px,.7fr) minmax(220px,1fr);gap:14px;align-items:center;min-height:48px;padding:0 14px;border-bottom:1px solid #e8ebee}.contact-head{font-size:12px;font-weight:700;color:#078d6c}.contact-row{width:100%;border-left:0;border-right:0;border-top:0;background:#fff;text-align:left;font:inherit;cursor:pointer}.contact-row:hover{background:#f8fbfa}.contact-empty{padding:26px 14px;color:#8a929c;text-align:center}@media(max-width:700px){.contact-head,.contact-row{grid-template-columns:1fr}.contact-head span:last-child{display:none}}
</style>
