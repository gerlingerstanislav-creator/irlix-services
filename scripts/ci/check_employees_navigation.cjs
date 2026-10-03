// Read-only browser smoke against the deployed frontend through an SSH tunnel.
// Authentication and business API responses are synthetic; production records are never loaded.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const raw = process.env.IRLIX_LOCAL_URL;
  assert(raw, 'IRLIX_LOCAL_URL is required');
  const publicUrl = new URL(raw.includes('://') ? raw : `http://${raw}`);
  const tunnel = 'http://127.0.0.1:18080';
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const issuer = tunnel + '/keycloak/auth/realms/irlix';
    await page.addInitScript(({ issuer }) => {
      const payload = btoa(JSON.stringify({ iss: issuer, exp: Math.floor(Date.now() / 1000) + 3600, preferred_username: 'synthetic-admin' }));
      sessionStorage.setItem('irlix.platform.auth.tokens', JSON.stringify({ access_token: `header.${payload}.signature` }));
    }, { issuer });
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.pathname.includes('openid-configuration')) return route.fulfill({ json: { issuer, authorization_endpoint: issuer + '/auth', token_endpoint: issuer + '/token' } });
      if (url.pathname.startsWith('/api/')) return route.fulfill({ json: { data: url.pathname.endsWith('/access/me')
        ? { allowed: true, roles: ['platform-admin'], permissions: { 'staff_positions.manage': true, 'access.manage': true, 'audit.read': true } }
        : url.pathname.endsWith('/reference-data') ? { employee_statuses: [], work_formats: [], cooperation_types: [], genders: [] } : [] } });
      assert.equal(url.origin, tunnel, 'Unexpected frontend navigation origin');
      const response = await fetch(url, { headers: { Host: publicUrl.host }, redirect: 'manual' });
      const body = Buffer.from(await response.arrayBuffer());
      if (url.pathname.endsWith('.js')) {
        assert(response.headers.get('content-type')?.includes('javascript'), 'Frontend asset returned HTML instead of JavaScript');
        console.log(`[browser] deployed JS ${url.pathname}`);
      }
      await route.fulfill({ status: response.status, headers: Object.fromEntries([...response.headers].filter(([key]) => !['content-encoding', 'content-length', 'transfer-encoding'].includes(key))), body });
    });
    await page.goto(tunnel + '/employees/');
    await page.getByRole('button', { name: 'Штатное расписание', exact: true }).first().waitFor();
    await page.evaluate(() => window.navigationSmokeMarker = 'same-document');
    await page.getByRole('button', { name: 'Штатное расписание', exact: true }).first().click();
    await page.locator('.staffing-route').waitFor();
    assert.equal(new URL(page.url()).pathname, '/employees/staff-positions');
    assert.equal(await page.evaluate(() => window.navigationSmokeMarker), 'same-document', 'Sidebar reloaded the document');
    await page.goBack();
    await page.locator('.registry-scroll-panel').waitFor();
    assert.equal(new URL(page.url()).pathname, '/employees/');
    await page.goForward();
    await page.locator('.staffing-route').waitFor();
    await page.reload();
    await page.locator('.staffing-route').waitFor();
    await page.goto(tunnel + '/employees/staff-positions');
    await page.locator('.staffing-route').waitFor();
    assert.deepEqual(errors, [], 'Browser runtime errors');
    console.log('[browser] Employees sidebar, URL, Back/Forward, refresh and direct staffing route PASS');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
