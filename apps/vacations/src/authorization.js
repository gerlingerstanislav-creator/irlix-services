/**
 * Vacations business authorization is owned by Vacations, never by the
 * Employees services list or an unverified human-readable role name.
 * Legacy behavior is preserved until the service-local flag is enabled.
 */
export function canVacations(mode, permissions, key, legacy = false) {
  return mode === 'local'
    ? permissions?.[key]?.allowed === true
    : mode === 'legacy' && legacy;
}

export function canEnterVacations(mode, permissions) {
  return mode === 'legacy' || (mode === 'local' && [
    'vacations.mine.view', 'vacations.department.view',
    'vacations.manage.view', 'vacations.approvals.view',
    'vacations.history.view',
  ].some(permission => permissions?.[permission]?.allowed === true));
}

export function visibleVacationsSections(mode, permissions, legacy) {
  const can = (permission, old) => canVacations(mode, permissions, permission, old);
  return {
    mine: can('vacations.mine.view', true),
    department: can('vacations.department.view', legacy.elevated),
    manage: can('vacations.manage.view', legacy.elevated),
    history: can('vacations.history.view', true),
    permissions: legacy.admin,
  };
}
