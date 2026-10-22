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
    key: 'finance',
    label: 'Финансы',
    items: [
      { key: 'finance', label: 'Финансы', href: '/finance/', icon: 'cash', available: true },
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
      { key: 'system', label:'Система', href:'/system/', icon:'settings', available:true, platformAdminOnly:true },
      { key: 'design-system', label: 'Design System', href: '/design-system/', icon: 'palette', available: true, public: true },
      { key: 'brandbook', label: 'Брендбук', href: '/brandbook/', icon: 'document', available: true, public: true },
      { key: 'brandbook', label: 'Брендбук', href: '/brandbook/', icon: 'document', available: true, public: true },
      { key: 'migration', label: 'Перенос данных', href: '/system/migration/control/', icon: 'tasks', available: true, platformAdminOnly: true, showInLauncher:false },
    ],
  },
];

export const SERVICE_STATUSES = Object.freeze({
  planned: 'Planned',
  inDevelopment: 'In development',
  preproduction: 'Preproduction',
  production: 'Production',
});

// Product lifecycle status is kept in the same catalog as Dashboard/navigation.
// It is an explicit product decision, not a value inferred from deployability or feature count.
const details = {
  dashboard: { description: 'Сервисы компании и быстрый переход между контурами.', audience: ['Сотрудник'], status: SERVICE_STATUSES.production },
  employees: { description: 'Реестр сотрудников, оргструктура, кадровые данные, история ТУ и зарплат.', audience: ['Руководитель', 'HR', 'Finance'], status: SERVICE_STATUSES.preproduction },
  vacations: { description: 'Отпуска, больничные, отгулы и другие типы отсутствий.', audience: ['Сотрудник', 'Руководитель', 'HR', 'Специалист по кадрам', 'Account Manager'], status: SERVICE_STATUSES.preproduction },
  clients: { description: 'Клиенты, проекты, лиды, запросы, условия и отчётные периоды.', audience: ['Account Manager', 'Sales', 'Руководитель направления'], status: SERVICE_STATUSES.preproduction },
  timesheets: { description: 'Учёт времени, подтверждение таймшитов и коммерческая загрузка.', audience: ['Сотрудник', 'Account Manager', 'Руководитель направления'], status: SERVICE_STATUSES.preproduction },
  assessments: { description: 'Сервис пока недоступен.', audience: [], status: SERVICE_STATUSES.planned },
  specialists: { description: 'Специалисты, компетенции и технологии для руководителей направлений.', audience: ['Руководитель направления'], status: SERVICE_STATUSES.inDevelopment },
  equipment: { description: 'Корпоративная техника, выдачи сотрудникам, стоимость и амортизация.', audience: ['Системный администратор', 'Бухгалтерия'], status: SERVICE_STATUSES.preproduction },
  finance: { description: 'Счета, денежный учёт и предварительная финансовая аналитика.', audience: ['Бухгалтерия'], status: SERVICE_STATUSES.preproduction },
  recruitment: { description: 'Кандидаты, заявки на подбор, воронка найма, интервью и офферы.', audience: ['Recruiter', 'HR', 'Руководитель направления'], status: SERVICE_STATUSES.inDevelopment },
  'cv-converter': { description: 'Преобразование исходных CV в стандартизированный формат IRLIX.', audience: [], status: SERVICE_STATUSES.inDevelopment },
  'design-system': { description: 'Компоненты, токены и визуальные правила интерфейсов платформы.', audience: [], status: SERVICE_STATUSES.production },
  brandbook: { description: 'Публичный каталог источников о бренде IRLIX; корпоративные правила ещё не утверждены.', audience: [], status: SERVICE_STATUSES.inDevelopment },
  brandbook: { description: 'Публичная витрина источников об идентичности IRLIX и будущего фирменного стандарта; корпоративные правила ещё не утверждены.', audience: [], status: SERVICE_STATUSES.inDevelopment },
  system: { description:'Ресурсный монитор, карта сервисов и перенос данных.', audience:[], status:SERVICE_STATUSES.preproduction },
  migration: { description: 'Перенос исторических данных из legacy-сервисов в новую платформу.', audience: [], status: SERVICE_STATUSES.preproduction },
};
for (const group of serviceGroups) {
  for (const service of group.items) Object.assign(service, details[service.key]);
}

export const isPlatformAdminAccess = access => Array.isArray(access?.roles)
  && access.roles.some(role => ['platform-admin', 'platform-tester'].includes(String(role).trim().toLowerCase().replaceAll('_', '-')));

// Strict administrator check; the broader helper above intentionally includes testers.
export const isPlatformAdministratorAccess = access => Array.isArray(access?.roles)
  && access.roles.some(role => String(role).trim().toLowerCase().replaceAll('_', '-') === 'platform-admin');

export function getVisibleServiceGroups(platformAdmin = false, access = null) {
  const roles = Array.isArray(access?.roles) ? access.roles.map(role => String(role).trim().toLowerCase().replaceAll('_', '-')) : [];
  const clientsBlocked = roles.includes('platform-tester') && !roles.includes('platform-admin');
  const entitlements = access?.services || null;
  return serviceGroups.map(group => ({
    ...group,
    items: group.items.filter(service => service.showInLauncher !== false).filter(service => service.public === true || (entitlements
      ? entitlements[service.key] === true
      : service.key === 'dashboard')),
  })).filter(group => group.items.length);
}
