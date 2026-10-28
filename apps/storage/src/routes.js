export const routes = [
  { path: '/', name: 'files', meta: { section: 'files' } },
  { path: '/available', name: 'available', meta: { section: 'available' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
