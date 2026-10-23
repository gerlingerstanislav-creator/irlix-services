// Local preview only; synthetic API/auth fixtures, no production session.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_EXECUTABLE, args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [], writes = [];
    let savedHours = 4;
    let savedDescription = 'Synthetic design work';
    let confirmed = false;
    let approved = false;
    page.on('pageerror', error => errors.push(error.message));
    const base = process.env.TIMESHEETS_PREVIEW || 'http://127.0.0.1:5194';
    const issuer = base + '/keycloak/auth/realms/irlix';
    await page.addInitScript(issuer => sessionStorage.setItem('irlix.platform.auth.tokens', JSON.stringify({ access_token: 'e30.' + btoa(JSON.stringify({ iss: issuer, exp: Math.floor(Date.now() / 1000) + 3600, name: 'Synthetic Operator' })) + '.synthetic' })), issuer);
    await page.route('**/.well-known/openid-configuration', route => route.fulfill({ json: { issuer, authorization_endpoint: issuer + '/auth', token_endpoint: issuer + '/token' } }));
    await page.route('**/api/**', route => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith('/employees/access/me')) return route.fulfill({ json: { data: { services: { timesheets: true } } } });
      if (path.endsWith('/permissions/me')) return route.fulfill({ json: { data: { permissions: { 'timesheets.mine.view': { allowed: true }, 'timesheets.management.view': { allowed: true }, 'timesheets.management.manage': { allowed: true } } } } });
      if (path.endsWith('/partner-hours') && route.request().method() === 'PUT') {
        const body = route.request().postDataJSON(); writes.push({ path, body });
        savedHours = Number(body.hours); savedDescription = body.description || '';
        return route.fulfill({ json: { data: { saved: true } } });
      }
      if (path.endsWith('/partner-hours/confirm') || path.endsWith('/partner-hours/final-approval')) {
        const body = route.request().postDataJSON(); writes.push({ path, body });
        if (path.endsWith('/partner-hours/confirm')) confirmed = body.confirmed;
        if (path.endsWith('/partner-hours/final-approval')) approved = body.approved;
        return route.fulfill({ json: { data: { saved: true } } });
      }
      if (path.endsWith('/partner-hours')) return route.fulfill({ json: { data: {
        members: [{ client_member_id: 42, client_id: 7, client_name: 'Synthetic Client', specialist_name: 'Synthetic Partner', valid_from: '2026-10-01', valid_to: '2026-10-31' }],
        entries: [{ client_member_id: 42, work_date: '2026-10-01', hours: savedHours, description: savedDescription }],
        confirmations: confirmed ? [{ client_member_id: 42, work_date: '2026-10-01' }] : [], final_approvals: approved ? [{ client_member_id: 42, month: '2026-10-01' }] : [], locked_reporting_periods: []
      } } });
      return route.fulfill({ json: { data: {} } });
    });
    // Navigate as a user: the service admission check and OIDC bootstrap may
    // canonicalize the initial URL before the timesheet section is mounted.
    await page.goto(base + '/timesheets/?month=2026-10');
    await page.getByRole('button',{name:'ТШ партнёров',exact:true}).first().click();
    try { await page.getByText('Synthetic Client / Synthetic Partner', { exact: true }).waitFor({timeout:8000}); }
    catch(error) { console.error('Partner page diagnostic', {url:page.url(), body:(await page.locator('body').innerText()).slice(0,3500), errors}); throw error; }
    const screenshotDir = process.env.QA_BROWSER_REPORT || '/tmp/qa-browser-report';
    require('node:fs').mkdirSync(screenshotDir, { recursive: true });
    await page.screenshot({ path: screenshotDir + '/timesheets-partner-hours.png', fullPage: true });

    const firstRow = page.getByRole('row').filter({ hasText: '01.10.2026' });
    await firstRow.locator('input[type="number"]').fill('5');
    await firstRow.locator('input[type="text"]').fill('Synthetic design and review');
    await firstRow.locator('input[type="text"]').blur();
    await page.waitForFunction(() => document.querySelector('.partner-hour-summary')?.textContent.includes('5.00'));
    assert.equal(writes[0].path.endsWith('/partner-hours'), true);
    assert.deepEqual(writes[0].body, { client_member_id: 42, work_date: '2026-10-01', hours: 5, description: 'Synthetic design and review' });

    await firstRow.getByRole('button', { name: 'Подтвердить', exact: true }).click();
    await page.getByRole('button', { name: '✓ Финально подтвердить месяц', exact: true }).click();
    assert.deepEqual(writes.slice(1).map(x => x.body), [
      { client_member_id: 42, work_date: '2026-10-01', confirmed: true },
      { client_member_id: 42, month: '2026-10', approved: true }
    ]);
    assert.deepEqual(errors, []);
    console.log('Partner hours browser passed: rendered client/member, saved canonical hours, confirmed day and month.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
