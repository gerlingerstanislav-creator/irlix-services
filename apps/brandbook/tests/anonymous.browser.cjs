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
    assert.equal(await page.getByText('Два цифровых варианта · v1.0', { exact:true }).count(),1);
    assert.equal(await page.locator('.brandbook-logo-source img').count(),2,'both approved digital previews visible');
    await page.goto(base + '/brandbook/colors/');
    assert.equal(await page.locator('.brandbook-swatch').count(),7,'seven independently labeled digital swatches');
    await page.goto(base + '/brandbook/typography/');
    const fontLoaded = await page.evaluate(async () => {
      await document.fonts.load('700 24px "Onest Variable"','Инженеры и задачи');
      return document.fonts.check('700 24px "Onest Variable"','Инженеры и задачи');
    });
    assert.equal(fontLoaded,true,'self-hosted Onest loads and covers Cyrillic');
    assert.equal(await page.locator('.brandbook-type-preview').count(),1);
    // Search spans the public catalog; attempts to fetch internal source data are forbidden.
    await page.goto(base + '/brandbook/identity/');
    const search = page.locator('#brandbook-search');
    await search.fill('Финтех');
    assert.ok(await page.locator('.brandbook-card').count() > 0, 'public search works');
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(base).origin });
    await page.getByRole('button', { name: 'Копировать с указанием источника' }).first().click();
    const copied = await page.evaluate(() => navigator.clipboard.readText());
    assert.match(copied, /Источник: /, 'copied excerpt includes attribution');
    assert.match(copied, /Проверено: /, 'copied excerpt includes verification date');
    assert.match(copied, /не утверждённый бренд-стандарт/, 'copied excerpt preserves source-vs-approval status');
    await page.goto(base + '/brandbook/colors/');
    await page.getByRole('button',{name:'Копировать со статусом согласования'}).click();
    const approvedCopied=await page.evaluate(() => navigator.clipboard.readText());
    assert.match(approvedCopied,/digital-основа v1.0/);
    assert.match(approvedCopied,/не подтверждает права на мастер-файлы/);
    await page.goto(base + '/brandbook/identity/');
    await search.fill('Финтех');
    assert.equal(await page.getByRole('status').filter({ hasText: 'Скопировано с источником' }).count(), 1);
    await page.getByRole('button', { name: 'Закрыть уведомление' }).click();
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
