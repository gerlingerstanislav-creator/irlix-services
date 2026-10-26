import assert from 'node:assert/strict';
import { test } from 'node:test';
import { visibleDepartmentEmployees, departmentAbsenceGroups, departmentAbsenceListItems, workingDatesForEmployee, monthEmploymentHours } from '../src/departmentAbsenceRoster.js';

const segment = (from, to, department_id, department_name) => ({ from, to, department_id, department_name });
const employee = (id, full_name, employment_segments, employment_status = 'Уволен') =>
  ({ id, full_name, employment_segments, employment_status });
const employees = [
  employee(3, 'Яковлев Борис', [segment('2026-09-01','2026-10-31',2,'Разработка')]),
  employee(1, 'Сидоров Сергей', [segment('2026-01-01','2026-12-31',1,'Аналитика')], 'Трудоустроен'),
  employee(4, 'Борисова Анна', [segment('2026-10-01','2026-10-31',2,'Разработка')]),
  employee(2, 'Иванов Иван', [segment('2026-10-05','2026-10-10',1,'Аналитика')]),
  employee(5, 'Сотрудник Будущий', [segment('2026-11-01','2026-12-31',1,'Аналитика')], 'Трудоустроен'),
  employee(6, 'Сотрудник Бывший', [segment('2026-03-01','2026-09-15',1,'Аналитика')]),
  employee(7, 'Кандидат Павел', [] , 'Ожидает трудоустройства'),
];
const octoberFrom='2026-10-01',octoberTo='2026-10-31';
const weekday=(date)=>{const day=new Date(date+'T00:00:00Z').getUTCDay();return day!==0&&day!==6};

test('selected month includes former employees who worked then, excludes future employees', () => {
  assert.deepEqual(visibleDepartmentEmployees(employees, [], '', octoberFrom, octoberTo).map(p=>p.id), [2,1,4,3]);
  assert.deepEqual(visibleDepartmentEmployees(employees, [], '', '2026-09-01', '2026-09-30').map(p=>p.id), [6,1,3]);
  assert.deepEqual(visibleDepartmentEmployees(employees, [], '', '2026-11-01', '2026-11-30').map(p=>p.id), [1,5]);
});

test('month roster respects department filters and search', () => {
  assert.deepEqual(visibleDepartmentEmployees(employees,[2],'',octoberFrom,octoberTo).map(p=>p.id),[4,3]);
  assert.deepEqual(visibleDepartmentEmployees(employees,[1,2],'иванов',octoberFrom,octoberTo).map(p=>p.id),[2]);
  assert.deepEqual(visibleDepartmentEmployees(employees,[],'кандидат',octoberFrom,octoberTo),[]);
});

test('rehires are shown only in employed periods; gaps contribute no hours', () => {
  const rehire=employee(9,'Тест Повторный',[
    segment('2026-01-01','2026-01-15',1,'Аналитика'),
    segment('2026-03-10','2026-03-15',1,'Аналитика'),
  ]);
  assert.equal(visibleDepartmentEmployees([rehire],[],'','2026-02-01','2026-02-28').length,0);
  assert.equal(workingDatesForEmployee(rehire,'2026-02-01','2026-02-28',weekday).size,0);
  assert.equal(visibleDepartmentEmployees([rehire],[],'','2026-03-01','2026-03-31').length,1);
});

test('month working hours prorate a mid-month hire and clip absences to tenure', () => {
  const hired=employee(8,'Новый Сотрудник',[segment('2026-10-05','2026-10-09',1,'Аналитика')]);
  const absences=[
    {id:1,employee_id:8,starts_on:'2026-10-06',ends_on:'2026-10-07',status:'confirmed'},
    {id:2,employee_id:8,starts_on:'2026-10-01',ends_on:'2026-10-02',status:'confirmed'},
    {id:3,employee_id:8,starts_on:'2026-10-08',ends_on:'2026-10-08',status:'cancelled'},
  ];
  assert.deepEqual(monthEmploymentHours([hired],absences,octoberFrom,octoberTo,weekday),{totalHours:32,absenceHours:16});
});

test('department transfers split workdays; multiple selected departments never double-count a day', () => {
  const transferred=employee(10,'Сотрудник Переведён',[
    segment('2026-10-01','2026-10-14',1,'Аналитика'),
    segment('2026-10-15','2026-10-31',2,'Разработка'),
  ]);
  const analytics=visibleDepartmentEmployees([transferred],[1],'',octoberFrom,octoberTo);
  const development=visibleDepartmentEmployees([transferred],[2],'',octoberFrom,octoberTo);
  const together=visibleDepartmentEmployees([transferred],[1,2],'',octoberFrom,octoberTo);
  assert.equal(analytics.length,1);
  assert.equal(development.length,1);
  assert.equal(together.length,1);
  assert.equal(analytics[0].department_name,'Аналитика');
  assert.equal(development[0].department_name,'Разработка');
  const a=monthEmploymentHours(analytics,[],octoberFrom,octoberTo,weekday).totalHours;
  const d=monthEmploymentHours(development,[],octoberFrom,octoberTo,weekday).totalHours;
  assert.equal(monthEmploymentHours(together,[],octoberFrom,octoberTo,weekday).totalHours,a+d);
  assert.equal(monthEmploymentHours(together,[],octoberFrom,octoberTo,weekday).totalHours,176);
});

test('overlapping identical segments or absences do not multiply calculated hours', () => {
  const duplicate=employee(11,'Сотрудник Дубликат',[
    segment('2026-10-05','2026-10-09',1,'Аналитика'),
    segment('2026-10-06','2026-10-09',1,'Аналитика'),
  ]);
  assert.deepEqual(monthEmploymentHours([duplicate],[
    {employee_id:11,starts_on:'2026-10-06',ends_on:'2026-10-07',status:'confirmed'},
    {employee_id:11,starts_on:'2026-10-07',ends_on:'2026-10-08',status:'confirmed'},
  ],octoberFrom,octoberTo,weekday),{totalHours:32,absenceHours:24});
});

test('every historical employee gets a row in calendar and list even without absences', () => {
  const roster=visibleDepartmentEmployees(employees,[], '', octoberFrom,octoberTo);
  const absences=[{id:20,employee_id:2,starts_on:'2026-10-07',ends_on:'2026-10-08'}];
  const groups=departmentAbsenceGroups(roster,absences,octoberFrom,octoberTo);
  assert.deepEqual(groups.map(g=>g.absences.length),[1,0,0,0]);
  assert.equal(departmentAbsenceListItems(groups).length,4);
  const empty=departmentAbsenceGroups(roster,[],'2026-10-20','2026-10-31');
  assert.ok(empty.every(g=>g.absences.length===0));
  assert.ok(departmentAbsenceListItems(empty).every(item=>item.is_employee_without_absences));
});

test('duplicate employee IDs and missing intervals do not create extra rows',()=>{
  assert.deepEqual(visibleDepartmentEmployees([employees[0],employees[0],employees[6]],[],'',octoberFrom,octoberTo).map(p=>p.id),[3]);
});
