import { createBrowserAuth } from '@irlix/auth';

const auth = createBrowserAuth({
  storagePrefix: 'irlix.dashboard.auth',
  defaultReturnTo: '/',
});

const authenticatedFetch = async (url, init = {}) => auth.fetch(url, init);

const showDashboard = async () => {
  const claims = auth.user || {};
  document.getElementById('current-user').textContent = claims.preferred_username || claims.email || 'Пользователь';
  document.getElementById('logout').addEventListener('click', () => auth.logout());

  const loading = document.getElementById('auth-loading');
  const dashboard = document.getElementById('dashboard');
  loading.hidden = true;
  loading.style.display = 'none';
  dashboard.hidden = false;

  const checks = [
    ['platform-health', '/api/platform/health', 'Platform Core', false],
    ['employees-health', '/api/employees/health', 'Employees', true],
  ];

  checks.forEach(async ([id, url, label, authenticated]) => {
    const node = document.getElementById(id);
    try {
      const response = authenticated
        ? await authenticatedFetch(url, { headers: { Accept: 'application/json' } })
        : await fetch(url, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`${label} returned ${response.status}`);
      node.textContent = `${label} · online`;
      node.classList.add('ok');
    } catch (error) {
      console.error(`${label} health check failed`, error);
      node.textContent = `${label} · недоступен`;
      node.classList.add('error');
    }
  });
};

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    await showDashboard();
  } catch (error) {
    console.error('Dashboard OIDC initialization failed', error);
    const loading = document.getElementById('auth-loading');
    const detail = error instanceof Error ? error.message : String(error || 'Unknown authentication error');
    loading.innerHTML = `<strong>Не удалось завершить авторизацию.</strong><br><span style="color:#667085">${detail.replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]))}</span>`;
  }
};

start();
