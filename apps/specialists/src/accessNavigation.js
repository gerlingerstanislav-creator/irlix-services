/** Visible sections are derived from the same backend grants that guard requests. */
export function accessibleSpecialistsNavigation(permissions, canManageRoleMatrix = false) {
  const result = [];
  if (permissions?.profiles_view === true) {
    result.push(
      { id: 'dashboard', label: 'Дашборд', icon: 'dashboard' },
      { id: 'people', label: 'Специалисты', icon: 'users' },
    );
  }
  if (permissions?.catalog_view === true) {
    result.push({ id: 'competencies', label: 'Технологии и компетенции', icon: 'code' });
    result.push({ id: 'technologies', label: 'Дополнительные технологии', icon: 'list' });
  }
  if (canManageRoleMatrix) {
    result.push({ id: 'role-permissions', label: 'Разрешения', icon: 'permissions' });
  }
  return result;
}

/** The service entry URL must land on a screen the current role can read. */
export function specialistsLandingPath(permissions) {
  if (permissions?.profiles_view === true) return '/';
  if (permissions?.catalog_view === true) return '/competencies';
  return null;
}
