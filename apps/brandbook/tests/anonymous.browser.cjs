// Dedicated anonymous brandbook smoke; does not use Keycloak, backend APIs or private data.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');

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
    assert.equal(await page.getByRole('heading',{name:'Инженерная разработка, усиленная ИИ.'}).count(),1);
    assert.equal(await page.locator('.brandbook-ai .brandbook-guide__tile').count(),3,'AI plus engineering/accountability pillars');
    assert.equal(await page.locator('.brandbook-overview__links a').count(),4,'four useful deep links on home');
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
    await page.locator('.brandbook-logo-source img').first().waitFor();
    assert.equal(await page.locator('.brandbook-logo-source img').first().evaluate(img => img.naturalWidth > 0), true, 'site SVG image is rendered');
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
    const licenseLink=page.getByRole('link',{name:/полный текст лицензии/i});
    assert.equal(await licenseLink.count(),1);
    const licenseResponse=await page.request.get(new URL(await licenseLink.getAttribute('href'),base).toString());
    assert.equal(licenseResponse.status(),200,'redistributed Onest license available');
    const licenseText=await licenseResponse.text();
    assert.match(licenseText,/Copyright 2021 The Onest Project Authors/);
    assert.match(licenseText,/SIL OPEN FONT LICENSE Version 1\.1/);


    // Owner-authorized public SVGs must be real files, not 200 HTML fallbacks.
    await page.goto(base + '/brandbook/assets/');
    await page.locator('.brandbook-downloads__item').first().waitFor();
    assert.equal(await page.locator('.brandbook-downloads__item').count(),3);
    const names=['irlix-logo-dark-background.svg','irlix-logo-light-background.svg','irlix-symbol-x.svg'];
    for(const name of names){
      const url=new URL('/brandbook/downloads/'+name,base).toString();
      const response=await page.request.get(url);
      assert.equal(response.status(),200,'download works without sign-in: '+name);
      assert.match(response.headers()['content-type']||'',/image\/svg\+xml/i,'SVG MIME: '+name);
      assert.match(await response.text(),/<svg\b/,'real SVG not SPA fallback: '+name);
    }
    assert.equal(await page.locator('.brandbook-downloads__item img').count(),3);
    assert.equal(await page.locator('.brandbook-downloads__token-links a').count(),2);
    for(const [name,mime] of [['irlix-digital-v1.css',/text\/css/i],['irlix-digital-v1.json',/application\/json/i]]){
      const response=await page.request.get(new URL('/brandbook/downloads/'+name,base).toString());
      assert.equal(response.status(),200,'token download works: '+name);
      assert.match(response.headers()['content-type']||'',mime,'token MIME: '+name);
      assert.ok((await response.text()).includes('irlix-brand') || name.endsWith('.json'));
    }

    const downloadPromise=page.waitForEvent('download');
    await page.locator('.brandbook-downloads__item').first().getByRole('link',{name:'Скачать SVG'}).click();
    const download=await downloadPromise;
    assert.equal(download.suggestedFilename(),names[0],'download offers a meaningful filename');

    // Search spans the public catalog; attempts to fetch internal source data are forbidden.
    // New brand-guide sections expose rules without inventing client achievements.
    for(const [route,heading,count] of [
      ['logo','Как использовать знак',2],
      ['graphics','Принципы цифрового оформления',4],
      ['voice','ИИ + инженерная экспертиза + ответственность',6],
      ['applications','Применение фирменной системы',4],
    ]){
      await page.goto(base+'/brandbook/'+route+'/');
      assert.equal(await page.getByRole('heading',{name:heading,exact:true}).count(),1,'section guide '+route);
      const ruleCount=await page.locator('.brandbook-guide__tile, .brandbook-guide__panel').count();
      assert.equal(ruleCount,count,'expected public usage examples in '+route);
    }
    await page.goto(base+'/brandbook/voice/');
    assert.equal(await page.locator('.brandbook-voice-sample').count(),4,'four usable tone-of-voice examples');
    assert.equal(await page.locator('.brandbook-voice-sample__good').count(),4,'approved editorial examples');
    assert.equal(await page.locator('.brandbook-voice-sample__bad').count(),4,'clearly marked negative examples');
    await page.goto(base+'/brandbook/applications/');
    assert.equal(await page.locator('.brandbook-carrier').count(),3,'three real carrier examples');
    assert.deepEqual(await page.locator('.brandbook-carrier__caption strong').allTextContents(),['Сайт','Презентация','Коммерческое предложение']);
    assert.equal(await page.locator('.brandbook-carrier__frame img').count(),3,'approved logo is used in all carriers');
    assert.equal(await page.locator('.brandbook-carrier__cta').count(),1,'site CTA is an illustrative span, not an inert action');
    await page.goto(base+'/brandbook/typography/');
    assert.equal(await page.getByRole('heading',{name:'Инженерная разработка, усиленная ИИ.'}).count(),1,'typography uses current brand headline');

    // Automated WCAG checks use axe on the actual public app, not a mocked component.
    for(const route of ['identity','logo','colors','typography','graphics','voice','applications','assets']){
      await page.goto(base+'/brandbook/'+route+'/');
      await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
      const axeResults=await page.evaluate(async()=>{
        return await window.axe.run(document.querySelector('#brandbook-main'),{
          runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa']},
        });
      });
      const report=axeResults.violations.map(v=>v.id+': '+v.nodes.map(n=>n.target.join(' ')).join(', '));
      assert.deepEqual(report,[],'WCAG automated audit '+route);
    }
    await page.goto(base + '/brandbook/voice/');
    const marketingCard=page.locator('.brandbook-card').filter({hasText:'ИИ усиливает инженерную экспертизу'});
    assert.equal(await marketingCard.count(),1,'marketing guidance visible');
    assert.match(await marketingCard.innerText(),/Согласовано в проекте/);
    const aiPending=page.locator('.brandbook-card').filter({hasText:'Доказательства внедрения ИИ'});
    assert.equal(await aiPending.count(),1,'missing proof is not represented as completed');
    assert.match(await aiPending.innerText(),/Ожидает утверждения/);
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
    assert.equal(await page.getByRole('status').filter({ hasText: 'Скопировано с источником' }).count(), 1);
    await page.getByRole('button', { name: 'Закрыть уведомление' }).click();
    await page.goto(base + '/brandbook/colors/');
    await page.getByRole('button',{name:'Копировать со статусом согласования'}).click();
    const approvedCopied=await page.evaluate(() => navigator.clipboard.readText());
    assert.match(approvedCopied,/digital-основа v1.0/);
    assert.match(approvedCopied,/Разрешение на SVG-логотип для публичного скачивания/);
    await page.getByRole('button',{name:'Закрыть уведомление'}).click();
    await page.goto(base + '/brandbook/identity/');
    await search.fill('Финтех');
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
    // Retain real responsive evidence for design review in the PR CI artifact.
    const qaDir=path.resolve(__dirname,'../qa-screenshots');
    fs.mkdirSync(qaDir,{recursive:true});
    for(const [route,width,filename] of [
      ['identity',1440,'overview-1440.png'],
      ['identity',390,'overview-390.png'],
      ['assets',1440,'library-1440.png'],
      ['assets',390,'library-390.png'],
      ['logo',1440,'logo-1440.png'],
      ['logo',390,'logo-390.png'],
      ['voice',390,'positioning-390.png'],
      ['voice',1440,'positioning-1440.png'],
      ['graphics',1440,'graphics-1440.png'],
      ['applications',1440,'carriers-1440.png'],
      ['applications',390,'carriers-390.png'],
    ]){
      await page.setViewportSize({width,height:850});
      await page.goto(base+'/brandbook/'+route+'/',{waitUntil:'networkidle'});
      const metrics=await page.evaluate(()=>({width:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth}));
      assert.ok(metrics.scrollWidth<=metrics.width+1,'screenshot layout overflow '+route+' '+width);
      await page.screenshot({path:path.join(qaDir,filename),fullPage:true});
    }
    assert.deepEqual(pageErrors, [], 'no runtime script errors');
    assert.deepEqual(protectedRequests, [], 'no anonymous requests to API/OIDC');
    console.log('Brandbook smoke PASS: nine deep links, anonymous no-auth, noindex, keyboard skip, source copy labelling, search, empty state, 4 responsive sizes');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
