import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const base=new URL('../../../',import.meta.url);
const read=p=>readFileSync(new URL(p,base),'utf8');

test('Raw Finance payout registers require salary detail permission',()=>{
  const routes=read('services/finance/routes/api.php');
  const receipts=routes.slice(routes.indexOf("Route::get('/receipts'"),routes.indexOf("Route::post('/receipts'"));
  const cash=routes.slice(routes.indexOf("Route::get('/cash-flow'"),routes.indexOf("Route::get('/pnl'"));
  assert.ok(receipts.includes("where('ct.direction','in')"));
  assert.ok(receipts.includes('finance.salary.view'));
  assert.ok(cash.includes("if(!$canReadPayoutDetails)$q->where('direction','in')"));
  assert.ok(cash.includes("'incoming_only'"));
  assert.ok(cash.includes("where('status','confirmed')"));
  assert.ok(cash.includes("->selectRaw(\"SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) as outcome\")"));
});

test('Finance UI signals that outgoing details are redacted',()=>{
  const ui=read('apps/finance/src/App.vue');
  assert.ok(ui.includes("cashflow?.detail_scope==='incoming_only'"));
  assert.ok(ui.includes("can('finance.expenses.manage') && can('finance.salary.view')"));
  assert.ok(ui.includes('Итоговые остатки всё равно включают все подтверждённые списания.'));
});
