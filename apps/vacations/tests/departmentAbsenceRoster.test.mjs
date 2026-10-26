import assert from 'node:assert/strict';
import { test } from 'node:test';
import { visibleDepartmentEmployees, departmentAbsenceGroups, departmentAbsenceListItems } from '../src/departmentAbsenceRoster.js';

const employees = [
  { id: 3, full_name: 'Яковлев Борис', department_id: 2, department_name: 'Разработка', employment_status: 'Трудоустроен' },
  { id: 1, full_name: 'Сидоров Сергей', department_id: 1, department_name: 'Аналитика', employment_status: 'Трудоустроен' },
  { id: 4, full_name: 'Борисова Анна', department_id: 2, department_name: 'Разработка', employment_status: 'Трудоустроен' },
  { id: 2, full_name: 'Иванов Иван', department_id: 1, department_name: 'Аналитика', employment_status: 'Трудоустроен' },
  { id: 5, full_name: 'Уволен Артём', department_id: 1, department_name: 'Аналитика', employment_status: 'Уволен' },
  { id: 6, full_name: 'Ожидает Павел', department_id: 2, department_name: 'Разработка', employment_status: 'Ожидает трудоустройства' },
];

test('roster contains every employed employee sorted by department, then full name', () => {
  assert.deepEqual(visibleDepartmentEmployees(employees).map(p => p.id), [2, 1, 4, 3]);
});

test('selected departments, multi-select and name search narrow the same roster', () => {
  assert.deepEqual(visibleDepartmentEmployees(employees, [2]).map(p => p.id), [4, 3]);
  assert.deepEqual(visibleDepartmentEmployees(employees, [2, 1], 'Иванов').map(p => p.id), [2]);
  assert.deepEqual(visibleDepartmentEmployees(employees, [], 'Уволен'), []);
});

test('no-absence employees remain visible in every month, including empty month', () => {
  const roster = visibleDepartmentEmployees(employees, [1, 2]);
  const absences = [{ id: 10, employee_id: 2, starts_on: '2026-10-10', ends_on: '2026-10-12' }];
  const october = departmentAbsenceGroups(roster, absences, '2026-10-01', '2026-10-31');
  assert.equal(october.length, 4);
  assert.deepEqual(october.map(group => group.absences.length), [1, 0, 0, 0]);
  const november = departmentAbsenceGroups(roster, absences, '2026-11-01', '2026-11-30');
  assert.equal(november.length, 4);
  assert.ok(november.every(group => group.absences.length === 0));
  assert.equal(departmentAbsenceListItems(november).length, 4);
  assert.ok(departmentAbsenceListItems(november).every(item => item.is_employee_without_absences));
});

test('list rows interleave absences and placeholders in department/name order', () => {
  const roster = visibleDepartmentEmployees(employees);
  const absences = [
    { id: 21, employee_id: 2, starts_on: '2026-10-16', ends_on: '2026-10-17' },
    { id: 20, employee_id: 2, starts_on: '2026-10-04', ends_on: '2026-10-05' },
    { id: 22, employee_id: 3, starts_on: '2026-10-03', ends_on: null },
    { id: 25, employee_id: 5, starts_on: '2026-10-04', ends_on: null },
  ];
  const rows = departmentAbsenceListItems(departmentAbsenceGroups(roster, absences, '2026-10-01', '2026-10-31'));
  assert.deepEqual(rows.map(item => item.id || 'employee-' + item.employee_id), [20, 21, 'employee-1', 'employee-4', 22]);
  assert.equal(rows.some(item => item.employee_id === 5), false);
});

test('duplicate and invalid employee IDs cannot produce duplicate calendar rows', () => {
  assert.deepEqual(visibleDepartmentEmployees([employees[0], employees[0], { id: 0, employment_status: 'Трудоустроен' }]).map(p => p.id), [3]);
});
