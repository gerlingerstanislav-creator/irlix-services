import { createBrowserAuth } from '@irlix/auth';

const nativeFetch = window.fetch.bind(window);
const browserAuth = createBrowserAuth({
  storagePrefix: 'irlix.employees.auth',
  defaultReturnTo: '/employees/',
});

let apiFetchInstalled = false;

const isProtectedApiRequest = (input) => {
  const raw = typeof input === 'string' ? input : input?.url;
  if (!raw) return false;
  const url = new URL(raw, window.location.origin);
  return url.origin === window.location.origin && url.pathname.startsWith('/api/');
};

const authenticatedFetch = async (input, init = {}) => browserAuth.fetch(input, init);

const installApiFetch = () => {
  if (apiFetchInstalled) return;
  apiFetchInstalled = true;
  window.fetch = async (input, init = {}) => {
    if (!isProtectedApiRequest(input)) return nativeFetch(input, init);
    return authenticatedFetch(input, init);
  };
};

export const auth = {
  async init() {
    const authenticated = await browserAuth.init();
    if (authenticated) installApiFetch();
    return authenticated;
  },
  login: () => browserAuth.login(),
  token: () => browserAuth.token(),
  fetch: authenticatedFetch,
  logout: () => browserAuth.logout(),
  get user() {
    return browserAuth.user;
  },
};
