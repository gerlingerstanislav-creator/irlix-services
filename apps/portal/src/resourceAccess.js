// The resource monitor intentionally excludes platform-tester, unlike the general catalog.
export const canViewResources = access => Array.isArray(access?.roles)
  && access.roles.some(role => ['platform-admin', 'system-admin'].includes(role));
export const dashboardSection = path => /^\/resources\/?$/.test(path) ? 'resources' : 'dashboard';
