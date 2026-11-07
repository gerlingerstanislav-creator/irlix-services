/**
 * Presentation helpers for Employees' legacy boolean permission matrix.
 * Hiding/reordering a column never changes any backend permission rule.
 */
const BASELINE = {
  'personnel-officer': ['employees.read', 'employees.application.create', 'employees.hire', 'organization.read', 'staff_positions.read', 'employees.transfer'],
  hr: ['employees.read', 'employees.application.create', 'organization.read', 'staff_positions.read'],
  accounting: ['employees.read', 'employees.salary.read', 'employees.salary.manage', 'organization.read', 'staff_positions.read', 'employees.fot.view'],
  managers: ['employees.read', 'employees.application.create', 'employees.salary.read', 'organization.read', 'staff_positions.read', 'employees.fot.view', 'employees.fot.plan', 'employees.fot.approve'],
  'direction-hr': ['employees.read', 'organization.read', 'staff_positions.read'],
  manager: ['employees.read', 'employees.application.create', 'employees.salary.read', 'organization.read', 'staff_positions.read', 'employees.fot.view', 'employees.fot.plan', 'employees.fot.approve'],
};

/** Match EmployeesAccess::withMatrix defaults; the server remains authoritative. */
export function permissionEnabled(role, permission, rules = []) {
  if (role === 'platform-admin') return true;
  const override = rules.find(rule => rule.role === role && rule.permission === permission);
  if (override) return Boolean(override.enabled);
  const baselineRole = role.endsWith('-head') ? 'manager' : role;
  return (BASELINE[baselineRole] || []).includes(permission);
}

/**
 * Scope choices come from the server. The matrix displays a role's own
 * grant; a concrete employee may inherit an independently scoped union.
 */
export function permissionDisplayValue(role, permission, rules = [], scopePolicies = {}) {
  if (role === 'platform-admin') return scopePolicies?.[role]?.[permission] ? 'all' : 'allow';
  if (!permissionEnabled(role, permission, rules)) return 'deny';
  const scope = scopePolicies?.[role]?.[permission];
  if (!scope) return 'allow';
  const override = rules.find(rule => rule.role === role && rule.permission === permission);
  return override?.scope || scope.default;
}

export function permissionOptions(role, permission, scopePolicies = {}) {
  const values = scopePolicies?.[role]?.[permission]?.options || ['deny', 'allow'];
  const labels = {
    deny: 'Нет доступа',
    own: 'В зоне ответственности',
    all: 'Вся компания',
    allow: 'Разрешено',
  };
  return values.map(value => ({ value, label: labels[value] }));
}

export function permissionSections(catalog = {}) {
  const permissions = Object.entries(catalog).map(([key, label]) => ({ key, label }));
  const sections = [
    { key: 'employees', label: 'Сотрудники и ФОТ', matches: key => key.startsWith('employees.') },
    { key: 'organization', label: 'Оргструктура и должности', matches: key => key.startsWith('organization.') || key.startsWith('staff_positions.') },
    { key: 'administration', label: 'Администрирование', matches: key => key === 'access.manage' || key === 'audit.read' },
    { key: 'services', label: 'Доступ к сервисам', matches: key => key.startsWith('services.') },
    { key: 'other', label: 'Прочее', matches: () => true },
  ];
  const assigned = new Set();
  return sections.map(section => {
    const entries = permissions.filter(item => !assigned.has(item.key) && section.matches(item.key));
    entries.forEach(item => assigned.add(item.key));
    return { key: section.key, label: section.label, permissions: entries };
  }).filter(section => section.permissions.length > 0);
}

/**
 * Saved columns are a local display preference, not a set of configured roles.
 * Platform admin remains visible to make its non-editable privileges explicit.
 */
export function orderedRoleColumns(catalog = {}, savedKeys = null) {
  const roles = Object.entries(catalog).map(([key, label]) => ({ key, label, locked: key === 'platform-admin' }));
  if (!Array.isArray(savedKeys)) return roles;
  const byKey = new Map(roles.map(role => [role.key, role]));
  const keys = [...new Set([...savedKeys, 'platform-admin', 'managers'])];
  return keys.filter(key => byKey.has(key)).map(key => byKey.get(key));
}

export function moveRoleColumn(roles, source, target) {
  const from = roles.findIndex(role => role.key === source);
  const to = roles.findIndex(role => role.key === target);
  if (from < 0 || to < 0 || from === to) return roles;
  const result = [...roles];
  const [moved] = result.splice(from, 1);
  result.splice(to, 0, moved);
  return result;
}

export function shiftRoleColumn(roles, key, delta) {
  const at = roles.findIndex(role => role.key === key);
  const next = at + delta;
  if (at < 0 || next < 0 || next >= roles.length) return roles;
  const result = [...roles];
  [result[at], result[next]] = [result[next], result[at]];
  return result;
}
