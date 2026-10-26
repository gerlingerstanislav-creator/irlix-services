// Local preview only; synthetic partner UI/API fixture, no production session.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_EXECUTABLE,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [], writes = [];
    let savedHours = 4, savedDescription = 'Synthetic design work', confirmed = false, approved = false;
    const analyticsRequests = [];
    page.on('pageerror', error => errors.push(error.message));
    const base = process.env.TIMESHEETS_PREVIEW || 'http://127.0.0.1:5194';
    const issuer = base + '/keycloak/auth/realms/irlix';
    await page.addInitScript(issuer => sessionStorage.setItem('irlix.platform.auth.tokens',
      JSON.stringify({ access_token: 'e30.' + btoa(JSON.stringify({
        iss: issuer, exp: Math.floor(Date.now() / 1000) + 3600, name: 'Synthetic Operator'
      })) + '.synthetic' })), issuer);
    await page.route('**/.well-known/openid-configuration', route => route.fulfill({
      json: { issuer, authorization_endpoint: issuer + '/auth', token_endpoint: issuer + '/token' }
    }));
    await page.route('**/api/**', route => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith('/employees/access/me')) return route.fulfill({ json: {
        data: { services: { timesheets: true } }
      } });
      if (path.endsWith('/permissions/me')) return route.fulfill({ json: { data: { permissions: {
        'timesheets.mine.view': { allowed: true },
        'timesheets.management.view': { allowed: true },
        'timesheets.management.manage': { allowed: true },
        'timesheets.analytics.view': { allowed: true }
      } } } });
      if (path.endsWith('/production-calendar')) return route.fulfill({ json: { data: { days: {} } } });
      if (path.endsWith('/management')) return route.fulfill({ json: { data: {
        employees: [], current_employee: { id: 100, full_name: 'Operator' }, departments: [],
        assignments: [], entries: [], confirmations: [], final_approvals: [],
        final_approval_exceptions: [], absences: [], client_reporting_periods: []
      } } });
      if (path.endsWith('/analytics')) {
        const staffType = new URL(route.request().url()).searchParams.get('staff_type');
        analyticsRequests.push(staffType);
        const employees = staffType === 'staff' ? 1 : 2;
        return route.fulfill({ json: { data: {
          totals: { employees, norm_hours: 176, commercial_hours: 8, commercial_percent: 4.55,
            idle_hours: 168, absences: {}, absence_hours: 0 },
          filters: { departments: [], clients: [] }, employees: [], partners: [],
          departments: [], type_labels: {}
        } } });
      }
      if (path.endsWith('/partner-hours') && route.request().method() === 'PUT') {
        const body = route.request().postDataJSON(); writes.push({ path, body });
        savedHours = Number(body.hours); savedDescription = body.description || '';
        confirmed = false;
        return route.fulfill({ json: { data: { ok: true } } });
      }
      if (path.endsWith('/partner-hours/confirm') || path.endsWith('/partner-hours/final-approval')) {
        const body = route.request().postDataJSON(); writes.push({ path, body });
        if (path.endsWith('/partner-hours/confirm')) confirmed = body.confirmed;
        else approved = body.approved;
        return route.fulfill({ json: { data: { ok: true } } });
      }
      if (path.endsWith('/partner-hours')) return route.fulfill({ json: { data: {
        members: [{ client_member_id: 42, client_id: 7, client_name: 'Synthetic Client',
          partner_specialist_id: 9, specialist_name: 'Synthetic Partner', account_employee_id: 100,
          valid_from: '2026-10-01', valid_to: '2026-10-31' }],
        entries: [{ client_member_id: 42, work_date: '2026-10-01',
          hours: savedHours, description: savedDescription }],
        confirmations: confirmed ? [{ client_member_id: 42, work_date: '2026-10-01' }] : [],
        final_approvals: approved ? [{ client_member_id: 42, month: '2026-10-01' }] : [],
        final_approval_exceptions: [], locked_reporting_periods: []
      } } });
      return route.fulfill({ json: { data: {} } });
    });

    await page.goto(base + '/timesheets/?month=2026-10');
    await page.getByRole('button', { name: 'Управление', exact: true }).first().click();
    const row = page.locator('.matrix tbody tr').filter({ hasText: 'Synthetic Partner' }).first();
    await row.waitFor({ timeout: 8000 });
    assert.match(await row.locator('.name strong').innerText(), /Synthetic Partner \(Партнер\)/);
    assert.equal(await row.locator('.name small').innerText(), '01.10.2026–31.10.2026');
    assert.equal(await page.getByRole('button', { name: 'ТШ партнёров', exact: true }).count(), 0);
    const screenshotDir = process.env.QA_BROWSER_REPORT || '/tmp/qa-browser-report';
    require('node:fs').mkdirSync(screenshotDir, { recursive: true });
    await page.screenshot({ path: screenshotDir + '/timesheets-partners-in-management.png', fullPage: true });

    await row.locator('.matrix-cell').first().dblclick();
    await page.locator('.manager-edit-modal input[type="number"]').fill('5');
    await page.locator('.manager-edit-modal textarea').fill('Synthetic design and review');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await page.waitForFunction(() => [...document.querySelectorAll('.matrix tbody tr')]
      .some(row => row.textContent.includes('Synthetic Partner') && row.textContent.includes('5.00')));
    assert.deepEqual(writes.filter(x => x.path.endsWith('/partner-hours')).at(-1).body, {
      client_member_id: 42, work_date: '2026-10-01', hours: 5, description: 'Synthetic design and review'
    });
    await row.locator('.matrix-cell').first().dblclick();
    await page.getByRole('button', { name: 'Подтвердить день партнёра' }).click();
    await page.waitForFunction(() => !document.querySelector('.manager-edit-modal'));
    await row.getByRole('button', { name: 'Финально подтвердить специалиста за месяц' }).click();
    await page.waitForFunction(() => [...document.querySelectorAll('.matrix tbody tr')]
      .some(row => row.textContent.includes('Synthetic Partner') && row.querySelector('.final')));
    assert.deepEqual(writes.filter(x => !x.path.endsWith('/partner-hours')).map(x => x.body), [
      { client_member_id: 42, work_date: '2026-10-01', confirmed: true },
      { client_member_id: 42, month: '2026-10', approved: true },
    ]);
    // The analytics staff-type filter must use the same shared chevron and
    // clear behavior as its neighboring filters. Clearing means "all", not an
    // empty/unrecognized API staff_type that silently excludes everyone.
    await page.getByRole('button', { name: 'Коммерческая загрузка', exact: true }).first().click();
    const filter = page.locator('.analytics-filters .ui-search-select').nth(2);
    await filter.locator('.ui-search-select__trigger').waitFor({ timeout: 8000 });
    assert.equal(await page.locator('.analytics-filters .ui-search-select').count(), 3);
    assert.equal(await page.locator('.analytics-filters > label, .analytics-filters select').count(), 0);
    assert.match(await filter.locator('.ui-search-select__trigger').innerText(), /Все/);
    assert.equal(analyticsRequests.at(-1), 'all');

    await filter.locator('.ui-search-select__trigger').click();
    const filtered = page.waitForResponse(response =>
      response.url().includes('/api/timesheets/analytics?')
      && new URL(response.url()).searchParams.get('staff_type') === 'staff');
    await page.getByRole('option', { name: 'Штат', exact: true }).click();
    await filtered;
    assert.equal(analyticsRequests.at(-1), 'staff');
    assert.match(await filter.locator('.ui-search-select__trigger').innerText(), /Штат/);

    const reset = page.waitForResponse(response =>
      response.url().includes('/api/timesheets/analytics?')
      && new URL(response.url()).searchParams.get('staff_type') === 'all');
    await filter.locator('.ui-search-select__clear').click();
    await reset;
    assert.equal(analyticsRequests.at(-1), 'all');
    assert.match(await filter.locator('.ui-search-select__trigger').innerText(), /Все/);
    assert.equal(await filter.locator('.ui-search-select__clear').count(), 0);
    // A historical partner link must canonicalize to Management without losing context.
    await page.goto(base + '/timesheets/partners/?month=2026-10&client_id=7&employee_id=9#partner');
    await page.waitForURL(url => url.pathname === '/timesheets/management/'
      && url.searchParams.get('month') === '2026-10'
      && url.searchParams.get('client_id') === '7'
      && url.searchParams.get('employee_id') === '9'
      && url.hash === '#partner');
    assert.deepEqual(errors, []);
    console.log('Unified partner management and legacy deep-link routing passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
