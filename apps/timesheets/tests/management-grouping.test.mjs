import test from 'node:test';
import assert from 'node:assert/strict';
import { buildManagementGroups } from '../src/management-grouping.js';

const clients = [
  { value: '2', label: 'Альфа' },
  { value: '1', label: 'Бета' },
  { value: '3', label: 'Пустой клиент' },
];
const rows = [
  { memberId: 11, clientId: 1, isPartner: false },
  { memberId: 22, clientId: 2, isPartner: true },
];
const dates = ['2026-10-01', '2026-10-02'];

test('groups filtered rows once, preserves option ordering, omits empty client groups', () => {
  const groups = buildManagementGroups(clients, rows, dates, (row, date) => ({
    date, active: true, final: true, hours: row.memberId,
  }));
  assert.deepEqual(groups.map((group) => group.label), ['Альфа', 'Бета']);
  assert.deepEqual(groups.map((group) => group.rows[0].memberId), [22, 11]);
  assert.deepEqual(groups[0].rows[0].cells.map((cell) => cell.date), dates);
});

test('totals and monthly approval derive from one set of cells', () => {
  const [group] = buildManagementGroups([{ value: '1', label: 'Бета' }], rows, dates, (_row, date) => ({
    date, active: true, final: date === '2026-10-01', hours: date === '2026-10-01' ? 7.5 : 2.25,
  }));
  assert.equal(group.rows[0].monthlyHours, 9.75);
  assert.deepEqual(group.rows[0].monthApproval, { all: false, any: true });
});

test('inactive cells are not counted for approval, but their existing hours stay in total', () => {
  const [group] = buildManagementGroups([{ value: '2', label: 'Альфа' }], rows, dates, (_row, date) => ({
    date, active: date === '2026-10-01', final: date === '2026-10-01', hours: 4,
  }));
  assert.deepEqual(group.rows[0].monthApproval, { all: true, any: true });
  assert.equal(group.rows[0].monthlyHours, 8);
});

test('a row without active days is neither fully nor partially approved', () => {
  const [group] = buildManagementGroups([{ value: '1', label: 'Бета' }], rows, dates, () => ({
    active: false, final: false, hours: 0,
  }));
  assert.deepEqual(group.rows[0].monthApproval, { all: false, any: false });
});
