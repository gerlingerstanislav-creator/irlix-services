// Page visibility is separate from entity permissions: Clients staff still work
// with positions inside requests, while production heads have two workflow pages.
export const sectionPermissions = {
  clients:'clients.view', leads:'leads.view', contacts:'contacts.view',
  requests:'requests.view', positions:'positions.view', attempts:'attempts.view',
  cashflow:'cashflow.view', reports:'reports.view', permissions:'permissions.view',
};
export function allowedClientSections(access = {}) {
  const admin = !!access.platform_admin;
  return Object.entries(sectionPermissions).filter(([section, permission]) => {
    if (!admin && section === 'positions' && !access.permissions?.['positions.view']?.allowed) return false;
    return !!access.permissions?.[permission]?.allowed ||
      (section === 'attempts' && !!access.permissions?.['attempts.analytics.view']?.allowed);
  }).map(([section]) => section);
}
