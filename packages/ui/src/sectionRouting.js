const serviceRoutes = [
  {
    base: '/employees/',
    defaultRoute: 'employees',
    routes: {
      employees: 'Сотрудники',
      departments: 'Подразделения',
      roles: 'Роли',
      audit: 'История действий',
    },
  },
  {
    base: '/vacations/',
    defaultRoute: 'mine',
    routes: {
      mine: 'Мои отпуска',
      department: 'Отпуска подразделения',
      management: 'Управление отпусками',
      audit: 'История действий',
    },
  },
  {
    base: '/clients/',
    defaultRoute: 'clients',
    routes: {
      clients: 'Клиенты',
      leads: 'Лиды',
      contacts: 'Контактные лица',
      requests: 'Запросы',
      positions: 'Позиции',
      attempts: 'Попытки подключения',
      members: 'Участники проектов',
      cashflow: 'ДДС',
      'reporting-periods': 'Отчётные периоды',
      permissions: 'Настройки разрешений',
    },
  },
  {
    base: '/timesheets/',
    defaultRoute: 'mine',
    routes: {
      mine: 'Мои таймшиты',
      management: 'Управление',
      'commercial-load': 'Коммерческая загрузка',
      audit: 'История действий',
    },
  },
  {
    base: '/cv/',
    defaultRoute: 'convert',
    routes: {
      convert: 'Конвертация',
    },
  },
  {
    base: '/design-system/',
    defaultRoute: 'components',
    routes: {
      components: 'Компоненты',
      navigation: 'Навигация',
    },
  },
];

let syncingFromLocation = false;
let syncQueued = false;

const configForLocation = () => serviceRoutes
  .filter((config) => window.location.pathname.startsWith(config.base))
  .sort((left, right) => right.base.length - left.base.length)[0] || null;

const currentRoute = (config) => {
  const tail = window.location.pathname.slice(config.base.length).replace(/^\/+|\/+$/g, '');
  if (!tail) {
    const legacySection = new URLSearchParams(window.location.search).get('section');
    if (legacySection && Object.prototype.hasOwnProperty.call(config.routes, legacySection)) return legacySection;
    return config.defaultRoute;
  }
  return tail.split('/')[0] || config.defaultRoute;
};

const canonicalPath = (config, route) => `${config.base}${route}/`;
const currentSuffix = () => `${window.location.search}${window.location.hash}`;

const navigationButtons = () => [...document.querySelectorAll(
  '[data-component="ui-app-sidebar"] .nav-entry button[aria-label], [data-component="ui-app-sidebar"] .nav-label-entry button, [data-component="ui-app-sidebar"] .bottom-action[aria-label]',
)];

const buttonForLabel = (label) => navigationButtons().find((button) =>
  (button.getAttribute('aria-label') || button.textContent || '').trim() === label
);

const syncSidebarToLocation = () => {
  syncQueued = false;
  const config = configForLocation();
  if (!config) return;

  let route = currentRoute(config);
  if (!Object.prototype.hasOwnProperty.call(config.routes, route)) {
    route = config.defaultRoute;
    window.history.replaceState(window.history.state, '', `${canonicalPath(config, route)}${currentSuffix()}`);
  } else if (window.location.pathname === config.base) {
    window.history.replaceState(window.history.state, '', `${canonicalPath(config, route)}${currentSuffix()}`);
  }

  const button = buttonForLabel(config.routes[route]);
  if (!button || button.classList.contains('active')) return;

  syncingFromLocation = true;
  button.click();
  syncingFromLocation = false;
};

const queueSync = () => {
  if (syncQueued) return;
  syncQueued = true;
  window.requestAnimationFrame(syncSidebarToLocation);
};

const routeForLabel = (config, label) => Object.entries(config.routes)
  .find(([, routeLabel]) => routeLabel === label)?.[0] || null;

document.addEventListener('click', (event) => {
  if (syncingFromLocation) return;
  const button = event.target.closest?.('[data-component="ui-app-sidebar"] button');
  if (!button) return;
  if (!button.matches('.nav-entry button, .nav-label-entry button, .bottom-action')) return;

  const config = configForLocation();
  if (!config) return;
  const label = (button.getAttribute('aria-label') || button.textContent || '').trim();
  const route = routeForLabel(config, label);
  if (!route) return;

  const target = canonicalPath(config, route);
  if (window.location.pathname !== target) window.history.pushState({ serviceRoute: route }, '', target);
}, true);

window.addEventListener('popstate', queueSync);

if (document.documentElement) {
  const observer = new MutationObserver(queueSync);
  observer.observe(document.documentElement, { childList: true, subtree: true });
}

queueSync();
