import test from 'node:test';
import assert from 'node:assert/strict';
import { clientPeriodApproved } from '../src/approval-state.js';

test('sent historical reporting periods imply account approval only within their client and dates', () => {
  for (const status of ['ТШ на согласовании','ТШ согласованы','Акт на согласовании','Акт согласован','Счет оплачен']) {
    const source = {client_reporting_periods:[{client_id:7,period_start:'2026-01-05',period_end:'2026-01-25',status}]};
    assert.equal(clientPeriodApproved(source,7,'2026-01-05'),true);
    assert.equal(clientPeriodApproved(source,7,'2026-01-25'),true);
    assert.equal(clientPeriodApproved(source,8,'2026-01-05'),false);
    assert.equal(clientPeriodApproved(source,7,'2026-01-26'),false);
  }
});
test('new, missing or unknown period does not imply approval', () => {
  for (const status of ['Новый', null, 'Unknown']) assert.equal(clientPeriodApproved({client_reporting_periods:[{client_id:7,period_start:'2026-01-01',period_end:'2026-01-31',status}]},7,'2026-01-05'),false);
  assert.equal(clientPeriodApproved(null,7,'2026-01-05'),false);
});
