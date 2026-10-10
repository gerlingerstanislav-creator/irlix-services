import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const base=new URL('../../../',import.meta.url);
const load=p=>readFile(new URL(p,base),'utf8');
test('client and request cards use identical summary component',async()=>{
 const [client,request,shared]=await Promise.all([load('apps/clients/src/components/ClientCard.vue'),load('apps/clients/src/components/RequestsWorkflowView.vue'),load('packages/ui/src/components/UiClientSummary.vue')]);
 assert.match(client,/<UiClientSummary\b/);
 assert.match(request,/<UiClientSummary\b/);
 assert.match(shared,/irlix-client-summary__tags/);
 assert.match(client,/Название клиента/);
 assert.match(client,/Sales менеджер/);
});
test('history works for leads and clients and scrolls to latest event',async()=>{
 const [lead,client,history,backend]=await Promise.all([load('apps/clients/src/components/LeadCard.vue'),load('apps/clients/src/components/ClientCard.vue'),load('apps/clients/src/components/HistoryTimeline.vue'),load('services/clients/app/Http/Controllers/ClientCardController.php')]);
 assert.match(lead,/<HistoryTimeline/);
 assert.match(client,/<HistoryTimeline/);
 assert.match(client,/label:'История'/);
 assert.match(history,/scrollTop\s*=\s*scrollArea.value.scrollHeight/);
 assert.match(history,/watch\(latestKey/);
 assert.match(backend,/function history\(int \$client\)/);
 assert.match(backend,/act_approved_at/);
 assert.match(backend,/paid_at/);
 assert.match(backend,/member_terms/);
});
test('drawer header matches service topbar token and tab icons have outline stroke',async()=>{
 const [baseCSS,card]=await Promise.all([load('packages/ui/src/styles/base.css'),load('apps/clients/src/components/ClientCard.vue')]);
 assert.match(baseCSS,/\.irlix-drawer-header\s*\{[^}]*height:var\(--irlix-topbar-height\)/s);
 assert.match(card,/grid-template-rows:var\(--irlix-topbar-height\)/);
 assert.match(baseCSS,/\.irlix-tab-icon\s*\{[^}]*stroke:currentColor/);
});
