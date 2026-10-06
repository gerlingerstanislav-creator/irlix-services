import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parsePeriod, shiftPeriod, formatPeriod } from '../src/period.js';

test('month navigation crosses years in both directions', () => {
  assert.equal(formatPeriod(shiftPeriod(parsePeriod('2026-12'), 1)), '2027-01');
  assert.equal(formatPeriod(shiftPeriod(parsePeriod('2026-01'), -1)), '2025-12');
  assert.equal(formatPeriod(shiftPeriod(parsePeriod('2026-10'), 15)), '2028-01');
});
test('year mode preserves a numeric year without inventing a month', () => {
  assert.equal(formatPeriod(shiftPeriod(parsePeriod(2026, 'year'), -1, 'year'), 'year'), 2025);
  assert.deepEqual(parsePeriod('2026', 'year'), { year: 2026, month: 0 });
});
test('invalid and incomplete periods are not normalized into other dates', () => {
  for (const value of ['', '2026-00', '2026-13', '2026-2', '2026-02-01', 'abc']) assert.equal(parsePeriod(value), null);
  assert.equal(parsePeriod('2026-10', 'year'), null);
});


test('period picker focus never scrolls the page', () => {
  const source = readFileSync(new URL('../src/components/UiPeriodPicker.vue', import.meta.url), 'utf8');
  assert.match(source, /focus\(\{ preventScroll: true \}\)/);
});
