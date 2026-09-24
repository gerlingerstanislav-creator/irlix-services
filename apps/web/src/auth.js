import Keycloak from 'keycloak-js';

const nativeFetch = window.fetch.bind(window);
const keycloak = new Keycloak({
  url: `${window.location.origin}/auth`,
  realm: 'irlix',
  clientId: 'irlix-services-web',
});

const RETURN_TO_KEY = 'irlix.auth.returnTo';
let apiFetchInstalled = false;

const relativeUrl = () => `${window.location.pathname}${window.location.search}${window.location.hash}`;
const isOidcCallback = () => {
  const params = new URLSearchParams(window.location.search);
  return params.has('code') && params.has('state');
};
const rememberReturnTo = () => {
  if (!isOidcCallback()) sessionStorage.setItem(RETURN_TO_KEY, relativeUrl());
  return sessionStorage.getItem(RETURN_TO_KEY) || '/employees/';
};

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

export const auth = {
  keycloak,
  async init() {
    const returnTo = rememberReturnTo();
    const redirectUri = new URL(returnTo, window.location.origin).href;
    const authenticated = await keycloak.init({
      onLoad: 'login-required',
      redirectUri,
      // PKCE S256 requires a secure browser context. The current internal stand is HTTP-only,
      // so it temporarily falls back to Authorization Code without PKCE until HTTPS is enabled.
      pkceMethod: window.isSecureContext ? 'S256' : false,
      checkLoginIframe: false,
    });
    if (!authenticated) {
      await keycloak.login({ redirectUri });
      return false;
    }
    sessionStorage.removeItem(RETURN_TO_KEY);
    installApiFetch();
    return true;
  },
  async login() {
    const returnTo = rememberReturnTo();
    return keycloak.login({ redirectUri: new URL(returnTo, window.location.origin).href });
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
