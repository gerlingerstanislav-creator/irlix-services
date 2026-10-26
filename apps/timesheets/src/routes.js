// Timesheets owns its URLs; old ?section= links remain supported.
export const routes = [
  { path: '/', redirect: to => {
    const section = typeof to.query.section === 'string' ? to.query.section : 'mine';
    const target = ({ mine:'mine', management:'management', partners:'partners', analytics:'analytics', audit:'audit' })[section];
    if (!target) return { name:'not-found', params:{pathMatch:'invalid-section'}, query: to.query, hash: to.hash };
    const query = { ...to.query };
    delete query.section;
    return { name: target, query, hash: to.hash };
  } },
  { path: '/mine/', name: 'mine', meta: { section: 'mine' } },
  { path: '/management/', name: 'management', meta: { section: 'management' } },
  { path: '/partners/', name: 'partners', meta: { section: 'management' } },
  { path: '/commercial-load/', name: 'analytics', meta: { section: 'analytics' } },
  { path: '/audit/', name: 'audit', meta: { section: 'audit' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
