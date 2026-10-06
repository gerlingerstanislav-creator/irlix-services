export function portalNavigation(platformAdmin) {
  const items = [{ id: 'dashboard', label: 'Дашборд', icon: 'dashboard', href: '/' }];
  if (platformAdmin) items.push({ id: 'migration-console', label: 'Пульт переноса', icon: 'tasks', href: '/migration/console/' });
  return items;
}

export function portalSection(pathname) {
  if (pathname === '/migration/console' || pathname === '/migration/console/') return 'migration-console';
  return pathname === '/' ? 'dashboard' : '';
}

export function navigatePortal(section) {
  const item = portalNavigation(true).find(item => item.id === section);
  if (item && location.pathname !== item.href) location.assign(item.href);
}
