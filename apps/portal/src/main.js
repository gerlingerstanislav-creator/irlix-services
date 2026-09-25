import { createBrowserAuth } from '@irlix/auth';

const auth = createBrowserAuth({
  storagePrefix: 'irlix.dashboard.auth',
  defaultReturnTo: '/',
});

const authenticatedFetch = async (url, init = {}) => auth.fetch(url, init);

const clearPlatformSessionStorage = () => {
  let idToken = null;
  for (let index = sessionStorage.length - 1; index >= 0; index -= 1) {
    const key = sessionStorage.key(index);
    if (!key || !key.startsWith('irlix.') || !key.includes('.auth.')) continue;
    if (!idToken && key.endsWith('.tokens')) {
      try { idToken = JSON.parse(sessionStorage.getItem(key) || 'null')?.id_token || null; }
      catch (_) { idToken = null; }
    }
    sessionStorage.removeItem(key);
  }
  return idToken;
};

const platformLogout = async () => {
  const loading = document.getElementById('auth-loading');
  loading.textContent = 'Сбрасываем авторизацию…';
  const idToken = clearPlatformSessionStorage();

  try {
    const response = await fetch('/keycloak/auth/realms/irlix/.well-known/openid-configuration', {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (!response.ok) throw new Error(`OIDC discovery failed (${response.status})`);
    const discovery = await response.json();
    if (!discovery.end_session_endpoint) throw new Error('OIDC logout endpoint is unavailable');

    const params = new URLSearchParams({
      client_id: 'irlix-services-web',
      post_logout_redirect_uri: `${window.location.origin}/`,
    });
    if (idToken) params.set('id_token_hint', idToken);
    window.location.replace(`${discovery.end_session_endpoint}?${params.toString()}`);
  } catch (error) {
    console.error('Platform logout failed', error);
    loading.textContent = 'Локальная авторизация сброшена. Возвращаемся на Dashboard…';
    window.setTimeout(() => window.location.replace('/'), 800);
  }
};

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
  if (window.location.pathname === '/auth/logout' || window.location.pathname === '/auth/logout/') {
    await platformLogout();
    return;
  }

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
