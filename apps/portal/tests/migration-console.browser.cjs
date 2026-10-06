// Run against a local Portal preview. All endpoints and identities are synthetic fixtures.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--use-gl=angle','--use-angle=swiftshader','--disable-gpu']});
  const page=await browser.newPage({viewport:{width:1440,height:900}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  let polls=0, starts=0, forbidden=false;
  const issuer='http://127.0.0.1:5173/keycloak/auth/realms/irlix';
  await page.addInitScript(({issuer})=>{
    const payload=btoa(JSON.stringify({iss:issuer,exp:Math.floor(Date.now()/1000)+3600,name:'Synthetic Operator'}));
    sessionStorage.setItem('irlix.platform.auth.tokens',JSON.stringify({access_token:`e30.${payload}.synthetic`}));
  },{issuer});
  const profile=()=>({host:`server-poll-${polls}.invalid`,port:5432,database:'synthetic',username:'readonly_test',sslmode:'disable',readonly_acknowledged:true,verified_at:'2026-10-07 00:00:00',credential_status:'ready'});
  await page.route('**/.well-known/openid-configuration',r=>r.fulfill({json:{issuer,authorization_endpoint:issuer+'/auth',token_endpoint:issuer+'/token'}}));
  await page.route('**/api/employees/access/me',r=>r.fulfill({json:{data:{roles:forbidden?[]:['platform-admin']}}}));
  await page.route('**/api/migration/**',async r=>{
    const path=new URL(r.request().url()).pathname;
    if(path==='/api/migration/state') {polls++;return r.fulfill({json:{data:{modules:[{key:'employees',status:'implemented',dependencies:[],connection:profile()},{key:'vacations',status:'implemented',dependencies:['employees'],connection:profile()},{key:'clients',status:'planned',dependencies:['employees'],description:'Synthetic future module'}],recent_runs:[]}}});}
    if(path==='/api/migration/console/state') return r.fulfill({json:{data:{operation:starts?{id:'synthetic-42',status:'queued',phase:'queued',scope:'all',runs:[],services:['employees','vacations'],message:'Ожидает запуска'}:null,history:[],snapshots:{all:[{id:'100',scope:'all',created_at:'2026-10-07T00:00:00Z',restored:false}],employees:[],vacations:[]}}}});
    if(path==='/api/migration/console/start') {starts++;return r.fulfill({json:{data:{operation:{id:'synthetic-42',status:'queued',phase:'queued',scope:'all',runs:[],services:['employees','vacations']}}},status:202});}
    return r.fulfill({json:{data:{}},status:200});
  });
  await page.goto(process.env.PORTAL_PREVIEW || 'http://127.0.0.1:5173/migration/console/');
  await page.getByRole('button',{name:'Перенести все',exact:true}).waitFor();
  await page.getByRole('button',{name:/01 Сотрудники/}).click();
  await page.getByRole('button',{name:'Доступ к БД',exact:true}).click();
  const host=page.getByLabel('Хост',{exact:true});
  await host.fill('my-draft.invalid');
  await host.focus();
  await host.evaluate(input=>{input.setSelectionRange(3,3);window.__draftInput=input;});
  const pollStart=polls;
  await page.waitForFunction(()=>document.querySelector('.mc-live')?.textContent.includes('Обновлено'));
  await new Promise(resolve=>setTimeout(resolve,11000));
  assert.ok(polls>=pollStart+2,'at least two background updates while editing');
  assert.equal(await host.inputValue(),'my-draft.invalid','polling cannot overwrite draft');
  assert.equal(await host.evaluate(input=>input===window.__draftInput),true,'input DOM must be retained');
  assert.equal(await host.evaluate(input=>document.activeElement===input),true,'focus survives polling');
  assert.equal(await host.evaluate(input=>input.selectionStart),3,'caret survives polling');
  await page.screenshot({path:process.env.SCREENSHOT_DIR?`${process.env.SCREENSHOT_DIR}/migration-console-form.png`:'/tmp/migration-console-form.png'});
  await page.getByRole('button',{name:'Закрыть',exact:true}).last().click();
  await page.getByRole('button',{name:/Все сервисы Доступ проверен/}).click();
  await page.getByRole('button',{name:'Перенести все',exact:true}).click();
  await page.getByRole('button',{name:'Создать точку и перенести',exact:true}).click();
  await page.waitForTimeout(150);
  assert.equal(starts,1,'one start request');
  assert.equal(await page.getByRole('button',{name:'Перенести все',exact:true}).isDisabled(),true,'queued operation stays locked');
  await page.setViewportSize({width:390,height:844});
  await page.screenshot({path:process.env.SCREENSHOT_DIR?`${process.env.SCREENSHOT_DIR}/migration-console-mobile.png`:'/tmp/migration-console-mobile.png'});
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no document horizontal overflow on mobile');
  forbidden=true;
  await page.reload();
  await page.getByText('Требуется роль platform-admin',{exact:true}).waitFor();
  assert.equal(await page.locator('#migration-console-root').count(),0,'no unauthorized console');
  assert.deepEqual(errors,[],'no runtime errors');
  console.log('Browser checks passed: polling preserves value, DOM, focus and caret; queue lock, mobile layout, admin access.');
  await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
