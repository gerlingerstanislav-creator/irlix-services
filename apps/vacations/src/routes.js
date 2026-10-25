// Vacations owns its paths independently from the platform's service launcher.
export const routes = [
  { path: '/', name: 'mine', meta: { section: 'mine' } },
  { path: '/department/', name: 'department', meta: { section: 'department' } },
  { path: '/manage/', name: 'manage', meta: { section: 'manage' } },
  { path: '/history/', name: 'history', meta: { section: 'history' } },
  { path: '/mine/', redirect: { name: 'mine' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
