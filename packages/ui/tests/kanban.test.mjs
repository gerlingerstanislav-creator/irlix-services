import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeKanbanColumns, kanbanItemsFor } from '../src/kanban.js';
import { readFile } from 'node:fs/promises';
test('columns preserve stable order and labels', () => {
  assert.deepEqual(normalizeKanbanColumns(['Новая', 'Интервью']), [
    {id:'Новая',label:'Новая'}, {id:'Интервью',label:'Интервью'}
  ]);
  assert.deepEqual(normalizeKanbanColumns([{id:'a',label:'A'}]), [{id:'a',label:'A'}]);
});
test('cards are counted only within their own status', () => {
  const cards=[{id:1,status:'Новая'},{id:2,status:'Интервью'},{id:3,status:'Новая'}];
  assert.deepEqual(kanbanItemsFor(cards,'status','Новая').map(x=>x.id),[1,3]);
  assert.equal(kanbanItemsFor(cards,'status','Завершена').length,0);
  assert.equal(kanbanItemsFor([{phase:2}], 'phase',2).length,1);
});
test('all live boards use the shared component; domain transitions remain in services',async()=>{
  const base = new URL('../../../apps/clients/src/components/',import.meta.url);
  for (const name of ['RequestsWorkflowView.vue','ReportingPeriodsView.vue','LeadsBoard.vue']) {
    const src=await readFile(new URL(name,base),'utf8');
    assert.match(src,/<UiKanbanBoard\b/);
    assert.match(src,/irlix-kanban-card/);
    assert.match(src,/@drop="/);
  }
  const css=await readFile(new URL('../src/styles/kanban.css',import.meta.url),'utf8');
  assert.match(css,/\.irlix-kanban-column__cards\s*\{/);
  assert.match(css,/overflow-y:auto/);
});
