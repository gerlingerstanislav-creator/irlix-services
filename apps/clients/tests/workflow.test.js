import test from 'node:test';
import assert from 'node:assert/strict';
import { sortNewest, positionMatchesStatus, requestDisplayStatus, requestMatchesStatus, attemptStageColors, attemptEmployeeOptions } from '../src/workflow.js';

test('open filter includes positions in work; newest records appear first without mutating props', () => {
  const input = [{ id: 1, created_at: '2026-09-01' }, { id: 2, created_at: '2026-09-30' }, { id: 3, created_at: '2026-09-30' }];
  assert.deepEqual(sortNewest(input).map(item => item.id), [3, 2, 1]);
  assert.equal(input[0].id, 1);
  assert.equal(positionMatchesStatus({ status: 'Открыт', display_status: 'В работе' }, 'Открыт'), true);
  assert.equal(positionMatchesStatus({ status: 'Закрыт' }, 'Открыт'), false);
  assert.equal(positionMatchesStatus({ status: 'Открыт', display_status: 'В работе' }, 'В работе'), true);
});

test('progress preserves completed stages and leaves future stages empty on failure', () => {
  const expected = new Map([
    ['Новая', ['empty', 'empty', 'empty']], ['CV отправлено', ['pending', 'empty', 'empty']],
    ['Интервью назначено', ['done', 'pending', 'empty']], ['Интервью пройдено', ['done', 'done', 'empty']],
    ['Ожидает подключения', ['done', 'done', 'pending']], ['Закрыт: успех', ['done', 'done', 'done']],
  ]);
  for (const [status, colors] of expected) assert.deepEqual(attemptStageColors({ status }), colors);
  for (const stage of ['Новая', 'CV отправлено']) assert.deepEqual(attemptStageColors({ status: 'Закрыт: неудача', closed_from_status: stage }), ['failed', 'empty', 'empty']);
  for (const stage of ['Интервью назначено', 'Интервью пройдено']) assert.deepEqual(attemptStageColors({ status: 'Закрыт: неудача', closed_from_status: stage }), ['done', 'failed', 'empty']);
  assert.deepEqual(attemptStageColors({ status: 'Закрыт: неудача', closed_from_status: 'Ожидает подключения' }), ['done', 'done', 'failed']);
  assert.deepEqual(attemptStageColors({ status: 'Закрыт: неудача' }), ['empty', 'empty', 'failed']);
});

test('employee tree includes production ancestors, sorts siblings, and excludes unmarked departments', () => {
  const departments = [
    { id: 1, name: 'Synthetic A', is_production: true },
    { id: 2, name: 'Synthetic B', parent_id: 1, is_production: true },
    { id: 3, name: 'Synthetic A child', parent_id: 1, is_production: true },
    { id: 4, name: 'Synthetic Office', parent_id: 1, is_production: false },
  ];
  const employees = [{ id: 10, department_id: 2, full_name: 'Synthetic B' }, { id: 11, department_id: 3, full_name: 'Synthetic A' }];
  const options = attemptEmployeeOptions(employees, departments, [10, 11]);
  assert.deepEqual(options.map(option => option.value), ['department:1', 'department:3', '11', 'department:2', '10']);
  assert.deepEqual(options.map(option => option.depth), [0, 1, 2, 1, 2]);
  assert.equal(options[0].kind, 'group');
  assert.deepEqual(attemptEmployeeOptions(employees, departments, [10]).filter(option => option.kind !== 'group').map(option => option.value), ['10']);
});

test('request work status follows positions without changing explicit open and closed states', () => {
  const request = { status: 'Открыт', positions: [{ status: 'Открыт', display_status: 'В работе' }, { status: 'Закрыт' }] };
  assert.equal(requestDisplayStatus(request), 'В работе');
  assert.equal(request.status, 'Открыт');
  assert.equal(requestMatchesStatus(request, 'Открыт'), true);
  assert.equal(requestMatchesStatus(request, 'В работе'), true);
  assert.equal(requestMatchesStatus(request, 'Закрыт'), false);
  assert.equal(requestDisplayStatus({ ...request, status: 'Закрыт' }), 'Закрыт');
  assert.equal(requestDisplayStatus({ status: 'Открыт', positions: [{ status: 'Закрыт' }] }), 'Открыт');
  assert.equal(requestDisplayStatus({ status: 'Открыт', positions: [] }), 'Открыт');
  assert.equal(requestMatchesStatus({ status: 'Закрыт' }, 'Открыт'), false);
});
