// Synthetic browser regression only; no live environment or credentials.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_EXECUTABLE,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    const errors = [];
    const yearRequests = [];
    const currentYear = new Date().getFullYear();
    let allowFunnel = true;
    page.on('pageerror', error => errors.push(error.message));
    const base = process.env.CLIENTS_PREVIEW || 'http://127.0.0.1:5193';
    const issuer = base + '/keycloak/auth/realms/irlix';
    await page.addInitScript(issuer => {
      sessionStorage.setItem('irlix.platform.auth.tokens',
        JSON.stringify({access_token:'e30.'+btoa(JSON.stringify({
          iss: issuer, exp: Math.floor(Date.now()/1000)+3600, name:'Synthetic Router QA'
        }))+'.synthetic'}));
      localStorage.setItem('irlix.clients.view.reports.mode', 'gantt');
    }, issuer);
    await page.route('**/.well-known/openid-configuration', route => route.fulfill({
      json: { issuer, authorization_endpoint: issuer+'/auth', token_endpoint:issuer+'/token' }
    }));
    await page.route('**/api/**', route => {
      const url = new URL(route.request().url());
      const path = url.pathname;
      if (path.endsWith('/overview') && url.searchParams.get('section') === 'reports') {
        const year = Number(url.searchParams.get('year'));
        const pageNumber = Number(url.searchParams.get('page') || 1);
        yearRequests.push({year,page:pageNumber});
        const firstPage = Array.from({length:80}, (_,i)=>({
          id:i+1,client_id:1,period_start:`${currentYear}-01-01`,period_end:`${currentYear}-01-31`,status:'Счет оплачен'
        }));
        const currentLast = [{id:81,client_id:1,period_start:`${currentYear}-05-01`,period_end:`${currentYear}-05-31`,status:'Акт согласован'}];
        const previous = [{id:82,client_id:1,period_start:`${currentYear-1}-02-01`,period_end:`${currentYear-1}-02-28`,status:'Счет оплачен'}];
        const rows = year === currentYear ? (pageNumber === 1 ? firstPage : currentLast) : year === currentYear - 1 ? previous : [];
        return route.fulfill({json:{data:{reportingPeriods:rows},meta:{page:pageNumber,has_more:year===currentYear && pageNumber===1,total:year===currentYear?81:rows.length}}});
      }
      const permissions = Object.fromEntries(['clients.view','requests.view','positions.view',
        'attempts.view','reports.view','permissions.view'].map(key => [key,{allowed:true,scope:'all'}]));
      permissions['attempts.analytics.view'] = {allowed:allowFunnel,scope:'all'};
      const data = path.endsWith('/employees/access/me') ? {services:{clients:true}} :
        path.endsWith('/permissions/me') ? {permissions,roles:['account-manager'],platform_admin:false} :
        path.endsWith('/clients-directory') ? {employees:[],departments:[],actor:{id:1}} :
        path.endsWith('/overview') ? {clients:[{id:1,name:'Router QA',client_members:[{id:1,employee_id:1,specialist_name:'Active Specialist',terms:[{id:1,valid_from:'2000-01-01',valid_to:null}],projects:[]}],projects:[]}],leads:[],contacts:[],requests:[],reportingPeriods:[]} :
        path.endsWith('/attempt-funnel') ? {stages:[],filters:{},totals:{}} : {};
      return route.fulfill({json:{data}});
    });

    await page.goto(base+'/clients/?origin=legacy#saved');
    await page.waitForURL(url => url.pathname === '/clients/clients/' && url.searchParams.get('origin') === 'legacy' && url.hash === '#saved');
    await page.locator('.client-row').waitFor();
    await page.goto(base+'/clients/reports/?month=2026-10#history');
    await page.waitForURL(url => url.pathname === '/clients/reporting-periods/' && url.searchParams.get('month') === '2026-10' && url.hash === '#history');
    await page.locator('.reports-gantt .gantt-period').first().waitFor();
    await page.waitForFunction(() => document.querySelectorAll('.reports-gantt .gantt-period').length === 81);
    assert.deepEqual(yearRequests.slice(0,2),[{year:currentYear,page:1},{year:currentYear,page:2}], 'Gantt fetches every page without scrolling');
    await page.getByRole('button',{name:'Предыдущий год',exact:true}).click();
    await page.waitForFunction(expected => document.querySelector('.ui-period-picker__value')?.textContent?.includes(String(expected))
      && document.querySelectorAll('.reports-gantt .gantt-period').length === 1, currentYear - 1);
    assert.ok(yearRequests.some(x=>x.year===currentYear-1 && x.page===1),'Changing the year requests its periods automatically');
    await page.goto(base+'/clients/attempt-funnel/?month=2026-10#funnel');
    await page.locator('.attempt-funnel-page').waitFor();
    assert.equal(new URL(page.url()).pathname, '/clients/attempt-funnel/');
    await page.reload();
    await page.locator('.attempt-funnel-page').waitFor();
    const navigation = page.getByRole('navigation', {name:'Навигация сервиса клиентов'});
    await page.evaluate(() => { window.__routerDocument = 'kept'; });
    await navigation.getByRole('button', {name:'Попытки подключения', exact:true}).click();
    await page.waitForURL(url => url.pathname === '/clients/attempts/');
    assert.equal(await page.evaluate(() => window.__routerDocument),'kept','SPA navigation must not reload');
    await page.goBack();
    await page.waitForURL(url => url.pathname === '/clients/attempt-funnel/');
    await page.locator('.attempt-funnel-page').waitFor();
    await page.goForward();
    await page.waitForURL(url => url.pathname === '/clients/attempts/');
    await page.goto(base+'/clients/definitely-unknown/');
    await page.getByText('404', {exact:true}).first().waitFor();
    assert.equal(await page.getByRole('link',{name:'К клиентам'}).getAttribute('href'),'/clients/clients/');

    allowFunnel = false;
    await page.goto(base+'/clients/attempt-funnel/');
    await page.getByText('403', {exact:true}).first().waitFor();
    assert.equal(await page.locator('.attempt-funnel-page').count(),0,'forbidden analytics cannot render the funnel');
    assert.deepEqual(errors,[]);
    console.log('Clients router PASS: canonical/legacy, query/hash, reload, history, 404 and funnel-specific 403.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
