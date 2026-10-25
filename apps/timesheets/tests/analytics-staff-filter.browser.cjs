// Local preview only: verify the shared DS select and reset-to-all behavior.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_EXECUTABLE,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const errors = [], requestedTypes = [];
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
      const url = new URL(route.request().url()), path = url.pathname;
      if (path.endsWith('/employees/access/me')) return route.fulfill({ json: {
        data: { services: { timesheets: true } }
      } });
      if (path.endsWith('/permissions/me')) return route.fulfill({ json: { data: { permissions: {
        'timesheets.mine.view': { allowed: true },
        'timesheets.management.view': { allowed: true },
        'timesheets.analytics.view': { allowed: true }
      } } } });
      if (path.endsWith('/analytics')) {
        const type = url.searchParams.get('staff_type');
        requestedTypes.push(type);
        const staff = type !== 'partners', partners = type !== 'staff';
        const employees = staff ? [{
          employee_id: 1, employee_name: 'Staff Specialist', commercial_hours: 8,
          norm_hours: 176, commercial_percent: 4.55, absence_hours: 0, idle_hours: 168
        }] : [];
        const partnerRows = partners ? [{
          identity_type: 'partner', partner_specialist_id: 2, employee_name: 'Partner Specialist',
          client_name: 'Synthetic Client', commercial_hours: 8, norm_hours: 8,
          commercial_percent: 100, absence_hours: 0, idle_hours: 0
        }] : [];
        return route.fulfill({ json: { data: {
          filters: { departments: [{ id: '10', name: 'Production' }],
            clients: [{ id: 12, name: 'Synthetic Client' }] },
          totals: { employees: employees.length + partnerRows.length,
            commercial_hours: 8 * (employees.length + partnerRows.length),
            norm_hours: (staff ? 176 : 0) + (partners ? 8 : 0),
            commercial_percent: 50, idle_hours: staff ? 168 : 0, absences: {} },
          type_labels: {}, employees, partners: partnerRows, departments: []
        } } });
      }
      return route.fulfill({ json: { data: {} } });
    });

    // Exercise the same sidebar navigation as the existing Timesheets browser scenario.
    await page.goto(base + '/timesheets/?month=2026-10');
    await page.getByRole('button', { name: 'Коммерческая загрузка', exact: true }).first().click();
    const select = page.locator('.analytics-filters .ui-search-select').nth(2);
    try {
      await select.waitFor({ timeout: 10000 });
    } catch (error) {
      console.error('Analytics filter did not render', {
        pathname: new URL(page.url()).pathname,
        text: (await page.locator('body').innerText()).slice(0, 1600), pageErrors: errors
      });
      throw error;
    }
    const trigger = select.locator('.ui-search-select__trigger');
    assert.equal(await page.locator('.analytics-filters select').count(), 0,
      'No native select/incorrect browser chevron');
    assert.equal(await page.locator('.analytics-staff-filter').count(), 0,
      'No external staff label');
    assert.equal(await trigger.getAttribute('aria-label'), 'Штат');
    assert.equal((await trigger.innerText()).trim(), 'Все');
    assert.equal(requestedTypes.at(-1), 'all');

    const selectOption = async (option, type) => {
      await trigger.click();
      const response = page.waitForResponse(res => {
        const url = new URL(res.url());
        return url.pathname.endsWith('/analytics') && url.searchParams.get('staff_type') === type;
      });
      await page.getByRole('option', { name: option, exact: true }).click();
      await response;
      assert.equal(requestedTypes.at(-1), type);
    };
    await selectOption('Партнеры', 'partners');
    assert.equal((await trigger.innerText()).trim(), 'Партнеры');
    await page.locator('.analytics-page .kpi strong').first().waitFor();
    assert.equal((await page.locator('.analytics-page .kpi strong').first().innerText()).trim(), '1');

    // The same shared clear button used by the neighboring client/department
    // selects restores BOTH staff and partners (staff_type=all).
    const cleared = page.waitForResponse(res => {
      const url = new URL(res.url());
      return url.pathname.endsWith('/analytics') && url.searchParams.get('staff_type') === 'all';
    });
    await select.locator('.ui-search-select__clear').click();
    await cleared;
    assert.equal((await trigger.innerText()).trim(), 'Все');
    await page.waitForFunction(() => document.querySelector('.analytics-page .kpi strong')?.textContent?.trim() === '2');
    assert.equal(await select.locator('.ui-search-select__clear').count(), 0);

    await selectOption('Штат', 'staff');
    assert.equal((await trigger.innerText()).trim(), 'Штат');
    assert.equal(requestedTypes.at(-1), 'staff');
    const screenshotDir = process.env.QA_BROWSER_REPORT || '/tmp/qa-browser-report';
    require('node:fs').mkdirSync(screenshotDir, { recursive: true });
    await page.screenshot({ path: screenshotDir + '/timesheets-analytics-staff-filter.png', fullPage: true });
    assert.deepEqual(errors, []);
    console.log('Timesheets staff filter uses shared DS control and resets to staff_type=all.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
