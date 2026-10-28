export const routes = [
  { path: '/', name: 'files', meta: { section: 'files' } },
  { path: '/trash', name: 'trash', meta: { section: 'trash' } },
  { path: '/v1', name: 'v1', meta: { section: 'v1' } },
  { path: '/available', name: 'available', meta: { section: 'available' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
