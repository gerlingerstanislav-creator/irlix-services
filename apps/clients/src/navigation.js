// Page visibility is separate from entity permissions: Clients staff still work
// with positions inside requests, while production heads have two workflow pages.
export const sectionPermissions = {
  clients:'clients.view', leads:'leads.view', contacts:'contacts.view',
  requests:'requests.view', positions:'positions.view', attempts:'attempts.view',
  cashflow:'cashflow.view', reports:'reports.view', permissions:'permissions.view',
};
export function allowedClientSections(access = {}) {
  if (access.clients_service_blocked) return [];
  const admin = !!access.platform_admin;
  const productionHead = (access.roles || []).includes('department-manager');
  return Object.entries(sectionPermissions).filter(([section, permission]) => {
    if (!admin && (productionHead ? !['positions','attempts'].includes(section) : section === 'positions')) return false;
    return !!access.permissions?.[permission]?.allowed ||
      (section === 'attempts' && !!access.permissions?.['attempts.analytics.view']?.allowed);
  }).map(([section]) => section);
}
