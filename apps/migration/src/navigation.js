export const migrationNavigation = [
  { id: 'legacy', label: 'Текущий интерфейс', icon: 'list', href: '/migration/' },
  { id: 'console', label: 'Пульт переноса', icon: 'tasks', href: '/migration/console/' },
];
export function migrationSection(pathname) {
  return pathname === '/migration/console' || pathname === '/migration/console/' ? 'console' : 'legacy';
}
export function navigateMigration(section) {
  const item = migrationNavigation.find(item => item.id === section);
  if (item && location.pathname !== item.href) location.assign(item.href);
}
