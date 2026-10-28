// Timesheets owns its URLs; old ?section= links remain supported.
export const routes = [
  // The bare launcher URL is a permission-aware entry point. Never guess
  // that every admitted Timesheets user can view 'mine': Account Managers may
  // have management.view but no mine.view. App resolves the first permitted
  // page after loading the actual Clients permission matrix.
  { path: '/', name: 'entry', meta: { section: 'entry' }, beforeEnter: to => {
    if (to.query.section === undefined) return true;
    // An explicitly requested legacy ?section=... is a deep link: keep its
    // destination (and its normal 403 if forbidden) rather than bypassing it.
    const section = typeof to.query.section === 'string' ? to.query.section : '';
    const target = ({ mine:'mine', management:'management', partners:'management', analytics:'analytics', audit:'audit' })[section];
    if (!target) return { name:'not-found', params:{pathMatch:'invalid-section'}, query: to.query, hash: to.hash };
    const query = { ...to.query };
    delete query.section;
    return { name: target, query, hash: to.hash };
  } },
  { path: '/mine/', name: 'mine', meta: { section: 'mine' } },
  { path: '/management/', name: 'management', meta: { section: 'management' } },
  // Partner hours are integrated into Management; preserve deep link filters.
  { path: '/partners/', redirect: to => ({ name: 'management', query: to.query, hash: to.hash }) },
  { path: '/commercial-load/', name: 'analytics', meta: { section: 'analytics' } },
  { path: '/audit/', name: 'audit', meta: { section: 'audit' } },
  { path: '/permissions/', name: 'permissions', meta: { section: 'permissions' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', meta: { section: 'not-found' } },
];
