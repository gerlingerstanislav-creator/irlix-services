import test from 'node:test';
import assert from 'node:assert/strict';
import { runAuthenticatedStartup } from '../src/startup.js';

const setup = ({ authenticated = true, initialize = async () => {} } = {}) => {
  const events = [];
  const start = () => runAuthenticatedStartup({
    authenticate: async () => {
      events.push('auth');
      if (authenticated instanceof Error) throw authenticated;
      return authenticated;
    },
    initialize: async () => { events.push('access-start'); await initialize(); events.push('access-done'); },
    onError: error => events.push('error:' + error.message),
    onReady: () => events.push('ready'),
  });
  return { events, start };
};

test('OIDC redirect keeps the loader visible instead of flashing a 403', async () => {
  const { events, start } = setup({ authenticated: false });
  await start();
  assert.deepEqual(events, ['auth']); // No access request and no ready/403 render.
});

test('initial loading stays up until the access check finishes', async () => {
  let resolveAccess;
  const { events, start } = setup({
    initialize: () => new Promise(resolve => { resolveAccess = resolve; }),
  });
  const pending = start();
  await Promise.resolve();
  await Promise.resolve();
  assert.deepEqual(events, ['auth', 'access-start']);
  resolveAccess();
  await pending;
  assert.deepEqual(events, ['auth', 'access-start', 'access-done', 'ready']);
});

test('successful authentication reveals the authorized UI', async () => {
  const { events, start } = setup();
  await start();
  assert.deepEqual(events, ['auth', 'access-start', 'access-done', 'ready']);
});

test('authenticated users without service permission still resolve to a real 403', async () => {
  let allowed = false;
  const view = [];
  await runAuthenticatedStartup({
    authenticate: async () => true,
    initialize: async () => { allowed = false; },
    onError: () => view.push('503'),
    onReady: () => view.push(allowed ? 'app' : '403'),
  });
  assert.deepEqual(view, ['403']);
});

test('OIDC and API failures yield an error state rather than an unauthorized flash', async () => {
  const oidc = setup({ authenticated: new Error('OIDC unavailable') });
  await oidc.start();
  assert.deepEqual(oidc.events, ['auth', 'error:OIDC unavailable', 'ready']);

  const api = setup({ initialize: async () => { throw new Error('access lookup unavailable'); } });
  await api.start();
  assert.deepEqual(api.events, ['auth', 'access-start', 'error:access lookup unavailable', 'ready']);
});
