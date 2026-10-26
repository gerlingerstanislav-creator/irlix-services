// Clients owns its pages; the platform only routes the /clients/ prefix.
// Preserve the legacy /clients/ entry, but use the documented /clients/clients/ URL.
export const routes = [
  { path: '/', redirect: to => ({ name: 'clients', query: to.query, hash: to.hash }) },
  { path: '/clients/', name: 'clients', meta: { section: 'clients' } },
  ...['partners','leads','contacts','requests','positions','attempts','members','cashflow','permissions']
    .map(name => ({ path: '/' + name + '/', name, meta: { section: name } })),
  { path: '/reporting-periods/', name: 'reports', meta: { section: 'reports' } },
  // Links created by the previous router must keep working without losing context.
  { path: '/reports/', redirect: to => ({ name: 'reports', query: to.query, hash: to.hash }) },
  { path: '/attempt-funnel/', name: 'attempt-funnel', meta: { section: 'attempts' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
