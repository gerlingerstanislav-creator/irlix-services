import { createBrowserAuth } from '@irlix/auth';

const browserAuth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/cv/' });

export const auth = {
  init: () => browserAuth.init(),
  logout: () => browserAuth.logout(),
  get user() { return browserAuth.user; },
};
