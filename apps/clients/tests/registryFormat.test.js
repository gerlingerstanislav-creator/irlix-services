import { test } from 'node:test';
import assert from 'node:assert/strict';
import { shortEmployeeName, compactNumber, ascendingTerms, rateDate } from '../src/registryFormat.js';
test('registry names omit patronymics and numbers preserve significant fractions and zero',()=>{
 assert.equal(shortEmployeeName('Демо Иван Отчество'),'Демо Иван');
 assert.equal(shortEmployeeName(''),'—');
 assert.equal(compactNumber('8.00'),'8');assert.equal(compactNumber('7,50'),'7,5');assert.equal(compactNumber(0),'0');assert.equal(compactNumber(null),'—');
});
test('rate history sorts chronologically without mutating source and formats dates',()=>{
 const terms=[{id:2,valid_from:'2026-01-01'},{id:1,valid_from:'2025-01-01'}];
 assert.deepEqual(ascendingTerms({terms}).map(t=>t.id),[1,2]);assert.equal(terms[0].id,2);
 assert.equal(rateDate('2025-01-01'),'1.01.2025');assert.equal(rateDate('2025-12-31'),'31.12.2025');assert.equal(rateDate(null),'по н.в.');
});
