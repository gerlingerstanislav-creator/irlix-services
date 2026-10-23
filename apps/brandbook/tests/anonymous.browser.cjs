// Dedicated anonymous brandbook smoke; does not use Keycloak, backend APIs or private data.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const base = process.env.BRANDBOOK_PREVIEW || 'http://127.0.0.1:5198';
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const pageErrors = [];
    const protectedRequests = [];
    page.on('pageerror', e => pageErrors.push(e.message));
    page.on('request', request => {
      const url = new URL(request.url());
      if (/\/api\/|keycloak|openid|oauth|authorize/i.test(url.pathname)) protectedRequests.push(request.url());
    });
    const pages = [
      ['identity', 'Компания'],
      ['logo', 'Логотип'],
      ['colors', 'Цвета'],
      ['typography', 'Типографика'],
      ['graphics', 'Графический стиль'],
      ['voice', 'Коммуникации'],
      ['applications', 'Применение'],
      ['assets', 'Библиотека'],
      ['changelog', 'Версии'],
    ];
    await page.goto(base + '/brandbook/', { waitUntil: 'domcontentloaded' });
    await page.waitForURL('**/brandbook/identity/');
    assert.equal(await page.locator('main.brandbook-page h1').textContent(), 'Компания');
    assert.match(await page.locator('meta[name="robots"]').getAttribute('content'), /noindex/, 'research catalogue is not search-indexable');
    const skipLink = page.getByRole('link', { name: 'К основному содержимому' });
    await skipLink.focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => location.hash === '#brandbook-main');
    assert.equal(await page.locator('#brandbook-main').count(), 1, 'keyboard skip goes to main landmark');
    assert.equal(await page.getByRole('button', { name: 'Открыть аккаунт', exact: true }).count(), 0);
    for (const [id, label] of pages) {
      await page.goto(base + '/brandbook/' + id + '/', { waitUntil: 'domcontentloaded' });
      await page.locator('main.brandbook-page h1').waitFor();
      assert.equal(await page.locator('main.brandbook-page h1').textContent(), label, id);
      assert.ok(await page.locator('.brandbook-disclaimer').count(), 'visible provisional status, ' + id);
    }
    await page.goto(base + '/brandbook/logo/');
    await page.locator('.brandbook-logo-source img').waitFor();
    assert.equal(await page.locator('.brandbook-logo-source img').evaluate(img => img.naturalWidth > 0), true, 'site SVG image is rendered');
    assert.equal(await page.getByText('SVG с официального сайта', { exact:true }).count(),1);
    // Search spans the public catalog; attempts to fetch internal source data are forbidden.
    await page.goto(base + '/brandbook/identity/');
    const search = page.locator('#brandbook-search');
    await search.fill('Финтех');
    assert.ok(await page.locator('.brandbook-card').count() > 0, 'public search works');
    assert.ok(await page.getByRole('button', { name: 'Копировать описание источника' }).count() > 0, 'source copying does not imply brand approval');
    await search.fill('unmatched--brandbook--test--query');
    await page.locator('.brandbook-empty').waitFor();
    await search.fill('');
    for (const width of [320, 375, 768, 1280]) {
      await page.setViewportSize({ width, height: 850 });
      const measures = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));
      assert.ok(measures.scrollWidth <= measures.clientWidth + 1, 'no horizontal overflow: ' + width);
    }
    assert.deepEqual(pageErrors, [], 'no runtime script errors');
    assert.deepEqual(protectedRequests, [], 'no anonymous requests to API/OIDC');
    console.log('Brandbook smoke PASS: nine deep links, anonymous no-auth, noindex, keyboard skip, source copy labelling, search, empty state, 4 responsive sizes');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
