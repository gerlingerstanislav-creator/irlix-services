import test from 'node:test';
import assert from 'node:assert/strict';
import { buildConnectionPeriodLabels } from '../src/management-connection-periods.js';

test('formats a single open-ended client connection', () => {
  const periods = buildConnectionPeriodLabels([
    { client_member_id: 2, valid_from: '2026-01-15', valid_to: null },
  ]);
  assert.equal(periods.get(2), 'с 15.01.2026');
});

test('keeps reconnects and overlapping month terms in chronological order', () => {
  const periods = buildConnectionPeriodLabels([
    { client_member_id: 10, valid_from: '2026-08-01', valid_to: null },
    { client_member_id: 10, valid_from: '2026-03-15', valid_to: '2026-05-20' },
    { client_member_id: 10, valid_from: '2026-03-15', valid_to: '2026-05-20' },
    { client_member_id: 11, valid_from: '2026-01-01', valid_to: '2026-03-01' },
  ]);
  assert.equal(periods.get(10), '15.03.2026–20.05.2026; с 01.08.2026');
  assert.equal(periods.get(11), '01.01.2026–01.03.2026');
});

test('ignores invalid member terms instead of showing invented dates', () => {
  const periods = buildConnectionPeriodLabels([
    { client_member_id: 7, valid_from: null, valid_to: null },
    { client_member_id: 8, valid_from: 'not-a-date', valid_to: null },
  ]);
  assert.equal(periods.size, 0);
});
