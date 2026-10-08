import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../src/index.js', import.meta.url), 'utf8');
const { createBrowserAuth } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const issuer = 'http://example.invalid/keycloak/auth/realms/irlix';
const jwt = exp => `h.${Buffer.from(JSON.stringify({ iss: issuer, exp })).toString('base64url')}.s`;
const tokens = () => ({ access_token: jwt(Math.floor(Date.now() / 1000) - 1), refresh_token: 'synthetic-refresh' });
const storage = () => {
  const map = new Map();
  return { getItem: k => map.get(k) ?? null, setItem: (k, v) => map.set(k, v), removeItem: k => map.delete(k) };
};
const setup = responder => {
  globalThis.sessionStorage = storage();
  globalThis.localStorage = storage();
  const calls = [];
  globalThis.window = {
    location: { origin: 'http://example.invalid' }, setTimeout, clearTimeout,
    fetch: async (url, init) => {
      if (String(url).includes('.well-known')) return Response.json({ issuer,
        authorization_endpoint: `${issuer}/protocol/openid-connect/auth`,
        token_endpoint: `${issuer}/protocol/openid-connect/token` });
      if (init?.body?.get?.('grant_type') === 'refresh_token') { calls.push(init.body); return responder(); }
      return Response.json({ authorization: init.headers.get('Authorization') });
    },
  };
  sessionStorage.setItem('test.tokens', JSON.stringify(tokens()));
  return { auth: createBrowserAuth({ storagePrefix: 'test' }), calls };
};
const fresh = () => Response.json({ access_token: jwt(Math.floor(Date.now() / 1000) + 600), refresh_token: 'synthetic-next' });

test('concurrent API requests share one refresh and use its new token', async () => {
  const { auth, calls } = setup(async () => { await new Promise(r => setTimeout(r, 5)); return fresh(); });
  const responses = await Promise.all([auth.fetch('/api/a'), auth.fetch('/api/b'), auth.token()]);
  assert.equal(calls.length, 1);
  assert.equal((await responses[0].json()).authorization, `Bearer ${responses[2]}`);
});

test('temporary refresh failure keeps tokens and the next attempt can recover', async () => {
  let failed = false;
  const { auth } = setup(() => { if (!failed) { failed = true; return Response.json({}, { status: 503 }); } return fresh(); });
  await assert.rejects(auth.fetch('/api/a'), /temporarily unavailable/);
  assert.ok(sessionStorage.getItem('test.tokens'));
  assert.equal((await auth.fetch('/api/a')).status, 200);
});

test('network failure keeps the refresh token', async () => {
  const { auth } = setup(() => { throw new TypeError('offline'); });
  await assert.rejects(auth.fetch('/api/a'), /offline/);
  assert.ok(sessionStorage.getItem('test.tokens'));
});

test('invalid_grant clears the expired or revoked session', async () => {
  const { auth } = setup(() => Response.json({ error: 'invalid_grant' }, { status: 400 }));
  await assert.rejects(auth.fetch('/api/a'), /missing or expired/);
  assert.equal(sessionStorage.getItem('test.tokens'), null);
});

test('late refresh does not restore an explicitly cleared session', async () => {
  let release;
  const { auth } = setup(() => new Promise(r => { release = r; }));
  const pending = auth.fetch('/api/a');
  while (!release) await new Promise(r => setTimeout(r, 0));
  auth.clear(); release(fresh());
  await assert.rejects(pending, /missing or expired/);
  assert.equal(sessionStorage.getItem('test.tokens'), null);
});

test('a frozen tab refreshes successfully after four hours with no intervening requests', async () => {
  const originalNow = Date.now;
  const started = originalNow();
  const { auth, calls } = setup(fresh);
  sessionStorage.setItem('test.tokens', JSON.stringify({ access_token: jwt(Math.floor(started / 1000) + 600), refresh_token: 'synthetic-refresh' }));
  Date.now = () => started + 4 * 60 * 60 * 1000;
  try { assert.equal((await auth.fetch('/api/a')).status, 200); assert.equal(calls.length, 1); }
  finally { Date.now = originalNow; }
});

test('realm import and bootstrap enforce idle/max limits and clear client overrides', () => {
  const realm = JSON.parse(fs.readFileSync(new URL('../../../infra/keycloak/irlix-realm.json', import.meta.url)));
  const bootstrap = fs.readFileSync(new URL('../../../infra/keycloak/bootstrap.sh', import.meta.url), 'utf8');
  for (const [key, value] of Object.entries({ accessTokenLifespan: 600, ssoSessionIdleTimeout: 18000,
    ssoSessionMaxLifespan: 86400, ssoSessionIdleTimeoutRememberMe: 18000,
    ssoSessionMaxLifespanRememberMe: 86400, clientSessionIdleTimeout: 0, clientSessionMaxLifespan: 0 })) {
    assert.equal(realm[key], value); assert.ok(bootstrap.includes(`-s ${key}=${value}`));
  }
  const client = realm.clients.find(c => c.clientId === 'irlix-services-web');
  for (const key of ['client.session.idle.timeout', 'client.session.max.lifespan']) {
    assert.equal(client.attributes[key], '0'); assert.ok(bootstrap.includes(`attributes."${key}"="0"`));
  }
  assert.ok(realm.ssoSessionIdleTimeout > 4 * 3600);
});
