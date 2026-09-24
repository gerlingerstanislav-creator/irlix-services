const DEFAULT_REALM = 'irlix';
const DEFAULT_CLIENT_ID = 'irlix-services-web';

const base64Url = (bytes) => btoa(String.fromCharCode(...bytes))
  .replace(/\+/g, '-')
  .replace(/\//g, '_')
  .replace(/=+$/g, '');

const randomValue = (length = 32) => {
  const bytes = new Uint8Array(length);
  crypto.getRandomValues(bytes);
  return base64Url(bytes);
};

const sha256 = async (value) => new Uint8Array(
  await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value)),
);

const parseJwt = (token) => {
  try {
    const payload = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    const padded = payload.padEnd(Math.ceil(payload.length / 4) * 4, '=');
    return JSON.parse(decodeURIComponent(escape(atob(padded))));
  } catch (_) {
    return {};
  }
};

export const createBrowserAuth = ({
  realm = DEFAULT_REALM,
  clientId = DEFAULT_CLIENT_ID,
  storagePrefix = 'irlix.auth',
  defaultReturnTo = '/',
} = {}) => {
  const authBase = `${window.location.origin}/auth/realms/${realm}/protocol/openid-connect`;
  const tokenKey = `${storagePrefix}.tokens`;
  const stateKey = `${storagePrefix}.state`;
  const verifierKey = `${storagePrefix}.verifier`;
  const returnToKey = `${storagePrefix}.returnTo`;
  const redirectUriKey = `${storagePrefix}.redirectUri`;

  let tokens = null;

  const loadTokens = () => {
    if (tokens) return tokens;
    try {
      tokens = JSON.parse(sessionStorage.getItem(tokenKey) || 'null');
    } catch (_) {
      tokens = null;
    }
    return tokens;
  };

  const saveTokens = (value) => {
    tokens = value;
    if (value) sessionStorage.setItem(tokenKey, JSON.stringify(value));
    else sessionStorage.removeItem(tokenKey);
  };

  const relativeUrl = () => `${window.location.pathname}${window.location.search}${window.location.hash}`;
  const currentCallback = () => {
    const params = new URLSearchParams(window.location.search);
    return { code: params.get('code'), state: params.get('state'), error: params.get('error') };
  };

  const redirectUri = () => sessionStorage.getItem(redirectUriKey) || `${window.location.origin}${window.location.pathname}`;
  const returnTo = () => sessionStorage.getItem(returnToKey) || defaultReturnTo;

  const refresh = async () => {
    const current = loadTokens();
    if (!current?.refresh_token) return null;
    const body = new URLSearchParams({
      grant_type: 'refresh_token',
      client_id: clientId,
      refresh_token: current.refresh_token,
    });
    const response = await fetch(`${authBase}/token`, {
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
    const claims = parseJwt(current?.access_token || '');
    if (!current || !claims.exp) return null;
    if (claims.exp * 1000 < Date.now() + 30000) current = await refresh();
    return current;
  };

  const exchangeCode = async (code, state) => {
    if (!state || state !== sessionStorage.getItem(stateKey)) {
      throw new Error('OIDC state mismatch');
    }

    const body = new URLSearchParams({
      grant_type: 'authorization_code',
      client_id: clientId,
      code,
      redirect_uri: redirectUri(),
    });
    const verifier = sessionStorage.getItem(verifierKey);
    if (verifier) body.set('code_verifier', verifier);

    const response = await fetch(`${authBase}/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    if (!response.ok) {
      const message = await response.text();
      throw new Error(`OIDC token exchange failed (${response.status}): ${message}`);
    }

    const next = await response.json();
    saveTokens(next);
    const target = returnTo();
    sessionStorage.removeItem(stateKey);
    sessionStorage.removeItem(verifierKey);
    sessionStorage.removeItem(returnToKey);
    sessionStorage.removeItem(redirectUriKey);
    history.replaceState({}, '', target);
    return next;
  };

  const login = async () => {
    const callback = currentCallback();
    if (!callback.code && !callback.error) {
      sessionStorage.setItem(returnToKey, relativeUrl());
      sessionStorage.setItem(redirectUriKey, `${window.location.origin}${window.location.pathname}`);
    }

    const state = randomValue(24);
    sessionStorage.setItem(stateKey, state);
    const params = new URLSearchParams({
      client_id: clientId,
      redirect_uri: redirectUri(),
      response_type: 'code',
      scope: 'openid profile email',
      state,
    });

    if (window.isSecureContext && window.crypto?.subtle) {
      const verifier = randomValue(64);
      sessionStorage.setItem(verifierKey, verifier);
      params.set('code_challenge', base64Url(await sha256(verifier)));
      params.set('code_challenge_method', 'S256');
    } else {
      sessionStorage.removeItem(verifierKey);
    }

    window.location.replace(`${authBase}/auth?${params.toString()}`);
  };

  const init = async () => {
    const callback = currentCallback();
    if (callback.error) throw new Error(`OIDC authorization error: ${callback.error}`);
    if (callback.code) await exchangeCode(callback.code, callback.state);

    const current = await ensureFresh();
    if (!current) {
      await login();
      return false;
    }
    return true;
  };

  const authenticatedFetch = async (input, init = {}) => {
    const current = await ensureFresh();
    if (!current?.access_token) {
      await login();
      throw new Error('Authentication redirect started');
    }
    const headers = new Headers(init.headers ?? (input instanceof Request ? input.headers : undefined));
    headers.set('Authorization', `Bearer ${current.access_token}`);
    return fetch(input, { ...init, headers });
  };

  const logout = () => {
    const current = loadTokens();
    saveTokens(null);
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
    async token() {
      const current = await ensureFresh();
      return current?.access_token || null;
    },
    get user() {
      return parseJwt(loadTokens()?.access_token || '');
    },
  };
};
