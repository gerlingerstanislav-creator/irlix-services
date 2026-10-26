// Page visibility is separate from entity permissions: Clients staff still work
// with positions inside requests, while production heads have two workflow pages.
export const sectionPermissions = {
  clients:'clients.view', partners:'clients.view', specialists:'members.view', members:'members.view', leads:'leads.view', contacts:'contacts.view',
  requests:'requests.view', positions:'positions.view', attempts:'attempts.view',
  cashflow:'cashflow.view', reports:'reports.view', permissions:'permissions.view',
};
export function allowedClientSections(access = {}) {
  const admin = !!access.platform_admin;
  const actorId = Number(access.employee?.id || 0);
  const serviceStaff = actorId > 0 && (access.client_service_employee_ids || []).some(id => Number(id) === actorId);
  return Object.entries(sectionPermissions).filter(([section, permission]) => {
    if (section === 'specialists') return admin || serviceStaff;
    if (['partners','members'].includes(section) && !admin && (access.roles || []).includes('platform-tester')) return false;
    if (section === 'partners' && !admin && access.permissions?.['clients.view']?.scope !== 'all') return false;
    if (!admin && section === 'positions' && !access.permissions?.['positions.view']?.allowed) return false;
    return !!access.permissions?.[permission]?.allowed ||
      (section === 'attempts' && !!access.permissions?.['attempts.analytics.view']?.allowed);
  }).map(([section]) => section);
}
