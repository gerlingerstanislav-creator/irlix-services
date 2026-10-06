const DEFAULT_REALM = 'irlix';
const DEFAULT_CLIENT_ID = 'irlix-services-web';
const DEFAULT_KEYCLOAK_PATH = '/keycloak/auth';
const OIDC_TIMEOUT_MS = 10000;
const TRANSACTION_TTL_MS = 10 * 60 * 1000;
const CALLBACK_RECOVERY_WINDOW_MS = 30 * 1000;

const base64Url = (bytes) => btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
const randomValue = (length = 32) => { const bytes = new Uint8Array(length); window.crypto.getRandomValues(bytes); return base64Url(bytes); };
const sha256 = async (value) => new Uint8Array(await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(value)));
const parseJwt = (token) => { try { const payload = token.split('.')[1]?.replace(/-/g, '+').replace(/_/g, '/'); if (!payload) return {}; const padded = payload.padEnd(Math.ceil(payload.length / 4) * 4, '='); const binary = atob(padded); const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0)); return JSON.parse(new TextDecoder().decode(bytes)); } catch (_) { return {}; } };

export const createBrowserAuth = ({ realm = DEFAULT_REALM, clientId = DEFAULT_CLIENT_ID, keycloakPath = DEFAULT_KEYCLOAK_PATH, storagePrefix = 'irlix.auth', defaultReturnTo = '/', onStage = null } = {}) => {
  const nativeFetch = window.fetch.bind(window);
  const normalizedKeycloakPath = `/${String(keycloakPath).replace(/^\/+|\/+$/g, '')}`;
  const discoveryUrl = `${window.location.origin}${normalizedKeycloakPath}/realms/${realm}/.well-known/openid-configuration`;
  const tokenKey = `${storagePrefix}.tokens`;
  const transactionsKey = `${storagePrefix}.transactions`;
  const legacyTransactionKey = `${storagePrefix}.transaction`;
  const recoveryKey = `${storagePrefix}.callback-recovery`;
  let tokens = null; let discovery = null; let redirecting = false;
  const reportStage = (stage, detail = '') => { try { if (typeof onStage === 'function') onStage({ stage, detail, at: Date.now() }); } catch (_) {} };

  const safeGet = (storage, key) => { try { return storage.getItem(key); } catch (_) { return null; } };
  const safeSet = (storage, key, value) => { try { storage.setItem(key, value); } catch (_) {} };
  const safeRemove = (storage, key) => { try { storage.removeItem(key); } catch (_) {} };
  const readDurable = (key) => safeGet(sessionStorage, key) ?? safeGet(localStorage, key);
  const writeDurable = (key, value) => { safeSet(sessionStorage, key, value); safeSet(localStorage, key, value); };
  const removeDurable = (key) => { safeRemove(sessionStorage, key); safeRemove(localStorage, key); };

  const oidcFetch = async (url, init = {}) => { const controller = new AbortController(); const timer = window.setTimeout(() => controller.abort(), OIDC_TIMEOUT_MS); try { return await nativeFetch(url, { ...init, signal: controller.signal }); } catch (error) { if (error?.name === 'AbortError') throw new Error(`OIDC request timed out: ${url}`); throw error; } finally { window.clearTimeout(timer); } };
  const sameOriginOidcEndpoint = (value, fallbackPath) => {
    try {
      const parsed = new URL(value, window.location.origin);
      if (parsed.pathname === normalizedKeycloakPath || parsed.pathname.startsWith(`${normalizedKeycloakPath}/`)) {
        return `${window.location.origin}${parsed.pathname}${parsed.search}`;
      }
    } catch (_) {}
    return `${window.location.origin}${fallbackPath}`;
  };
  const loadDiscovery = async () => {
    if (discovery) return discovery;
    reportStage('oidc-discovery', discoveryUrl);
    const response = await oidcFetch(discoveryUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error(`OIDC discovery failed (${response.status})`);
    const discovered = await response.json();
    if (!discovered?.issuer || !discovered?.authorization_endpoint || !discovered?.token_endpoint) throw new Error('OIDC discovery is incomplete');
    const realmProtocolPath = `${normalizedKeycloakPath}/realms/${realm}/protocol/openid-connect`;
    discovery = {
      ...discovered,
      authorization_endpoint: sameOriginOidcEndpoint(discovered.authorization_endpoint, `${realmProtocolPath}/auth`),
      token_endpoint: sameOriginOidcEndpoint(discovered.token_endpoint, `${realmProtocolPath}/token`),
      end_session_endpoint: sameOriginOidcEndpoint(discovered.end_session_endpoint, `${realmProtocolPath}/logout`),
    };
    reportStage('oidc-discovery-complete', discovery.issuer);
    return discovery;
  };
  const loadTokens = () => { if (tokens) return tokens; try { tokens = JSON.parse(safeGet(sessionStorage, tokenKey) || 'null'); } catch (_) { tokens = null; } return tokens; };
  const saveTokens = (value) => { tokens = value; if (value) safeSet(sessionStorage, tokenKey, JSON.stringify(value)); else safeRemove(sessionStorage, tokenKey); };

  const loadTransactions = () => {
    let transactions = {};
    try { const parsed = JSON.parse(readDurable(transactionsKey) || '{}'); if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) transactions = parsed; } catch (_) { transactions = {}; }
    try { const legacy = JSON.parse(readDurable(legacyTransactionKey) || 'null'); if (legacy?.state && !transactions[legacy.state]) transactions[legacy.state] = legacy; } catch (_) {}
    removeDurable(legacyTransactionKey);
    const now = Date.now(); let changed = false;
    Object.entries(transactions).forEach(([state, transaction]) => { if (!transaction?.startedAt || now - Number(transaction.startedAt) > TRANSACTION_TTL_MS) { delete transactions[state]; changed = true; } });
    if (changed) { if (Object.keys(transactions).length) writeDurable(transactionsKey, JSON.stringify(transactions)); else removeDurable(transactionsKey); }
    return transactions;
  };
  const saveTransactions = (transactions) => { if (Object.keys(transactions || {}).length) writeDurable(transactionsKey, JSON.stringify(transactions)); else removeDurable(transactionsKey); };
  const saveTransaction = (transaction) => { const transactions = loadTransactions(); transactions[transaction.state] = transaction; saveTransactions(transactions); };
  const getTransaction = (state) => state ? loadTransactions()[state] || null : null;
  const removeTransaction = (state) => { if (!state) return; const transactions = loadTransactions(); delete transactions[state]; saveTransactions(transactions); };
  const clearTransactions = () => { removeDurable(transactionsKey); removeDurable(legacyTransactionKey); };
  const relativeUrl = () => `${window.location.pathname}${window.location.search}${window.location.hash}`;
  const currentCallback = () => { const params = new URLSearchParams(window.location.search); return { code: params.get('code'), state: params.get('state'), error: params.get('error'), errorDescription: params.get('error_description') }; };
  const stripOidcCallback = () => { const params = new URLSearchParams(window.location.search); ['code','state','session_state','error','error_description','iss'].forEach((key) => params.delete(key)); const query = params.toString(); const target = `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`; window.history.replaceState({}, '', target); return target; };

  const refresh = async () => { reportStage('token-refresh'); const current = loadTokens(); if (!current?.refresh_token) return null; const oidc = await loadDiscovery(); const body = new URLSearchParams({ grant_type:'refresh_token', client_id:clientId, refresh_token:current.refresh_token }); const response = await oidcFetch(oidc.token_endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}); if (!response.ok) { saveTokens(null); return null; } const next = await response.json(); saveTokens(next); return next; };
  const ensureFresh = async () => { reportStage('session-check'); const oidc = await loadDiscovery(); let current = loadTokens(); let claims = parseJwt(current?.access_token || ''); if (!current || !claims.exp) return null; if (claims.iss !== oidc.issuer) { saveTokens(null); return null; } if (claims.exp * 1000 < Date.now() + 30000) { current = await refresh(); claims = parseJwt(current?.access_token || ''); if (!current || claims.iss !== oidc.issuer) { saveTokens(null); return null; } } return current; };

  const exchangeCode = async (callback) => { reportStage('token-exchange'); const transaction = getTransaction(callback.state); if (!transaction?.state || !callback.state) { const error = new Error('OIDC state mismatch'); error.code='OIDC_STATE_MISMATCH'; throw error; } const oidc = await loadDiscovery(); const body = new URLSearchParams({grant_type:'authorization_code',client_id:clientId,code:callback.code,redirect_uri:transaction.redirectUri}); if (transaction.verifier) body.set('code_verifier',transaction.verifier); const response=await oidcFetch(oidc.token_endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}); if(!response.ok){const detail=await response.text();throw new Error(`OIDC token exchange failed (${response.status}): ${detail}`);} const next=await response.json(); saveTokens(next); reportStage('token-exchange-complete'); const target=transaction.returnTo||defaultReturnTo; removeTransaction(callback.state); removeDurable(recoveryKey); window.history.replaceState({},'',target); return next; };

  const login = async ({ force=false, returnTo=null }={}) => { if(redirecting) throw new Error('OIDC redirect is already in progress'); redirecting=true; try { const oidc=await loadDiscovery(); const state=randomValue(24); const redirectUri=`${window.location.origin}${window.location.pathname}`; const transaction={state,redirectUri,returnTo:returnTo||relativeUrl(),startedAt:Date.now(),verifier:null}; const params=new URLSearchParams({client_id:clientId,redirect_uri:redirectUri,response_type:'code',scope:'openid profile email',state}); if(window.isSecureContext&&window.crypto?.subtle){transaction.verifier=randomValue(64);params.set('code_challenge',base64Url(await sha256(transaction.verifier)));params.set('code_challenge_method','S256');} if(force)params.set('prompt','login'); saveTransaction(transaction); reportStage('authorization-redirect', oidc.authorization_endpoint); window.location.replace(`${oidc.authorization_endpoint}?${params.toString()}`); return false; } catch(error) { redirecting=false; throw error; } };
  const recoverMissingTransaction = async () => { const previous=Number(readDurable(recoveryKey)||0); const now=Date.now(); if(previous&&now-previous<CALLBACK_RECOVERY_WINDOW_MS) throw new Error('OIDC state mismatch after automatic recovery'); writeDurable(recoveryKey,String(now)); clearTransactions(); const returnTo=stripOidcCallback(); return login({returnTo}); };

  const init = async () => { reportStage('auth-init'); await loadDiscovery(); reportStage('callback-check'); const callback=currentCallback(); if(callback.error){removeTransaction(callback.state);throw new Error(`OIDC authorization error: ${callback.errorDescription||callback.error}`);} if(callback.code){try{await exchangeCode(callback);}catch(error){if(error?.code==='OIDC_STATE_MISMATCH')return recoverMissingTransaction();throw error;}} const current=await ensureFresh(); if(!current)return login(); removeDurable(recoveryKey); reportStage('authenticated'); return true; };
  const authenticatedFetch = async (input,init={}) => { const current=await ensureFresh(); if(!current?.access_token)throw new Error('Authentication session is missing or expired'); const headers=new Headers(init.headers??(input instanceof Request?input.headers:undefined)); headers.set('Authorization',`Bearer ${current.access_token}`); headers.set('X-Irlix-Access-Token',current.access_token); return nativeFetch(input,{...init,headers}); };
  const logout = async () => { const current=loadTokens(); saveTokens(null); clearTransactions(); removeDurable(recoveryKey); const oidc=await loadDiscovery(); const endpoint=oidc.end_session_endpoint; if(!endpoint){window.location.replace(defaultReturnTo);return;} const params=new URLSearchParams({client_id:clientId,post_logout_redirect_uri:`${window.location.origin}${defaultReturnTo}`}); if(current?.id_token)params.set('id_token_hint',current.id_token); window.location.replace(`${endpoint}?${params.toString()}`); };

  return { init, login, logout, fetch:authenticatedFetch, clear:()=>{saveTokens(null);clearTransactions();removeDurable(recoveryKey);}, async token(){return(await ensureFresh())?.access_token||null;}, get user(){return parseJwt(loadTokens()?.access_token||'');} };
};
