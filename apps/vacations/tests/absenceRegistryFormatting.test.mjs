import test from 'node:test';
import assert from 'node:assert/strict';
import { formatDateNumeric } from '../src/constants.js';

test('registry periods use numeric DD.MM.YYYY dates', () => {
  assert.equal(formatDateNumeric('2026-11-11'), '11.11.2026');
  assert.equal(formatDateNumeric('2026-10-01'), '01.10.2026');
  assert.equal(formatDateNumeric('2026-12-31T00:00:00'), '31.12.2026');
});

test('registry periods handle missing or invalid dates', () => {
  assert.equal(formatDateNumeric(null), '—');
  assert.equal(formatDateNumeric(''), '—');
  assert.equal(formatDateNumeric('date not set'), '—');
});
