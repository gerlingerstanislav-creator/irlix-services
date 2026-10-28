export const routes = [
  { path: '/', name: 'registry', meta: { section: 'registry' } },
  { path: '/assignments', name: 'assignments', meta: { section: 'assignments' } },
  { path: '/valuation', name: 'valuation', meta: { section: 'valuation' } },
  { path: '/depreciation', redirect: { name: 'valuation' } },
  { path: '/written-off', name: 'written-off', meta: { section: 'written-off' } },
  { path: '/settings', name: 'settings', meta: { section: 'settings' } },
  { path: '/role-permissions', name: 'role-permissions', meta: { section: 'role-permissions' } },
  { path: '/items/:id(\\d+)', name: 'item', meta: { section: 'registry' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
