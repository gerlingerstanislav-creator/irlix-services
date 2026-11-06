import { displayEmployeeName } from './displayEmployeeName.js';

// Historical names are display-only. Selection is intentionally based on the
// active employees list returned by Employees, not this union.
export function buildEmployeeNameMap(active = [], historical = [], formatter = displayEmployeeName) {
  return new Map([...historical, ...active].map(employee => [
    Number(employee.id),
    formatter(employee.full_name) || `#${employee.id}`,
  ]));
}
