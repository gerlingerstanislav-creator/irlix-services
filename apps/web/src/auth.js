import Keycloak from 'keycloak-js';

const keycloak = new Keycloak({
  url: `${window.location.origin}/auth`,
  realm: 'irlix',
  clientId: 'irlix-services-web',
});

export const auth = {
  keycloak,
  async init() {
    const authenticated = await keycloak.init({
      onLoad: 'login-required',
      pkceMethod: 'S256',
      checkLoginIframe: false,
    });
    if (!authenticated) {
      await keycloak.login();
      return false;
    }
    return true;
  },
  async token() {
    await keycloak.updateToken(30);
    return keycloak.token;
  },
  async fetch(input, init = {}) {
    const token = await this.token();
    const headers = new Headers(init.headers ?? {});
    headers.set('Authorization', `Bearer ${token}`);
    return fetch(input, { ...init, headers });
  },
  logout() {
    return keycloak.logout({ redirectUri: `${window.location.origin}/employees/` });
  },
  get user() {
    return keycloak.tokenParsed ?? {};
  },
};
