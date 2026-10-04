import { createApp, h } from 'vue';
import { UiAppTopbar } from '@irlix/ui';

const PRIMARY_SERVICES = Object.freeze({
  '/employees/': {
    roles: ['Руководитель', 'HR', 'Finance'],
  },
  '/vacations/': {
    roles: ['Сотрудник', 'Руководитель', 'HR', 'Кадровик'],
  },
  '/clients/': {
    roles: ['Account Manager', 'Sales', 'Руководитель направления'],
  },
  '/timesheets/': {
    roles: ['Сотрудник', 'Account Manager', 'Руководитель направления'],
  },
  '/specialists/': {
    roles: ['Руководитель направления'],
  },
  '/recruitment/': {
    roles: ['Recruiter', 'HR', 'Руководитель направления'],
  },
  '/equipment/': {
    roles: ['Системный администратор', 'Бухгалтерия'],
  },
});

const UTILITY_SERVICES = Object.freeze({
  '/cv-converter/': {
    roles: ['Все пользователи с доступом к платформе'],
  },
  '/design-system/': {
    roles: ['Все пользователи с доступом к платформе'],
  },
  '/migration/': {
    roles: [],
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
      <circle cx="12" cy="12" r="9"></circle>
      ${available
        ? '<path d="m8.3 12.2 2.4 2.5 5.1-5.4"></path>'
        : '<path d="m9 9 6 6m0-6-6 6"></path>'}
    </svg>
  </span>`;

const roleFooter = (roles) => `
  <div class="dashboard-service-roles" data-platform-admin-only hidden>
    <span class="dashboard-service-roles-label">Доступ</span>
    <div class="dashboard-service-role-list">
      ${roles.length
        ? roles.map((role) => `<span class="dashboard-service-role">${role}</span>`).join('')
        : '<span class="dashboard-service-role dashboard-service-role-empty">Других ролей нет</span>'}
    </div>
  </div>`;

const enhanceCard = (card, definition, utility = false) => {
  if (!card || card.dataset.dashboardCardReady === '1') return;
  const icon = card.querySelector('.icon')?.outerHTML || '<span class="icon">•</span>';
  const title = card.querySelector('h2')?.textContent?.trim() || 'Сервис';
  const description = card.querySelector('p')?.outerHTML || '';
  const available = card.classList.contains('available') && !card.classList.contains('disabled');

  card.dataset.dashboardCardReady = '1';
  card.classList.add('dashboard-service-card');
  if (utility) card.classList.add('dashboard-service-card-utility');
  card.setAttribute('aria-label', `${title}. ${available ? 'Сервис доступен' : 'Сервис недоступен'}`);
  card.innerHTML = `
    <div class="dashboard-service-card-head">
      <div class="dashboard-service-card-title">${icon}<h2>${title}</h2></div>
      ${statusIcon(available)}
    </div>
    ${description}
    ${roleFooter(definition.roles || [])}`;
};

const syncAdminRoleFooters = () => {
  const migrationCard = document.getElementById('migration-card');
  const platformAdmin = Boolean(migrationCard && !migrationCard.hidden);
  document.querySelectorAll('[data-platform-admin-only]').forEach((element) => {
    element.hidden = !platformAdmin;
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
    #dashboard-page>.grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:0}
    #dashboard-page .card.dashboard-service-card{min-width:0;min-height:178px;gap:12px;padding:14px 15px}
    #dashboard-page .dashboard-service-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
    #dashboard-page .dashboard-service-card-title{display:flex;align-items:center;min-width:0;gap:10px}
    #dashboard-page .dashboard-service-card-title .icon{flex:0 0 34px}
    #dashboard-page .dashboard-service-card-title h2{min-width:0;margin:0;font-size:15px;line-height:1.2;white-space:normal}
    #dashboard-page .dashboard-service-status{display:grid;place-items:center;flex:0 0 23px;width:23px;height:23px}
    #dashboard-page .dashboard-service-status svg{width:21px;height:21px;overflow:visible;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    #dashboard-page .dashboard-service-status.is-available{color:#16a34a}
    #dashboard-page .dashboard-service-status.is-unavailable{color:#dc2626}
    #dashboard-page .dashboard-service-card>p{margin:0;color:#7d8795;font-size:12px;line-height:1.45}
    #dashboard-page .dashboard-service-roles{margin-top:auto;padding-top:10px;border-top:1px solid #edf0f2}
    #dashboard-page .dashboard-service-roles-label{display:block;margin-bottom:6px;color:#98a2b3;font-size:9px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}
    #dashboard-page .dashboard-service-role-list{display:flex;flex-wrap:wrap;gap:5px}
    #dashboard-page .dashboard-service-role{display:inline-flex;align-items:center;min-height:21px;padding:3px 7px;border-radius:999px;background:#f2f4f7;color:#667085;font-size:9px;font-weight:700;line-height:1.2}
    #dashboard-page .dashboard-service-role-empty{font-weight:600}
    #dashboard-page>.utility{margin-top:12px;padding-top:0;border-top:0}
    #dashboard-page>.utility>.utility-label{display:none!important}
    #dashboard-page .utility-links{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    #dashboard-page .utility-card.dashboard-service-card{display:flex;width:auto;min-height:142px;padding:14px 15px}
    #dashboard-page .utility-card.dashboard-service-card .icon{width:34px;height:34px;border-radius:9px;font-size:12px}
    #dashboard-page .utility-card.dashboard-service-card h2{font-size:15px}
    @media(max-width:1120px){
      #dashboard-page>.grid,#dashboard-page .utility-links{grid-template-columns:repeat(3,minmax(0,1fr))}
    }
    @media(max-width:860px){
      #dashboard-page>.grid,#dashboard-page .utility-links{grid-template-columns:repeat(2,minmax(0,1fr))}
    }
    @media(max-width:720px){
      #dashboard-page.page{padding:0 16px 40px}
      #dashboard-topbar{margin:0 -16px 16px}
    }
    @media(max-width:560px){
      #dashboard-page>.grid,#dashboard-page .utility-links{grid-template-columns:1fr}
    }
  `;
  document.head.append(style);
};

const enhanceDashboard = () => {
  const dashboard = document.getElementById('dashboard-page');
  if (!dashboard) return;

  ensureStyles();
  mountTopbar(dashboard);

  dashboard.querySelectorAll(':scope > .grid > a.card').forEach((card) => {
    const definition = PRIMARY_SERVICES[normalizePath(card.getAttribute('href'))];
    if (definition) enhanceCard(card, definition, false);
  });

  dashboard.querySelectorAll('.utility-links > a.card').forEach((card) => {
    const definition = UTILITY_SERVICES[normalizePath(card.getAttribute('href'))];
    if (definition) enhanceCard(card, definition, true);
  });

  syncAdminRoleFooters();
  const migrationCard = document.getElementById('migration-card');
  if (migrationCard) {
    new MutationObserver(syncAdminRoleFooters).observe(migrationCard, {
      attributes: true,
      attributeFilter: ['hidden'],
    });
  }
};

enhanceDashboard();
