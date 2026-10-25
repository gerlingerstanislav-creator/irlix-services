import { createRouter, createWebHistory } from 'vue-router';

// Employees owns its paths; the platform launcher only knows /employees/.
export const routes = [
  { path: '/', name: 'employees', meta: { section: 'employees' } },
  { path: '/organization', name: 'positions', meta: { section: 'positions' } },
  { path: '/positions', name: 'staffPositions', meta: { section: 'staffPositions' } },
  { path: '/fot', name: 'fot', meta: { section: 'fot' } },
  { path: '/roles', name: 'roles', meta: { section: 'roles' } },
  { path: '/permissions', name: 'permissions', meta: { section: 'permissions' } },
  { path: '/audit', name: 'audit', meta: { section: 'audit' } },
  // Legacy Employees URLs normalize without accidentally changing the new positions catalog.
  { path: '/departments', redirect: { name: 'positions' } },
  { path: '/staff-positions', redirect: { name: 'positions' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});
