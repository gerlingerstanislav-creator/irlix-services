// Regression for catalog-owned history, grouped menu and shared component controls.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_EXECUTABLE, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    const base = process.env.DESIGN_SYSTEM_PREVIEW || 'http://127.0.0.1:5196';
    const issuer = `${base}/keycloak/auth/realms/irlix`;
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(issuer => sessionStorage.setItem('irlix.platform.auth.tokens', JSON.stringify({ access_token: `e30.${btoa(JSON.stringify({ iss: issuer, exp: Math.floor(Date.now() / 1000) + 3600, name: 'Synthetic Administrator' }))}.synthetic` })), issuer);
    await page.route('**/.well-known/openid-configuration', route => route.fulfill({ json: { issuer, authorization_endpoint: `${issuer}/auth`, token_endpoint: `${issuer}/token` } }));
    await page.route('**/api/**', route => route.fulfill({ json: { data: { services: { 'design-system': true }, roles: ['platform-admin'] } } }));
    const groups = [
      ['foundations', 'Основы'], ['tables-lists', 'Таблицы и списки'], ['buttons-actions', 'Кнопки и действия'], ['fields', 'Поля ввода и выбор'],
      ['statuses', 'Статусы и загрузка'], ['tabs', 'Вкладки и переключатели'], ['panels', 'Карточки и панели'], ['navigation', 'Навигация'],
    ];
    const menu = label => page.locator('.icon-nav').getByRole('button', { name: label, exact: true });
    async function atGroup(id, label) {
      await page.locator('main.ds-page').waitFor({ state: 'visible' });
      await page.waitForFunction(({ id, label }) => {
        const target = document.getElementById(`section-${id}`);
        const button = [...document.querySelectorAll('.icon-nav button')].find(button => button.getAttribute('aria-label') === label);
        const topbar = document.querySelector('.irlix-app-shell__workspace > .irlix-app-topbar');
        if (!target || !button || !topbar) return false;
        const sticky = topbar.getBoundingClientRect();
        return Math.abs(target.getBoundingClientRect().top - sticky.bottom - 16) < 3 && button.classList.contains('active');
      }, { id, label });
    }
    await page.goto(`${base}/design-system/#section-fields`);
    await page.locator('main').waitFor();
    await page.evaluate(() => document.fonts.ready);
    // Scroll settles after the initial font load; reload must preserve the direct link.
    await page.reload();
    await atGroup('fields', 'Поля ввода и выбор');
    assert.deepEqual(await page.locator('main > [data-catalog-group]').evaluateAll(nodes => nodes.map(node => [node.dataset.catalogGroup, node.querySelector('h2').textContent])), groups);
    assert.equal(await page.locator('main .ui-registry-table').count(), 1, 'one canonical shared registry');
    assert.equal(await page.locator('#section-service-dashboard, #filter-rail-showcase').count(), 0);
    assert.equal(await page.getByText('Леманал Про', { exact: true }).count(), 0);
    for (const width of [1366, 420]) {
      await page.setViewportSize({ width, height: 900 });
      await page.evaluate(() => { window.__catalogDocument = 'same-document'; });
      for (const [id, label] of groups) {
        await menu(label).click();
        await atGroup(id, label);
        assert.equal(new URL(page.url()).hash, `#section-${id}`);
        assert.equal(await page.evaluate(() => window.__catalogDocument), 'same-document', 'menu navigation must not reload');
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `no page overflow at ${width}/${id}`);
        const style = await page.locator(`#heading-${id}`).evaluate(node => ({ border: parseFloat(getComputedStyle(node).borderTopWidth), after: getComputedStyle(node, '::after').height }));
        assert.ok(style.border >= 1, 'visible horizontal group divider');
      }
    }
    await menu('Кнопки и действия').click();
    await atGroup('buttons-actions', 'Кнопки и действия');
    await menu('Поля ввода и выбор').click();
    await atGroup('fields', 'Поля ввода и выбор');
    await page.goBack();
    await atGroup('buttons-actions', 'Кнопки и действия');
    await page.goForward();
    await atGroup('fields', 'Поля ввода и выбор');
    await page.reload();
    await atGroup('fields', 'Поля ввода и выбор');
    await page.goto(`${base}/design-system/components/?section=filters#section-checkbox`);
    await page.waitForURL('**/design-system/#section-checkbox');
    await page.waitForFunction(() => document.querySelector('.icon-nav button[aria-label="Поля ввода и выбор"]').classList.contains('active'));
    const checkbox = page.getByRole('checkbox', { name: 'Активный параметр' });
    await checkbox.uncheck();
    assert.equal(await checkbox.isChecked(), false);
    await checkbox.check();
    await menu('Карточки и панели').click();
    await page.getByRole('button', { name: 'Открыть карточку', exact: true }).click();
    await page.getByRole('button', { name: 'Открыть вложенную карточку' }).click();
    assert.equal(await page.locator('.irlix-drawer').count() > 0, true);
    await page.locator('.irlix-drawer-close').last().click();
    await page.locator('.irlix-drawer-close').last().click();
    await page.goto(`${base}/design-system/#section-foundations`);
    for (const label of ['Тёмная', 'Светлая']) {
      await page.getByRole('button', { name: label, exact: true }).first().click();
      assert.equal(await page.getByRole('button', { name: label, exact: true }).first().getAttribute('aria-pressed'), 'true');
    }
    await page.locator('#section-statuses').evaluate(node => node.scrollIntoView({ block: 'start' }));
    await page.waitForFunction(() => document.querySelector('.icon-nav button[aria-label="Статусы и загрузка"]').classList.contains('active'));
    for (const invalid of ['constructor', 'toString', '__proto__', 'missing-section']) {
      await page.goto(`${base}/design-system/#section-${invalid}`);
      await page.waitForURL('**/design-system/#section-foundations');
      await atGroup('foundations', 'Основы');
    }
    assert.deepEqual(errors, []);
    console.log('Catalog navigation Chromium PASS: eight grouped dividers/menu targets, desktop/mobile clicks without reload, history/deep links/reload/legacy migration, manual scroll state, checkbox/drawers/themes, no viewport overflow');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
