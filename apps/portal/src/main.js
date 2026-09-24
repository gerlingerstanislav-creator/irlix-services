import Keycloak from 'keycloak-js';

const keycloak = new Keycloak({
  url: `${window.location.origin}/auth`,
  realm: 'irlix',
  clientId: 'irlix-services-web',
});

const authenticatedFetch = async (url, init = {}) => {
  await keycloak.updateToken(30);
  const headers = new Headers(init.headers || {});
  headers.set('Authorization', `Bearer ${keycloak.token}`);
  return fetch(url, { ...init, headers });
};

const showDashboard = async () => {
  const claims = keycloak.tokenParsed || {};
  document.getElementById('current-user').textContent = claims.preferred_username || claims.email || 'Пользователь';
  document.getElementById('logout').addEventListener('click', () => {
    keycloak.logout({ redirectUri: `${window.location.origin}/` });
  });

  document.getElementById('auth-loading').hidden = true;
  document.getElementById('dashboard').hidden = false;

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
    const authenticated = await keycloak.init({
      onLoad: 'login-required',
      pkceMethod: window.isSecureContext ? 'S256' : false,
      checkLoginIframe: false,
    });

    if (!authenticated) {
      await keycloak.login();
      return;
    }

    await showDashboard();
  } catch (error) {
    console.error('Dashboard OIDC initialization failed', error);
    const loading = document.getElementById('auth-loading');
    loading.textContent = 'Не удалось завершить авторизацию. Обновите страницу через несколько секунд.';
  }
};

start();
