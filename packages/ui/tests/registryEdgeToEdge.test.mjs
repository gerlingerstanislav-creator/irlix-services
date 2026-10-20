import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL('../../../' + path, import.meta.url), 'utf8');
const rule = (source, selector) => {
  const pos=source.indexOf(selector);
  assert.ok(pos>=0, 'missing '+selector);
  const start=source.indexOf('{',pos);
  const end=source.indexOf('}',start);
  return source.slice(start+1,end).replace(/\s+/g,' ');
};
const flush = (source, selector) => {
  const css=rule(source,selector);
  assert.match(css,/margin:\s*0(?:px)?\s*;/,selector+' margin');
  assert.match(css,/border:\s*0(?:px)?\s*;/,selector+' frame');
  assert.match(css,/border-radius:\s*0(?:px)?\s*;/,selector+' radius');
};

test('shared list panel removes desktop and responsive exterior gutters',()=>{
  const css=read('packages/ui/src/styles/base.css');
  flush(css,'.irlix-registry-panel {');
  assert.match(css,/\.irlix-registry-panel \{ max-width:100%; margin:0; \}/);
  assert.match(css,/\.workspace > \.ui-panel:has\(\.ui-registry-table\)/);
});
test('Clients positions, requests, contacts and members fill their whole content viewport',()=>{
  const css=read('apps/clients/src/style.css');
  flush(css,'.content>.workflow-view:not(:has(.attempt-kanban)){');
  flush(css,'.content>.clients-simple-registry{');
  assert.doesNotMatch(css,/workflow-view:not\(:has\(\.attempt-kanban\)\)\{margin:10px 12px 12px\}/);
});
test('Vacations list panels and embedded Employee roles are flush, including mobile layouts',()=>{
  const css=read('apps/vacations/src/layout-fixes.css');
  assert.match(css,/max-width:100%!important;\s*margin:0!important;/);
  assert.match(css,/\.vacations-page-panel\{\s*border:0!important;\s*border-radius:0!important;\s*box-shadow:none!important;/);
  assert.doesNotMatch(css,/\.workspace>\.ui-panel\{[^}]*margin:10px 12px 12px!important/);
  const roles=read('apps/web/src/components/SpecialRolesView.vue');
  flush(roles,'.roles-layout { flex:1 1 auto;');
  assert.match(roles,/\.roles-layout \{ display:block; overflow:visible; margin:0; \}/);
});
test('Timesheets analytics and audit are unframed while the management matrix is preserved',()=>{
  const css=read('apps/timesheets/src/extra.css');
  flush(css,'.analytics-page,.audit-page{');
  assert.match(css,/\.management-page\{\s*min-width:0;\s*margin:12px 16px 16px;/);
  assert.match(css,/\.analytics-page,\.audit-page\{margin:0\}/);
});
test('Specialists and four Recruitment lists have flush full-height tables, but not unrelated cards',()=>{
  for (const service of ['specialists','recruitment']) {
    const css=read('apps/'+service+'/src/style.css');
    flush(css,'.content > .table-card--registry{');
    assert.match(css,/\.content\.irlix-service-content:has\(> \.table-card--registry\)\{[^}]*padding:0;overflow:hidden;/);
    assert.match(css,/\.content:has\(> \.table-card--registry\) > \.page-head\{[^}]*padding:12px 16px;/);
  }
  assert.match(read('apps/recruitment/src/AppMvp.vue'),/<template v-else-if="route.section === 'offers'">/);
  assert.match(read('apps/specialists/src/App.vue'),/<template v-else-if="route.section === 'person'">/);
});
