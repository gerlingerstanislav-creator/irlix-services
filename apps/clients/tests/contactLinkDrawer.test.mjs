import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('partner and client link flows reuse the canonical contact card with searchable contact select',()=>{
 const partner=read('../src/components/PartnerProfileSections.vue');
 const client=read('../src/components/ClientContactsTab.vue');
 const card=read('../src/components/ContactPersonCard.vue');
 const select=read('../../../packages/ui/src/components/UiSearchSelect.vue');
 assert.match(partner, /<ContactPersonCard v-if="contactOpen"[^>]+:link-partner-id="partnerId"/);
 assert.match(client, /<ContactPersonCard v-if="linkOpen\.value"[^>]+:link-client-id="clientId"/);
 assert.match(client, /@click="linkOpen\.value=true"/);
 assert.match(client, /@click="createContact"/);
 assert.match(card, /<template v-if="isLink">\s*<UiSearchSelect/);
 assert.match(card, /search-placeholder="Поиск контакта по ФИО"/);
 assert.match(card, /@search="searchLinkContacts"/);
 assert.match(select, /emit\('search', value\)/);
 assert.match(card, /\/api\/clients\/partners\/contact-options\?q=/);
 assert.match(card, /v-else-if="isLink && !activeContactId"/);
 assert.match(card, /v-if="!isLink && canManage" class="row-actions"/);
 assert.match(card, /const payload = props\.linkPartnerId/);
 assert.match(card, /\/client-relations/);
 assert.match(card, /@click="linkExistingContact"/);
 assert.match(card, /emit\('changed'\);\s*emit\('close'\)/);
});
test('partner tabs span full width, use dynamic proportional tracks, and keep text aligned',()=>{
 const partner=read('../src/components/PartnerProfileSections.vue');
 assert.match(partner, /const tabColumns = tabs\.map\(item =>/);
 assert.match(partner, /gridTemplateColumns:tabColumns/);
 assert.match(partner, /\.partner-subsections__tabs\{[^}]*margin:0;width:100%/s);
 assert.match(partner, /\.partner-subsections__tabs :deep\(\.irlix-tab\)\{[^}]*width:100%/s);
 assert.doesNotMatch(partner, /width:calc\(100% - 20px\)/);
});
