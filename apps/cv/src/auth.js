import { createBrowserAuth } from '@irlix/auth';

const browserAuth = createBrowserAuth({
  storagePrefix: 'irlix.platform.auth',
  defaultReturnTo: '/cv-converter/',
});

export const auth = {
  init: () => browserAuth.init(),
  logout: () => browserAuth.logout(),
  fetch: (input, init = {}) => browserAuth.fetch(input, init),
  token: () => browserAuth.token(),
  get user() { return browserAuth.user; },
};
