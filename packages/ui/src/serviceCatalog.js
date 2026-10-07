export const serviceGroups = [
  {
    key: 'employees',
    label: 'Сотрудники',
    items: [
      { key: 'dashboard', label: 'Дашборд', href: '/', icon: 'dashboard', available: true },
      { key: 'employees', label: 'Сотрудники', href: '/employees/', icon: 'users', available: true },
      { key: 'vacations', label: 'Отсутствия', href: '/vacations/', icon: 'vacation', available: true },
    ],
  },
  {
    key: 'clients',
    label: 'Клиентские сервисы',
    items: [
      { key: 'clients', label: 'Клиенты', href: '/clients/', icon: 'briefcase', available: true },
      { key: 'timesheets', label: 'Таймшиты', href: '/timesheets/', icon: 'hourglass', available: true },
      { key: 'assessments', label: 'Оценки', icon: 'assessment', available: false },
    ],
  },
  {
    key: 'it',
    label: 'IT',
    items: [
      { key: 'specialists', label: 'Специалисты', href: '/specialists/', icon: 'code', available: true },
      { key: 'equipment', label: 'Учёт техники', href: '/equipment/', icon: 'briefcase', available: true },
    ],
  },
  {
    key: 'recruitment',
    label: 'Recruitment',
    items: [
      { key: 'recruitment', label: 'Recruitment', href: '/recruitment/', icon: 'users', available: true },
      { key: 'cv-converter', label: 'CV конвертер', href: '/cv-converter/', icon: 'document', available: true, platformAdminOnly: true },
    ],
  },
  {
    key: 'system',
    label: 'Системные',
    items: [
      { key: 'design-system', label: 'Design System', href: '/design-system/', icon: 'palette', available: true, platformAdminOnly: true },
      { key: 'migration', label: 'Перенос данных', href: '/migration/', icon: 'tasks', available: true, platformAdminOnly: true },
    ],
  },
];

// Descriptions and informational audience labels belong to the same catalog as navigation.
const details = {
  dashboard: { description: 'Сервисы компании и быстрый переход между контурами.', audience: ['Сотрудник'] },
  employees: { description: 'Реестр сотрудников, оргструктура, кадровые данные, история ТУ и зарплат.', audience: ['Руководитель', 'HR', 'Finance'] },
  vacations: { description: 'Отпуска, больничные, отгулы и другие типы отсутствий.', audience: ['Сотрудник', 'Руководитель', 'HR', 'Специалист по кадрам', 'Account Manager'] },
  clients: { description: 'Клиенты, проекты, лиды, запросы, условия и отчётные периоды.', audience: ['Account Manager', 'Sales', 'Руководитель направления'] },
  timesheets: { description: 'Учёт времени, подтверждение таймшитов и коммерческая загрузка.', audience: ['Сотрудник', 'Account Manager', 'Руководитель направления'] },
  assessments: { description: 'Сервис пока недоступен.', audience: [] },
  specialists: { description: 'Специалисты, компетенции и технологии для руководителей направлений.', audience: ['Руководитель направления'] },
  equipment: { description: 'Корпоративная техника, выдачи сотрудникам, стоимость и амортизация.', audience: ['Системный администратор', 'Бухгалтерия'] },
  recruitment: { description: 'Кандидаты, заявки на подбор, воронка найма, интервью и офферы.', audience: ['Recruiter', 'HR', 'Руководитель направления'] },
  'cv-converter': { description: 'Преобразование исходных CV в стандартизированный формат IRLIX.', audience: [] },
  'design-system': { description: 'Компоненты, токены и визуальные правила интерфейсов платформы.', audience: [] },
  migration: { description: 'Перенос исторических данных из legacy-сервисов в новую платформу.', audience: [] },
};
for (const group of serviceGroups) {
  for (const service of group.items) Object.assign(service, details[service.key]);
}

export const isPlatformAdminAccess = access => Array.isArray(access?.roles)
  && access.roles.some(role => ['platform-admin', 'platform-tester'].includes(String(role).trim().toLowerCase().replaceAll('_', '-')));

export function getVisibleServiceGroups(platformAdmin = false) {
  return serviceGroups.map(group => ({
    ...group,
    items: group.items.filter(service => !service.platformAdminOnly || platformAdmin),
  })).filter(group => group.items.length);
}
