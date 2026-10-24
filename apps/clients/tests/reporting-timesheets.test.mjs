import test from 'node:test';
import assert from 'node:assert/strict';
import { groupReportingTimesheets } from '../src/reportingTimesheets.js';

test('commercial reporting aggregates a specialist across project lineage and preserves rate versions', () => {
  const rows = [
    { employee_id: 7, client_member_id: 20, project_id: 2, term_id: 101, valid_from: '2026-01-01', hourly_rate: 100, worked_hours: 4, confirmed_hours: 3, approved_overtime_hours: 1, worked_amount: 400, confirmed_amount: 300, account_confirmed: true },
    { employee_id: 7, client_member_id: 20, project_id: 3, term_id: 101, valid_from: '2026-01-01', hourly_rate: 100, worked_hours: 2, confirmed_hours: 2, approved_overtime_hours: 0, worked_amount: 200, confirmed_amount: 200, account_confirmed: true },
    { employee_id: 7, client_member_id: 20, project_id: 3, term_id: 102, valid_from: '2026-01-20', hourly_rate: 120, worked_hours: 3, confirmed_hours: 3, approved_overtime_hours: 0, worked_amount: 360, confirmed_amount: 360, account_confirmed: true },
  ];
  const [row] = groupReportingTimesheets(rows);
  assert.equal(groupReportingTimesheets(rows).length, 1);
  assert.equal(row.worked_hours, 9);
  assert.equal(row.confirmed_hours, 8);
  assert.equal(row.worked_amount, 960);
  assert.equal(row.confirmed_amount, 860);
  assert.deepEqual(row.rates.map(rate => [rate.term_id, rate.worked_hours]), [[101, 6], [102, 3]]);
  assert.equal('project_id' in row, false);
});

test('partner rows keep their independent partner identity and do not acquire an employee id', () => {
  const grouped = groupReportingTimesheets([
    { employee_id: null, partner_specialist_id: 9, client_member_id: 30, term_id: 201, valid_from: '2026-02-01', hourly_rate: 80, worked_hours: 7.33, confirmed_hours: 7.33, approved_overtime_hours: 0, worked_amount: 586.4, confirmed_amount: 586.4, account_confirmed: true },
  ]);
  assert.equal(grouped.length, 1);
  assert.equal(grouped[0].employee_id, null);
  assert.equal(grouped[0].partner_specialist_id, 9);
  assert.equal(grouped[0].worked_hours, 7.33);
});
