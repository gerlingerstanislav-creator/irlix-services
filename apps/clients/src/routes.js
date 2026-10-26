// Clients owns its pages; the platform only routes the /clients/ prefix.
export const routes = [
  { path: '/', name: 'clients', meta: { section: 'clients' } },
  ...['partners','leads','contacts','requests','positions','attempts','members','cashflow','reports','permissions']
    .map(name => ({ path: '/'+name, name, meta: { section: name } })),
  // Historical public URLs remain valid without exposing new permissions.
  { path: '/reporting-periods', name: 'reporting-periods', meta: { section: 'reports' } },
  { path: '/attempt-funnel', name: 'attempt-funnel', meta: { section: 'attempts' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
