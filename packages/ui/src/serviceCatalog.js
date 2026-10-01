export const serviceGroups = [
  {
    label: 'Сотрудники',
    items: [
      { key: 'dashboard', label: 'Дашборд сотрудника', href: '/', icon: 'dashboard', available: true },
      { key: 'employees', label: 'Сотрудники', href: '/employees/', icon: 'users', available: true },
      { key: 'vacations', label: 'Отпуска', href: '/vacations/', icon: 'vacation', available: true },
    ],
  },
  {
    label: 'Клиентские сервисы',
    items: [
      { key: 'clients', label: 'Клиенты', href: '/clients/', icon: 'briefcase', available: true },
      { key: 'timesheets', label: 'Таймшиты', href: '/timesheets/', icon: 'hourglass', available: true },
      { key: 'assessments', label: 'Оценки', icon: 'assessment', available: false },
    ],
  },
  {
    label: 'IT',
    items: [
      { key: 'specialists', label: 'Специалисты', href: '/specialists/', icon: 'code', available: true },
    ],
  },
  {
    label: 'Recruitment',
    items: [
      { key: 'recruitment', label: 'Recruitment', href: '/recruitment/', icon: 'users', available: true },
      { key: 'cv-converter', label: 'CV конвертер', href: '/cv-converter/', icon: 'document', available: true },
    ],
  },
  {
    label: 'Системные',
    items: [
      { key: 'design-system', label: 'Design System', href: '/design-system/', icon: 'palette', available: true },
    ],
  },
];
