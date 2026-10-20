<script setup>
import { reactive } from 'vue';
import { UiIcon, UiRegistryTable } from '@irlix/ui';
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
    <div class="section-line"><h3>Контакты</h3><button type="button" class="client-link-action" @click="createContact">＋ Добавить</button></div>
    <div class="contact-table">
      <UiRegistryTable class="contact-registry" :columns="['38%','auto','42px']" min-width="420px" max-height="320px" aria-label="Контакты клиента" density="compact">
        <template #head><tr><th>ФИО</th><th>Должность</th><th aria-label="Редактирование"></th></tr></template>
        <tr v-for="contact in contacts" :key="contact.id" class="contact-row" tabindex="0" role="button"
          :aria-label="`Открыть контакт ${contact.full_name}`"
          @click="openContact(contact)" @keydown.enter.prevent="openContact(contact)" @keydown.space.prevent="openContact(contact)">
          <td><strong>{{ contact.full_name }}</strong></td>
          <td>{{ contact.relation_role || contact.position || '—' }}</td>
          <td class="contact-edit"><UiIcon name="pencil" /></td>
        </tr>
        <tr v-if="!contacts.length"><td class="contact-empty" colspan="3">У клиента пока нет контактных лиц</td></tr>
      </UiRegistryTable>
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
.section-line{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.section-line h3{margin:0;font-size:var(--irlix-font-size-body)}
.client-link-action{border:0;padding:4px 0;background:transparent;color:var(--irlix-color-primary-text);font:inherit;font-size:var(--irlix-font-size-table);font-weight:600;cursor:pointer}
.client-link-action:hover{text-decoration:underline}
.contact-table{min-width:0;border:1px solid var(--irlix-color-border);border-radius:8px;overflow:hidden}
.contact-table :deep(.ui-registry-table){height:auto}
.contact-row{cursor:pointer}
.contact-row:hover td,.contact-row:focus-visible td{background:var(--irlix-color-row-hover)}
.contact-row:focus-visible{outline:2px solid var(--irlix-color-primary-text);outline-offset:-2px}
.contact-row td{color:var(--irlix-color-text);font-size:var(--irlix-font-size-table);line-height:20px;overflow-wrap:anywhere}
.contact-edit{text-align:right}
.contact-empty{padding:26px 14px;color:var(--irlix-color-text-muted);text-align:center}
</style>