import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const controller=readFileSync(new URL('../../../services/finance/app/Http/Controllers/SettlementController.php',import.meta.url),'utf8');

function between(start,next){
  const from=controller.indexOf(start);
  const to=controller.indexOf(next,from+start.length);
  assert.ok(from!==-1 && to>from,'controller region must exist: '+start);
  return controller.slice(from,to);
}

test('Every Finance invoice writer uses period-first row locking',()=>{
  for(const [start,end] of [
    ['public function issue(', 'public function cancel('],
    ['public function cancel(', 'private static function settlementIfPaid('],
    ['public function reverseAllocation(', 'public function retry('],
  ]){
    const method=between(start,end);
    const period=method.indexOf('self::lockPeriod(');
    const invoice=method.indexOf("DB::table('invoices')->where('id',");
    assert.ok(period!==-1 && invoice!==-1 && period<invoice,
      start+' must lock period before invoice to avoid cross-operation cycles');
  }
});

test('Multi-invoice payments lock periods in stable sorted order before invoices',()=>{
  const method=between('public function allocate(', 'public function reverseAllocation(');
  const sort=method.indexOf("->unique()->sort()->values()->all()");
  const period=method.indexOf('foreach ($periods as $p) self::lockPeriod(');
  const invoice=method.indexOf('$invoices=DB::table(');
  assert.ok(sort!==-1 && sort<period && period<invoice,
    'payment must acquire period locks in stable ascending order before invoice rows');
});
