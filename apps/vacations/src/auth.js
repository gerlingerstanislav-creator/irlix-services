import { createBrowserAuth } from '@irlix/auth';

const nativeFetch = window.fetch.bind(window);
const browserAuth = createBrowserAuth({
  storagePrefix: 'irlix.platform.auth',
  defaultReturnTo: '/vacations/',
});

let installed = false;
const isProtectedApiRequest = (input) => {
  const raw = typeof input === 'string' ? input : input?.url;
  if (!raw) return false;
  const url = new URL(raw, window.location.origin);
  return url.origin === window.location.origin && url.pathname.startsWith('/api/');
};

export const auth = {
  async init() {
    const authenticated = await browserAuth.init();
    if (authenticated && !installed) {
      installed = true;
      window.fetch = async (input, init = {}) => isProtectedApiRequest(input)
        ? browserAuth.fetch(input, init)
        : nativeFetch(input, init);
    }
    return authenticated;
  },
  fetch: (input, init = {}) => browserAuth.fetch(input, init),
  logout: () => browserAuth.logout(),
  get user() { return browserAuth.user; },
};
