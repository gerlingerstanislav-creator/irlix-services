import Keycloak from 'keycloak-js';

const nativeFetch = window.fetch.bind(window);
const keycloak = new Keycloak({
  url: `${window.location.origin}/auth`,
  realm: 'irlix',
  clientId: 'irlix-services-web',
});

let apiFetchInstalled = false;

const isProtectedApiRequest = (input) => {
  const raw = typeof input === 'string' ? input : input?.url;
  if (!raw) return false;
  const url = new URL(raw, window.location.origin);
  return url.origin === window.location.origin && url.pathname.startsWith('/api/');
};

const authenticatedFetch = async (input, init = {}) => {
  await keycloak.updateToken(30);
  const headers = new Headers(init.headers ?? (input instanceof Request ? input.headers : undefined));
  headers.set('Authorization', `Bearer ${keycloak.token}`);
  return nativeFetch(input, { ...init, headers });
};

const installApiFetch = () => {
  if (apiFetchInstalled) return;
  apiFetchInstalled = true;
  window.fetch = async (input, init = {}) => {
    if (!isProtectedApiRequest(input)) return nativeFetch(input, init);
    return authenticatedFetch(input, init);
  };
};

const keycloakOptions = () => ({
  onLoad: 'login-required',
  // Internal stand is HTTP-only. Restore S256 automatically once HTTPS is enabled.
  pkceMethod: window.isSecureContext ? 'S256' : false,
  checkLoginIframe: false,
});

export const auth = {
  keycloak,
  async init() {
    const authenticated = await keycloak.init(keycloakOptions());
    if (!authenticated) {
      await keycloak.login();
      return false;
    }
    installApiFetch();
    return true;
  },
  login() {
    return keycloak.login();
  },
  async token() {
    await keycloak.updateToken(30);
    return keycloak.token;
  },
  fetch: authenticatedFetch,
  logout() {
    return keycloak.logout({ redirectUri: `${window.location.origin}/employees/` });
  },
  get user() {
    return keycloak.tokenParsed ?? {};
  },
};
