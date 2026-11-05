export const routes = [
 { path: '/', redirect: { name: 'convert' } },
 { path: '/convert/', name: 'convert', meta: { section:'convert' } },
 { path: '/settings/', name: 'settings', meta: { section:'settings' } },
 { path: '/permissions/', name: 'permissions', meta: { section:'permissions' } },
 { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section:'not-found' } },
];
