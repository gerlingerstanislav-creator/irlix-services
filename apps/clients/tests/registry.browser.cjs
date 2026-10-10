// Local preview only; synthetic API/auth fixtures, no production session.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
 const browser = await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try {
  const page=await browser.newPage({viewport:{width:1366,height:900}}), errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  const base=process.env.CLIENTS_PREVIEW || 'http://127.0.0.1:5193';
  const issuer=base+'/keycloak/auth/realms/irlix';
  await page.addInitScript(issuer=>sessionStorage.setItem('irlix.platform.auth.tokens',JSON.stringify({access_token:'e30.'+btoa(JSON.stringify({iss:issuer,exp:Math.floor(Date.now()/1000)+3600,name:'Synthetic Operator'}))+'.synthetic'})),issuer);
  await page.route('**/.well-known/openid-configuration',r=>r.fulfill({json:{issuer,authorization_endpoint:issuer+'/auth',token_endpoint:issuer+'/token'}}));
  await page.route('**/api/**',r=>{
   const path=new URL(r.request().url()).pathname;
   const data=path.endsWith('/permissions/me')?{platform_admin:true,roles:['platform-admin'],permissions:Object.fromEntries(['clients.view','clients.manage','members.view','members.manage'].map(k=>[k,{allowed:true,scope:'all'}]))}:
    path.endsWith('/clients-directory')?{employees:[{id:1,full_name:'Synthetic Employee',department_name:'Synthetic Department'}],departments:[],actor:{id:1}}:
    path.endsWith('/overview')?{clients:Array.from({length:81},(_,i)=>({id:i+1,name:'Synthetic Client '+String(i+1).padStart(3,'0'),projects:[{id:i+1,members:[{id:i+1,specialist_name:'Synthetic Member '+i,terms:[{id:i+1,valid_from:i===80?'2099-01-01':'2000-01-01',valid_to:null}]}]}]})),leads:[],contacts:[],requests:[],reportingPeriods:[]}:
    path.endsWith('/catalog')?{technologies:[{id:1,name:'Synthetic Technology'}]}:{};
   return r.fulfill({json:{data}});
  });
  await page.goto(base+'/clients/');
  await page.waitForSelector('.client-row');
  assert.equal(await page.locator('.client-row').count(),80,'future rates are inactive by default');
  assert.equal(await page.locator('.client-members').count(),0,'all clients start collapsed');
  const search=page.getByPlaceholder('Поиск по клиентам');
  await search.fill('001');
  await page.getByRole('button',{name:'Развернуть всех видимых клиентов',exact:true}).click();
  assert.equal(await page.locator('.client-members').count(),1);
  await search.fill('');
  assert.equal(await page.locator('.client-members').count(),1,'hidden clients are untouched');
  await page.getByRole('button',{name:'Развернуть всех видимых клиентов',exact:true}).click();
  assert.equal(await page.locator('.client-members').count(),80);
  await search.fill('001');
  await page.getByRole('button',{name:'Свернуть всех видимых клиентов',exact:true}).click();
  await search.fill('');
  assert.equal(await page.locator('.client-members').count(),79,'collapse also only affects visible clients');
  for(const width of [1366,1024,390]) {
   await page.setViewportSize({width,height:700});
   const before=await page.evaluate(()=>{
    const table=document.querySelector('.client-table'),head=document.querySelector('.client-head'),row=document.querySelector('.client-row');
    const headerToggle=head.querySelector('button').getBoundingClientRect(),rowToggle=row.querySelector('.ui-tree-toggle').getBoundingClientRect();
    const cells=[...head.children].map(e=>e.getBoundingClientRect()).filter(e=>e.width>0);
    const label=head.querySelector('.client-name-cell>span:last-child').getBoundingClientRect(),name=row.querySelector('strong').getBoundingClientRect();
    return {scroll:table.scrollHeight>table.clientHeight,height:table.clientHeight,head:head.getBoundingClientRect().y,dx:headerToggle.x-rowToggle.x,labelDx:label.x-name.x,borderDelta:Math.max(...cells.map(e=>e.bottom))-Math.min(...cells.map(e=>e.bottom))};
   });
   assert.equal(before.scroll,true,'table body scrolls at '+width);
   assert.ok(before.height>0);assert.ok(before.borderDelta<1,'all header cell borders align');assert.ok(Math.abs(before.dx)<1);assert.ok(Math.abs(before.labelDx)<1);
   await page.locator('.client-table').evaluate(e=>e.scrollTop=300);
   assert.ok(Math.abs(await page.locator('.client-head').evaluate(e=>e.getBoundingClientRect().y)-before.head)<1,'header stays pinned');
   await page.locator('.client-table').evaluate(e=>e.scrollTop=0);
  }
  await search.fill('missing');
  assert.equal(await page.getByRole('button',{name:'Развернуть всех видимых клиентов',exact:true}).isDisabled(),true);
  await page.reload();await page.waitForSelector('.client-row');
  assert.equal(await page.locator('.client-members').count(),0);
  assert.deepEqual(errors,[]);
  console.log('Clients registry browser passed: collapsed defaults, active rates, filtered bulk toggles, alignment and pinned header at 1366/1024/390.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
