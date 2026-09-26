import { createApp } from 'vue';
import App from './App.vue';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';
import './sidebar.css';

const serviceGroups = [
  {
    label: 'Сотрудники',
    items: [
      { key: 'dashboard', label: 'Dashboard', href: '/' },
      { key: 'employees', label: 'Сотрудники', href: '/employees/' },
      { key: 'vacations', label: 'Отсутствия', href: '/vacations/' },
      { key: 'specialists', label: 'Специалисты' },
    ],
  },
  {
    label: 'Клиентские сервисы',
    items: [
      { key: 'clients', label: 'Клиенты', href: '/clients/' },
      { key: 'timesheets', label: 'Учет времени' },
    ],
  },
  {
    label: 'Системные',
    items: [
      { key: 'design-system', label: 'Design System', href: '/design-system/' },
    ],
  },
];

const sidebarItems = [
  { source: 'Клиенты', label: 'Клиенты' },
  { source: 'Лиды', label: 'Лиды' },
  { source: 'Контакты', label: 'Контактные лица' },
  { source: 'Запросы', label: 'Запросы' },
  { source: 'Позиции', label: 'Позиции' },
  { source: 'Попытки', label: 'Попытки подключения' },
  { source: 'Участники', label: 'Участники проектов' },
  { source: 'ДДС', label: 'ДДС' },
  { source: 'Отчётные периоды', label: 'Отчётные периоды' },
];

const enhanceSidebar = () => {
  const rail = document.querySelector('.rail');
  if (!rail || rail.dataset.enhanced === '1') return;
  rail.dataset.enhanced = '1';

  const brand = rail.querySelector('.brand');
  const home = rail.querySelector('.home');
  const userChip = rail.querySelector('.rail-chip');
  const nav = rail.querySelector('nav');
  if (!brand || !nav) return;

  home?.remove();

  brand.className = 'services-logo';
  brand.setAttribute('role', 'button');
  brand.setAttribute('tabindex', '0');
  brand.setAttribute('aria-label', 'Открыть список сервисов');
  brand.setAttribute('aria-expanded', 'false');
  brand.innerHTML = `
    <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true">
      <path fill-rule="evenodd" clip-rule="evenodd" class="logo-one" d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z" />
      <path fill-rule="evenodd" clip-rule="evenodd" class="logo-two" d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z" />
    </svg>`;

  const divider = document.createElement('div');
  divider.className = 'sidebar-divider';
  brand.after(divider);

  const hoverZone = document.createElement('div');
  hoverZone.className = 'nav-hover-zone';
  nav.parentNode.insertBefore(hoverZone, nav);
  hoverZone.appendChild(nav);
  nav.classList.add('icon-nav');

  const buttons = [...nav.querySelectorAll('button')];
  const byTitle = new Map(buttons.map((button) => [button.getAttribute('title') || '', button]));
  sidebarItems.forEach((item) => {
    const button = byTitle.get(item.source);
    if (!button) return;
    button.dataset.menuLabel = item.label;
    button.setAttribute('aria-label', item.label);
    button.removeAttribute('title');
    nav.appendChild(button);
  });

  const navLabels = document.createElement('div');
  navLabels.className = 'nav-labels';
  navLabels.hidden = true;
  const labelEntries = [...nav.querySelectorAll('button')].map((button) => {
    const label = document.createElement('button');
    label.type = 'button';
    label.textContent = button.dataset.menuLabel || button.getAttribute('aria-label') || '';
    label.classList.toggle('active', button.classList.contains('active'));
    label.addEventListener('click', () => button.click());
    new MutationObserver(() => label.classList.toggle('active', button.classList.contains('active')))
      .observe(button, { attributes: true, attributeFilter: ['class'] });
    navLabels.appendChild(label);
    return { button, label };
  });
  rail.appendChild(navLabels);

  const positionLabels = () => {
    labelEntries.forEach(({ button, label }) => {
      const rect = button.getBoundingClientRect();
      label.style.top = `${Math.round(rect.top + (rect.height - 30) / 2)}px`;
      label.hidden = rect.bottom <= 0 || rect.top >= window.innerHeight;
    });
  };
  const showLabels = () => {
    if (rail.classList.contains('services-open')) return;
    positionLabels();
    navLabels.hidden = false;
  };
  const hideLabels = () => { navLabels.hidden = true; };
  hoverZone.addEventListener('mouseenter', showLabels);
  hoverZone.addEventListener('mouseleave', (event) => {
    if (navLabels.contains(event.relatedTarget)) return;
    hideLabels();
  });
  navLabels.addEventListener('mouseenter', showLabels);
  navLabels.addEventListener('mouseleave', (event) => {
    if (hoverZone.contains(event.relatedTarget)) return;
    hideLabels();
  });
  hoverZone.addEventListener('scroll', () => {
    if (!navLabels.hidden) positionLabels();
  });
  window.addEventListener('resize', () => {
    if (!navLabels.hidden) positionLabels();
  });

  const bottom = document.createElement('div');
  bottom.className = 'sidebar-bottom';
  if (userChip) {
    userChip.className = 'user-chip';
    userChip.title = auth.user?.preferred_username || auth.user?.email || 'Пользователь';
    bottom.appendChild(userChip);
  }
  const logout = document.createElement('button');
  logout.type = 'button';
  logout.className = 'logout-button';
  logout.title = 'Выйти';
  logout.setAttribute('aria-label', 'Выйти');
  logout.textContent = '↪';
  logout.addEventListener('click', () => auth.logout());
  bottom.appendChild(logout);
  rail.appendChild(bottom);

  const popover = document.createElement('div');
  popover.className = 'services-popover';
  popover.hidden = true;
  popover.innerHTML = '<div class="services-list"></div>';
  const list = popover.querySelector('.services-list');

  serviceGroups.forEach((group) => {
    const section = document.createElement('section');
    section.className = 'services-group';
    const heading = document.createElement('div');
    heading.className = 'services-group__title';
    heading.textContent = group.label;
    section.appendChild(heading);

    group.items.forEach((service) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.disabled = !service.href;
      if (service.key === 'clients') button.classList.add('active');
      button.innerHTML = `<span class="service-icon">${service.label.slice(0, 1)}</span><span class="service-label">${service.label}</span>${service.key === 'clients' ? '<span class="service-current">Текущий</span>' : ''}`;
      if (service.href) button.addEventListener('click', () => window.location.assign(service.href));
      section.appendChild(button);
    });

    list.appendChild(section);
  });
  rail.appendChild(popover);

  const setOpen = (open) => {
    popover.hidden = !open;
    rail.classList.toggle('services-open', open);
    brand.setAttribute('aria-expanded', String(open));
    if (open) hideLabels();
  };
  const toggleServices = () => setOpen(popover.hidden);
  brand.addEventListener('click', toggleServices);
  brand.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      toggleServices();
    }
  });
  document.addEventListener('pointerdown', (event) => {
    if (popover.hidden || rail.contains(event.target)) return;
    setOpen(false);
  });
};

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    createApp(App).mount('#app');
    window.requestAnimationFrame(enhanceSidebar);
  } catch (error) {
    console.error('Clients OIDC initialization failed', error);
    const target = document.querySelector('#app');
    if (target) target.innerHTML = '<div style="padding:24px;font-family:Arial,sans-serif">Не удалось подключиться к сервису авторизации.</div>';
  }
};

start();
