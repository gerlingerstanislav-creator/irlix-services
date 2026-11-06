import test from 'node:test';
import assert from 'node:assert/strict';
import { buildEmployeeNameMap } from '../src/employeeDisplayMap.js';

test('historical Sales, lead and Account owner labels resolve by Employees id', () => {
  const result = buildEmployeeNameMap(
    [{ id: 7, full_name: 'Носков Александр Александрович' }],
    [{ id: 23, full_name: 'Исторический сейлз' }, { id: 475, full_name: 'Исторический ответственный' }],
  );
  assert.equal(result.get(23), 'Исторический сейлз');
  assert.equal(result.get(475), 'Исторический ответственный');
  assert.equal(result.get(7), 'Носков Александр');
});
test('lead kanban keeps full employee names in both active and historical display', () => {
  const names = buildEmployeeNameMap(
    [{id:7, full_name:'Носков Александр Александрович'}],
    [{id:23, full_name:'Бывший Иван Петрович'}],
    name=>String(name||'').trim(),
  );
  assert.equal(names.get(7), 'Носков Александр Александрович');
  assert.equal(names.get(23), 'Бывший Иван Петрович');
});
test('current employee names override stale historical snapshot and system accounts are not supplied', () => {
  const result = buildEmployeeNameMap([{id:23, full_name:'Актуальный сотрудник'}], [{id:23, full_name:'Старое имя'}]);
  assert.equal(result.get(23), 'Актуальный сотрудник');
  assert.equal(result.get(999), undefined);
});
