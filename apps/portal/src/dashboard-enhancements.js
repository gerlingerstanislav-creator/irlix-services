import { createApp, h } from 'vue';
import { UiAppTopbar } from '@irlix/ui';

const CONTOURS = Object.freeze([
  { id: 'core', label: 'Core' },
  { id: 'clients', label: 'Клиентские сервисы' },
  { id: 'it', label: 'IT' },
  { id: 'platform', label: 'Платформа' },
]);

const SERVICES = Object.freeze({
  '/employees/': {
    contour: 'core',
    roles: ['Руководитель', 'HR', 'Finance'],
  },
  '/vacations/': {
    contour: 'core',
    roles: ['Сотрудник', 'Руководитель', 'HR', 'Кадровик', 'Account Manager'],
  },
  '/recruitment/': {
    contour: 'core',
    roles: ['Recruiter', 'HR', 'Руководитель направления'],
  },
  '/clients/': {
    contour: 'clients',
    roles: ['Account Manager', 'Sales', 'Руководитель направления'],
  },
  '/timesheets/': {
    contour: 'clients',
    roles: ['Сотрудник', 'Account Manager', 'Руководитель направления'],
  },
  '/specialists/': {
    contour: 'it',
    roles: ['Руководитель направления'],
  },
  '/equipment/': {
    contour: 'it',
    roles: ['Системный администратор', 'Бухгалтерия'],
  },
  '/cv-converter/': {
    contour: 'platform',
    roles: [],
    adminOnly: true,
    description: 'Преобразование исходных CV в стандартизированный формат IRLIX.',
  },
  '/design-system/': {
    contour: 'platform',
    roles: [],
    adminOnly: true,
    description: 'Компоненты, токены и визуальные правила интерфейсов платформы.',
  },
  '/migration/': {
    contour: 'platform',
    roles: [],
    adminOnly: true,
    description: 'Перенос исторических данных из legacy-сервисов в новую платформу.',
  },
});

const TOPBAR_ITEMS = Object.freeze([{ id: 'dashboard', label: 'Дашборд' }]);

const normalizePath = (value) => {
  if (!value) return '/';
  try {
    const path = new URL(value, window.location.origin).pathname;
    return path.endsWith('/') ? path : `${path}/`;
  } catch (_) {
    return value;
  }
};

const statusIcon = (available) => `
  <span class="dashboard-service-status ${available ? 'is-available' : 'is-unavailable'}"
        role="img"
        aria-label="${available ? 'Сервис доступен' : 'Сервис недоступен'}"
        title="${available ? 'Сервис доступен' : 'Сервис недоступен'}">
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M12 3v8"></path>
      <path d="M7.2 5.8a8 8 0 1 0 9.6 0"></path>
    </svg>
  </span>`;

const roleFooter = (roles) => `
  <div class="dashboard-service-roles" data-platform-admin-only hidden>
    <span class="dashboard-service-roles-label">Доступ</span>
    <div class="dashboard-service-role-list">
      ${roles.length
        ? roles.map((role) => `<span class="dashboard-service-role">${role}</span>`).join('')
        : '<span class="dashboard-service-role dashboard-service-role-empty">Только администратор платформы</span>'}
    </div>
  </div>`;

const enhanceCard = (card, definition) => {
  if (!card || card.dataset.dashboardCardReady === '1') return;

  const icon = card.querySelector('.icon')?.outerHTML || '<span class="icon">•</span>';
  const title = card.querySelector('h2')?.textContent?.trim() || 'Сервис';
  const existingDescription = card.querySelector('p')?.textContent?.trim();
  const description = definition.description || existingDescription || '';
  const available = card.classList.contains('available') && !card.classList.contains('disabled');

  card.dataset.dashboardCardReady = '1';
  card.dataset.adminOnlyService = definition.adminOnly ? '1' : '0';
  card.dataset.contour = definition.contour;
  card.classList.remove('utility-card');
  card.classList.add('dashboard-service-card');
  card.setAttribute('aria-label', `${title}. ${available ? 'Сервис доступен' : 'Сервис недоступен'}`);
  card.innerHTML = `
    <div class="dashboard-service-card-head">
      <div class="dashboard-service-card-title">${icon}<h2>${title}</h2></div>
      ${statusIcon(available)}
    </div>
    <p>${description}</p>
    ${roleFooter(definition.roles || [])}`;
};

const createContourSections = (dashboard, cards) => {
  const container = document.createElement('div');
  container.id = 'dashboard-contours';
  container.className = 'dashboard-contours';

  CONTOURS.forEach((contour) => {
    const section = document.createElement('section');
    section.className = 'dashboard-contour';
    section.dataset.contourSection = contour.id;
    section.innerHTML = `
      <div class="dashboard-contour-heading">${contour.label}</div>
      <div class="dashboard-contour-grid"></div>`;

    const grid = section.querySelector('.dashboard-contour-grid');
    cards
      .filter((card) => card.dataset.contour === contour.id)
      .forEach((card) => grid.append(card));

    container.append(section);
  });

  dashboard.append(container);
};

const syncAdminVisibility = () => {
  const migrationCard = document.getElementById('migration-card');
  const platformAdmin = Boolean(migrationCard && !migrationCard.hidden);

  document.querySelectorAll('[data-platform-admin-only]').forEach((element) => {
    element.hidden = !platformAdmin;
  });
  document.querySelectorAll('[data-admin-only-service="1"]').forEach((element) => {
    element.hidden = !platformAdmin;
  });
  document.querySelectorAll('[data-contour-section]').forEach((section) => {
    const visibleCards = [...section.querySelectorAll('.dashboard-service-card')]
      .some((card) => !card.hidden);
    section.hidden = !visibleCards;
  });
};

const mountTopbar = (dashboard) => {
  if (document.getElementById('dashboard-topbar')) return;
  const target = document.createElement('div');
  target.id = 'dashboard-topbar';
  dashboard.prepend(target);
  createApp({
    render: () => h(UiAppTopbar, {
      service: 'dashboard',
      serviceName: 'IRLIX Services',
      section: 'dashboard',
      items: TOPBAR_ITEMS,
      loading: false,
    }),
  }).mount(target);
};

const ensureStyles = () => {
  if (document.getElementById('dashboard-enhancement-styles')) return;
  const style = document.createElement('style');
  style.id = 'dashboard-enhancement-styles';
  style.textContent = `
    #dashboard-page.page{max-width:none;margin:0;padding:0 20px 56px;overflow-x:hidden}
    #dashboard-page>.hero{display:none!important}
    #dashboard-topbar{margin:0 -20px 18px}
    #dashboard-page>.grid,#dashboard-page>.utility{display:none!important}
    #dashboard-page .dashboard-contours{display:flex;flex-direction:column;gap:24px}
    #dashboard-page .dashboard-contour{min-width:0}
    #dashboard-page .dashboard-contour-heading{margin:0 0 10px;color:#667085;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
    #dashboard-page .dashboard-contour-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    #dashboard-page .card.dashboard-service-card{display:flex;width:auto;height:220px;min-width:0;min-height:0;gap:12px;padding:14px 15px}
    #dashboard-page .dashboard-service-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
    #dashboard-page .dashboard-service-card-title{display:flex;align-items:center;min-width:0;gap:10px}
    #dashboard-page .dashboard-service-card-title .icon{flex:0 0 34px;width:34px;height:34px;border-radius:9px;font-size:12px}
    #dashboard-page .dashboard-service-card-title h2{min-width:0;margin:0;font-size:15px;line-height:1.2;white-space:normal}
    #dashboard-page .dashboard-service-status{display:grid;place-items:center;flex:0 0 25px;width:25px;height:25px}
    #dashboard-page .dashboard-service-status svg{width:23px;height:23px;overflow:visible;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    #dashboard-page .dashboard-service-status.is-available{color:#16a34a}
    #dashboard-page .dashboard-service-status.is-unavailable{color:#dc2626}
    #dashboard-page .dashboard-service-card>p{margin:0;color:#7d8795;font-size:12px;line-height:1.45}
    #dashboard-page .dashboard-service-roles{margin-top:auto;padding-top:10px;border-top:1px solid #edf0f2}
    #dashboard-page .dashboard-service-roles-label{display:block;margin-bottom:6px;color:#98a2b3;font-size:9px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}
    #dashboard-page .dashboard-service-role-list{display:flex;flex-wrap:wrap;gap:5px}
    #dashboard-page .dashboard-service-role{display:inline-flex;align-items:center;min-height:21px;padding:3px 7px;border-radius:999px;background:#f2f4f7;color:#667085;font-size:9px;font-weight:700;line-height:1.2}
    #dashboard-page .dashboard-service-role-empty{font-weight:600}
    @media(max-width:1120px){#dashboard-page .dashboard-contour-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media(max-width:860px){#dashboard-page .dashboard-contour-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:720px){#dashboard-page.page{padding:0 16px 40px}#dashboard-topbar{margin:0 -16px 16px}}
    @media(max-width:560px){#dashboard-page .dashboard-contour-grid{grid-template-columns:1fr}#dashboard-page .card.dashboard-service-card{height:auto;min-height:190px}}
  `;
  document.head.append(style);
};

const enhanceDashboard = () => {
  const dashboard = document.getElementById('dashboard-page');
  if (!dashboard) return;

  ensureStyles();
  mountTopbar(dashboard);

  const cards = [...dashboard.querySelectorAll(':scope > .grid > a.card, .utility-links > a.card')];
  cards.forEach((card) => {
    const definition = SERVICES[normalizePath(card.getAttribute('href'))];
    if (definition) enhanceCard(card, definition);
  });

  createContourSections(dashboard, cards.filter((card) => card.dataset.dashboardCardReady === '1'));
  syncAdminVisibility();

  const migrationCard = document.getElementById('migration-card');
  if (migrationCard) {
    new MutationObserver(syncAdminVisibility).observe(migrationCard, {
      attributes: true,
      attributeFilter: ['hidden'],
    });
  }
};

enhanceDashboard();
