const DEFAULT_REALM = 'irlix';
const DEFAULT_CLIENT_ID = 'irlix-services-web';
const DEFAULT_KEYCLOAK_PATH = '/keycloak/auth';

const base64Url = (bytes) => btoa(String.fromCharCode(...bytes))
  .replace(/\+/g, '-')
  .replace(/\//g, '_')
  .replace(/=+$/g, '');

const randomValue = (length = 32) => {
  const bytes = new Uint8Array(length);
  window.crypto.getRandomValues(bytes);
  return base64Url(bytes);
};

const sha256 = async (value) => new Uint8Array(
  await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(value)),
);

const parseJwt = (token) => {
  try {
    const payload = token.split('.')[1]?.replace(/-/g, '+').replace(/_/g, '/');
    if (!payload) return {};
    const padded = payload.padEnd(Math.ceil(payload.length / 4) * 4, '=');
    const binary = atob(padded);
    const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
    return JSON.parse(new TextDecoder().decode(bytes));
  } catch (_) {
    return {};
  }
};

export const createBrowserAuth = ({
  realm = DEFAULT_REALM,
  clientId = DEFAULT_CLIENT_ID,
  keycloakPath = DEFAULT_KEYCLOAK_PATH,
  storagePrefix = 'irlix.auth',
  defaultReturnTo = '/',
} = {}) => {
  const nativeFetch = window.fetch.bind(window);
  const normalizedKeycloakPath = `/${String(keycloakPath).replace(/^\/+|\/+$/g, '')}`;
  const realmBase = `${window.location.origin}${normalizedKeycloakPath}/realms/${realm}`;
  const authBase = `${realmBase}/protocol/openid-connect`;
  const tokenKey = `${storagePrefix}.tokens`;
  const transactionKey = `${storagePrefix}.transaction`;
  let tokens = null;
  let redirecting = false;

  const loadTokens = () => {
    if (tokens) return tokens;
    try { tokens = JSON.parse(sessionStorage.getItem(tokenKey) || 'null'); }
    catch (_) { tokens = null; }
    return tokens;
  };

  const saveTokens = (value) => {
    tokens = value;
    if (value) sessionStorage.setItem(tokenKey, JSON.stringify(value));
    else sessionStorage.removeItem(tokenKey);
  };

  const loadTransaction = () => {
    try { return JSON.parse(sessionStorage.getItem(transactionKey) || 'null'); }
    catch (_) { return null; }
  };

  const saveTransaction = (value) => {
    if (value) sessionStorage.setItem(transactionKey, JSON.stringify(value));
    else sessionStorage.removeItem(transactionKey);
  };

  const relativeUrl = () => `${window.location.pathname}${window.location.search}${window.location.hash}`;
  const currentCallback = () => {
    const params = new URLSearchParams(window.location.search);
    return {
      code: params.get('code'),
      state: params.get('state'),
      error: params.get('error'),
      errorDescription: params.get('error_description'),
    };
  };

  const refresh = async () => {
    const current = loadTokens();
    if (!current?.refresh_token) return null;
    const body = new URLSearchParams({
      grant_type: 'refresh_token',
      client_id: clientId,
      refresh_token: current.refresh_token,
    });
    const response = await nativeFetch(`${authBase}/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    if (!response.ok) {
      saveTokens(null);
      return null;
    }
    const next = await response.json();
    saveTokens(next);
    return next;
  };

  const ensureFresh = async () => {
    let current = loadTokens();
    let claims = parseJwt(current?.access_token || '');
    if (!current || !claims.exp) return null;
    if (claims.iss !== realmBase) {
      saveTokens(null);
      return null;
    }
    if (claims.exp * 1000 < Date.now() + 30000) {
      current = await refresh();
      claims = parseJwt(current?.access_token || '');
      if (!current || claims.iss !== realmBase) {
        saveTokens(null);
        return null;
      }
    }
    return current;
  };

  const exchangeCode = async (callback) => {
    const transaction = loadTransaction();
    if (!transaction?.state || !callback.state || callback.state !== transaction.state) {
      throw new Error('OIDC state mismatch');
    }

    const body = new URLSearchParams({
      grant_type: 'authorization_code',
      client_id: clientId,
      code: callback.code,
      redirect_uri: transaction.redirectUri,
    });
    if (transaction.verifier) body.set('code_verifier', transaction.verifier);

    const response = await nativeFetch(`${authBase}/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    if (!response.ok) {
      const detail = await response.text();
      saveTransaction(null);
      throw new Error(`OIDC token exchange failed (${response.status}): ${detail}`);
    }

    const next = await response.json();
    saveTokens(next);
    const target = transaction.returnTo || defaultReturnTo;
    saveTransaction(null);
    window.history.replaceState({}, '', target);
    return next;
  };

  const login = async ({ force = false } = {}) => {
    if (redirecting) return false;
    redirecting = true;

    const state = randomValue(24);
    const redirectUri = `${window.location.origin}${window.location.pathname}`;
    const transaction = {
      state,
      redirectUri,
      returnTo: relativeUrl(),
      startedAt: Date.now(),
      verifier: null,
    };

    const params = new URLSearchParams({
      client_id: clientId,
      redirect_uri: redirectUri,
      response_type: 'code',
      scope: 'openid profile email',
      state,
    });

    if (window.isSecureContext && window.crypto?.subtle) {
      transaction.verifier = randomValue(64);
      params.set('code_challenge', base64Url(await sha256(transaction.verifier)));
      params.set('code_challenge_method', 'S256');
    }
    if (force) params.set('prompt', 'login');

    saveTransaction(transaction);
    window.location.assign(`${authBase}/auth?${params.toString()}`);
    return false;
  };

  const init = async () => {
    const callback = currentCallback();
    if (callback.error) {
      saveTransaction(null);
      throw new Error(`OIDC authorization error: ${callback.errorDescription || callback.error}`);
    }
    if (callback.code) await exchangeCode(callback);

    const current = await ensureFresh();
    if (!current) {
      const stale = loadTransaction();
      if (stale && Date.now() - Number(stale.startedAt || 0) < 15000 && !callback.code) {
        throw new Error('OIDC redirect did not return a usable session');
      }
      await login();
      return false;
    }
    return true;
  };

  const authenticatedFetch = async (input, init = {}) => {
    const current = await ensureFresh();
    if (!current?.access_token) throw new Error('Authentication session is missing or expired');
    const headers = new Headers(init.headers ?? (input instanceof Request ? input.headers : undefined));
    headers.set('Authorization', `Bearer ${current.access_token}`);
    return nativeFetch(input, { ...init, headers });
  };

  const logout = () => {
    const current = loadTokens();
    saveTokens(null);
    saveTransaction(null);
    const params = new URLSearchParams({
      client_id: clientId,
      post_logout_redirect_uri: `${window.location.origin}${defaultReturnTo}`,
    });
    if (current?.id_token) params.set('id_token_hint', current.id_token);
    window.location.assign(`${authBase}/logout?${params.toString()}`);
  };

  return {
    init,
    login,
    logout,
    fetch: authenticatedFetch,
    clear: () => { saveTokens(null); saveTransaction(null); },
    async token() { return (await ensureFresh())?.access_token || null; },
    get user() { return parseJwt(loadTokens()?.access_token || ''); },
  };
};
