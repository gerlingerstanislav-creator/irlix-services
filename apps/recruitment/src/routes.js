export const routes = [
  { path: '/', name: 'dashboard', meta: { section: 'dashboard' } },
  ...['requests','candidates','hiring','interviews','offers','talent-pools','employment','onboarding','tasks','analytics']
    .map(name => ({ path: '/'+name, name, meta: { section: name } })),
  { path: '/candidates/:id(\\d+)', name: 'candidate', meta: { section: 'candidate' } },
  { path: '/role-permissions', name: 'role-permissions', meta: { section: 'role-permissions' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
