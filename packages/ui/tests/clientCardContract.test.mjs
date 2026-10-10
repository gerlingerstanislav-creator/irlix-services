import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const root=new URL('../../../',import.meta.url);
const read=p=>readFile(new URL(p,root),'utf8');
test('client card layout and action contract',async()=>{
 const [card,contacts,icons]=await Promise.all([
  read('apps/clients/src/components/ClientCard.vue'),
  read('apps/clients/src/components/ClientContactsTab.vue'),
  read('packages/ui/src/components/UiIcon.vue')
 ]);
 assert.match(card,/width:50vw/);
 assert.match(card,/client-description-row\{grid-column:1\/-1/);
 assert.match(card,/field-label\{color:var\(--irlix-color-text-muted\)/);
 assert.match(card,/shortEmployeeName\(employeeName/);
 assert.ok(card.indexOf("key:'sales_employee_id'")<card.indexOf("key:'account_employee_id'"));
 assert.match(card,/name="pencil"/);
 assert.doesNotMatch(card,/background:var\(--irlix-color-info\)/);
 assert.match(card,/iconName:'file-clock'/);
 assert.match(card,/client-link-action/);
 assert.match(contacts,/class="client-link-action"/);
 assert.match(icons,/name === 'pencil'/);
});
