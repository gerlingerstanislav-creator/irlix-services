import { matchesEmployeeName } from './employeeNameSearch.js';

const compareRussian = (left, right) => String(left || '').localeCompare(String(right || ''), 'ru', { sensitivity: 'base' });
const iso = (value) => String(value || '').slice(0, 10);
const overlaps = (from, to, otherFrom, otherTo) => from <= otherTo && to >= otherFrom;

/**
 * Historical employees already have server-scoped department segments from Employees.
 * A selected month may span a transfer: the person is shown once and only their
 * matching segment days contribute to the totals.
 */
export function visibleDepartmentEmployees(employees, departmentIds = [], search = '', from = '0000-01-01', to = '9999-12-31') {
  const selected = new Set((departmentIds || []).map((id) => String(id)));
  const seen = new Set();
  return (employees || []).flatMap((employee) => {
    const id = Number(employee?.id);
    if (!Number.isSafeInteger(id) || id <= 0 || seen.has(id)) return [];
    if (!matchesEmployeeName(employee.full_name, search)) return [];
    const segments = (employee.employment_segments || [])
      .filter(segment => (!selected.size || selected.has(String(segment.department_id ?? '')))
        && overlaps(iso(segment.from), iso(segment.to), from, to))
      .sort((a, b) => iso(a.from).localeCompare(iso(b.from)) || iso(a.to).localeCompare(iso(b.to)));
    if (!segments.length) return [];
    seen.add(id);
    const last = segments.at(-1);
    return [{ ...employee, employment_segments: segments,
      department_id: last.department_id, department_name: last.department_name }];
  }).sort((a, b) => compareRussian(a.department_name || 'Без подразделения', b.department_name || 'Без подразделения')
    || compareRussian(a.full_name, b.full_name)
    || Number(a.id) - Number(b.id));
}

export function departmentAbsenceGroups(employees, absences, from, to) {
  const byEmployee = new Map(employees.map((person) => [Number(person.id), []]));
  for (const absence of absences) {
    const list = byEmployee.get(Number(absence.employee_id));
    if (list && overlaps(iso(absence.starts_on), iso(absence.ends_on || to), from, to)) list.push(absence);
  }
  return employees.map(employee => ({
    employee_id: Number(employee.id),
    employee_name: employee.full_name,
    department_id: employee.department_id,
    department_name: employee.department_name,
    employment_segments: employee.employment_segments,
    absences: byEmployee.get(Number(employee.id)).map(a => ({
      ...a, employee_name: employee.full_name,
      department_id: employee.department_id, department_name: employee.department_name,
    })).sort((a, b) => String(a.starts_on).localeCompare(String(b.starts_on)) || Number(a.id) - Number(b.id)),
  }));
}

export function departmentAbsenceListItems(groups) {
  return groups.flatMap(group => group.absences.length
    ? group.absences.filter(absence => group.employment_segments.some(segment =>
      overlaps(iso(absence.starts_on), iso(absence.ends_on || '9999-12-31'), iso(segment.from), iso(segment.to))))
    : [{
      employee_id: group.employee_id, employee_name: group.employee_name,
      department_id: group.department_id, department_name: group.department_name,
      is_employee_without_absences: true,
    }]);
}

export function workingDatesForEmployee(employee, from, to, isWorkingDate) {
  const dates = new Set();
  for (const segment of employee.employment_segments || []) {
    const start = iso(segment.from) > from ? iso(segment.from) : from;
    const end = iso(segment.to) < to ? iso(segment.to) : to;
    if (start > end) continue;
    for (let timestamp = Date.parse(start + 'T00:00:00Z'); timestamp <= Date.parse(end + 'T00:00:00Z'); timestamp += 86400000) {
      const date = new Date(timestamp).toISOString().slice(0, 10);
      if (isWorkingDate(date)) dates.add(date);
    }
  }
  return dates;
}

export function monthEmploymentHours(employees, absences, from, to, isWorkingDate) {
  const absencesById = new Map();
  for (const absence of absences) {
    if (['rejected', 'cancelled'].includes(absence.status)) continue;
    const id = Number(absence.employee_id);
    if (!absencesById.has(id)) absencesById.set(id, []);
    absencesById.get(id).push(absence);
  }
  let totalDays = 0, absentDays = 0;
  for (const employee of employees) {
    const dates = workingDatesForEmployee(employee, from, to, isWorkingDate);
    totalDays += dates.size;
    const occupied = new Set();
    for (const absence of absencesById.get(Number(employee.id)) || []) {
      for (const date of dates) {
        if (date >= iso(absence.starts_on) && date <= iso(absence.ends_on || to)) occupied.add(date);
      }
    }
    absentDays += occupied.size;
  }
  return { totalHours: totalDays * 8, absenceHours: absentDays * 8 };
}
