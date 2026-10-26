import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('specialist calendar uses one compact filter row, no status column, and continuous bars', () => {
 const view = source('../src/components/SpecialistsAbsenceRoster.vue');
 assert.match(view,/flex-wrap:nowrap/);
 assert.match(view,/specialists-roster__control--department/);
 assert.match(view,/specialists-roster__control--client/);
 assert.doesNotMatch(view,/<th[^>]*>Отсутствия \/ статус<\/th>/);
 assert.doesNotMatch(view,/specialists-roster__summary/);
 assert.match(view,/specialists-roster__absence--start/);
 assert.match(view,/specialists-roster__absence--end/);
 assert.match(view,/height:10px;width:100%/);
 assert.doesNotMatch(view,/specialists-roster__absence span.*border-radius:50%/);
});

test('partner card follows ClientCard shell, About tab and explicit history composer', () => {
 const parent = source('../src/components/PartnersRegistry.vue');
 const tabs = source('../src/components/PartnerProfileSections.vue');
 const app = source('../src/App.vue');
 assert.match(parent,/class="partner-card-overlay"/);
 assert.match(parent,/width:50vw/);
 assert.match(parent,/partner-about-fields/);
 assert.match(parent,/grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);
 assert.match(parent,/class="partner-field__label"/);
 assert.match(parent,/\.partner-child:before/);
 assert.match(parent,/\.partner-child:after/);
 assert.doesNotMatch(parent,/<UiButton v-if="canManage" @click="open\('partner'\)">/);
 assert.match(app,/@click="partnersRegistry\?\.openCreatePartner\(\)"/);
 assert.match(tabs,/value:'about',label:'О партнёре'/);
 assert.match(tabs,/label for="partner-history-comment"/);
 assert.match(tabs,/@submit.prevent="note"/);
 assert.match(tabs,/await mutate\('\/api\/clients\/partners\/'.*\/history'/);
});
