export const migrationNavigation = [
  { id: 'console', label: 'Пульт переноса', icon: 'tasks', href: '/migration/' },
  { id: 'control', label: 'Контроль переноса', icon: 'database', href: '/migration/control/' },
];

export function navigateMigration(section) {
  const item = migrationNavigation.find(entry => entry.id === section);
  if (!item) return;
  const target = item.href + location.search + location.hash;
  if (location.pathname + location.search + location.hash !== target) location.assign(target);
}
