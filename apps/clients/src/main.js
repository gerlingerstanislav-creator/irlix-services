import { createApp, h, ref } from 'vue';
import App from './App.vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';
import './sidebar.css';

const sidebarItems = [
  { id: 'clients', label: 'Клиенты', icon: 'briefcase', source: 'Клиенты' },
  { id: 'leads', label: 'Лиды', icon: 'crown', source: 'Лиды' },
  { id: 'contacts', label: 'Контактные лица', icon: 'contact', source: 'Контакты' },
  { id: 'requests', label: 'Запросы', icon: 'target', source: 'Запросы' },
  { id: 'positions', label: 'Позиции', icon: 'list', source: 'Позиции' },
  { id: 'attempts', label: 'Попытки подключения', icon: 'rocket', source: 'Попытки' },
  { id: 'members', label: 'Участники проектов', icon: 'members', source: 'Участники' },
  { id: 'cashflow', label: 'ДДС', icon: 'cash', source: 'ДДС' },
  { id: 'reports', label: 'Отчётные периоды', icon: 'reports', source: 'Отчётные периоды' },
];

function mountSharedSidebar() {
  const legacyRail = document.querySelector('.rail');
  const shell = document.querySelector('.clients-app');
  if (!legacyRail || !shell) return;

  const legacyButtons = new Map(
    [...legacyRail.querySelectorAll('nav button')].map((button) => [button.getAttribute('title') || '', button]),
  );

  const initialItem = sidebarItems.find((item) => legacyButtons.get(item.source)?.classList.contains('active')) || sidebarItems[0];
  const section = ref(initialItem.id);

  const host = document.createElement('div');
  host.className = 'clients-shared-sidebar';
  shell.insertBefore(host, legacyRail);
  legacyRail.classList.add('legacy-rail-hidden');

  createApp({
    setup() {
      const selectSection = (id) => {
        const item = sidebarItems.find((entry) => entry.id === id);
        if (!item) return;
        const button = legacyButtons.get(item.source);
        if (!button) return;
        button.click();
        section.value = id;
      };

      return () => h(UiAppSidebar, {
        section: section.value,
        items: sidebarItems,
        currentService: 'clients',
        currentUser: auth.user,
        ariaLabel: 'Навигация сервиса клиентов',
        'onUpdate:section': selectSection,
        onLogout: () => auth.logout(),
      });
    },
  }).mount(host);
}

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    createApp(App).mount('#app');
    window.requestAnimationFrame(mountSharedSidebar);
  } catch (error) {
    console.error('Clients OIDC initialization failed', error);
    const target = document.querySelector('#app');
    if (target) target.innerHTML = '<div style="padding:24px;font-family:Arial,sans-serif">Не удалось подключиться к сервису авторизации.</div>';
  }
};

start();
