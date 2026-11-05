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
  }
  if (canManageRoleMatrix) {
    result.push({ id: 'role-permissions', label: 'Разрешения', icon: 'settings' });
  }
  return result;
}
