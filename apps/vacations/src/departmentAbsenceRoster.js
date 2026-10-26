import { matchesEmployeeName } from './employeeNameSearch.js';

const compareRussian = (left, right) => String(left || '').localeCompare(String(right || ''), 'ru', { sensitivity: 'base' });

/** Employees /vacations-directory is already scoped. Filters here only narrow that trusted directory. */
export function visibleDepartmentEmployees(employees, departmentIds = [], search = '') {
  const selected = new Set((departmentIds || []).map((id) => String(id)));
  const seen = new Set();
  return (employees || [])
    .filter((employee) => {
      const id = Number(employee?.id);
      if (!Number.isSafeInteger(id) || id <= 0 || seen.has(id)) return false;
      if (employee.employment_status !== 'Трудоустроен') return false;
      if (selected.size && !selected.has(String(employee.department_id ?? ''))) return false;
      if (!matchesEmployeeName(employee.full_name, search)) return false;
      seen.add(id);
      return true;
    })
    .sort((a, b) => compareRussian(a.department_name || 'Без подразделения', b.department_name || 'Без подразделения')
      || compareRussian(a.full_name, b.full_name)
      || Number(a.id) - Number(b.id));
}

export function departmentAbsenceGroups(employees, absences, from, to) {
  const byEmployee = new Map(employees.map((person) => [Number(person.id), []]));
  for (const absence of absences) {
    const list = byEmployee.get(Number(absence.employee_id));
    if (list && absence.starts_on <= to && (!absence.ends_on || absence.ends_on >= from)) list.push(absence);
  }
  return employees.map((employee) => ({
    employee_id: Number(employee.id),
    employee_name: employee.full_name,
    department_id: employee.department_id,
    department_name: employee.department_name,
    absences: byEmployee.get(Number(employee.id)).sort((a, b) =>
      String(a.starts_on).localeCompare(String(b.starts_on)) || Number(a.id) - Number(b.id)),
  }));
}

export function departmentAbsenceListItems(groups) {
  return groups.flatMap((group) => group.absences.length
    ? group.absences
    : [{
      employee_id: group.employee_id,
      employee_name: group.employee_name,
      department_id: group.department_id,
      department_name: group.department_name,
      is_employee_without_absences: true,
    }]);
}
