export const migrationNavigation = [
  { id: 'console', label: 'Пульт переноса', icon: 'tasks', href: '/migration/' },
];
export function navigateMigration(section) {
  const item = migrationNavigation.find(item => item.id === section);
  if (item && location.pathname !== item.href) location.assign(item.href);
}
