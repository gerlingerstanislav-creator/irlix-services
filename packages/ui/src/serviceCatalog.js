export const serviceGroups = [
  {
    label: 'Сотрудники',
    items: [
      { key: 'dashboard', label: 'Дашборд сотрудника', href: '/', icon: 'dashboard', available: true },
      { key: 'employees', label: 'Сотрудники', href: '/employees/', icon: 'users', available: true },
      { key: 'vacations', label: 'Отпуска', href: '/vacations/', icon: 'vacation', available: true },
      { key: 'specialists', label: 'Специалисты', icon: 'code', available: false },
    ],
  },
  {
    label: 'Клиентские сервисы',
    items: [
      { key: 'clients', label: 'Клиенты', href: '/clients/', icon: 'briefcase', available: true },
      { key: 'timesheets', label: 'Таймшиты', icon: 'hourglass', available: false },
      { key: 'assessments', label: 'Оценки', icon: 'assessment', available: false },
    ],
  },
  {
    label: 'Ведение проектов',
    items: [
      { key: 'task-tracker', label: 'Трекер задач', icon: 'tasks', available: false },
    ],
  },
  {
    label: 'Системные',
    items: [
      { key: 'design-system', label: 'Design System', href: '/design-system/', icon: 'palette', available: true },
    ],
  },
];
