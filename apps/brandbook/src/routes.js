import { sections, initialSection } from './catalog.js';
// Public brandbook sections belong to this application, not the shared UI library.
export const routes = [
  { path: '/', redirect: { name: initialSection } },
  ...sections.map(({ id }) => ({ path: '/' + id + '/', name: id, meta: { section: id } })),
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
