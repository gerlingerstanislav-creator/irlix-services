// Local preview only; synthetic API/auth fixtures, no production session.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
 const browser = await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try {
  const page=await browser.newPage({viewport:{width:1366,height:900}}), errors=[], saved=[], partnerWrites=[], apiResponses=[];
  const partnerState={id:3,name:'Synthetic Agency',description:'Synthetic agency profile',active:true,specialists:[{id:9,partner_id:3,full_name:'Synthetic Partner',active:true,comment:'Synthetic member profile'}]};
  let membershipAccess='full';
  page.on('pageerror',e=>errors.push(e.message));
  page.on('response',r=>{if(new URL(r.url()).pathname.startsWith('/api/'))apiResponses.push({path:new URL(r.url()).pathname,method:r.request().method(),status:r.status()});});
  page.on('requestfailed',r=>{if(new URL(r.url()).pathname.startsWith('/api/'))apiResponses.push({path:new URL(r.url()).pathname,method:r.method(),error:r.failure()?.errorText||'request failed'});});
  const base=process.env.CLIENTS_PREVIEW || 'http://127.0.0.1:5193';
  const issuer=base+'/keycloak/auth/realms/irlix';
  await page.addInitScript(issuer=>sessionStorage.setItem('irlix.platform.auth.tokens',JSON.stringify({access_token:'e30.'+btoa(JSON.stringify({iss:issuer,exp:Math.floor(Date.now()/1000)+3600,name:'Synthetic Operator'}))+'.synthetic'})),issuer);
  await page.route('**/.well-known/openid-configuration',r=>r.fulfill({json:{issuer,authorization_endpoint:issuer+'/auth',token_endpoint:issuer+'/token'}}));
  await page.route('**/api/**',r=>{
   const path=new URL(r.request().url()).pathname;
   if(path.endsWith('/client-members') && r.request().method()==='POST'){saved.push(r.request().postDataJSON());return r.fulfill({status:201,json:{data:{id:72,client_id:1,partner_specialist_id:9,employee_id:null,specialist_name:'Synthetic Partner',terms:[],projects:[],feedbacks:[]}}});}
   if(path.endsWith('/client-members')) return r.fulfill({json:{data:saved.some(x=>x.partner_specialist_id===9)?[{id:72,client_id:1,partner_specialist_id:9,employee_id:null,specialist_name:'Synthetic Partner',terms:[],projects:[],feedbacks:[{id:99,text:'Synthetic feedback with metadata',project_id:null,occurred_on:'2026-10-12',rating:'Strong',legacy_interview_id:31},{id:100,text:'Legacy feedback without metadata',project_id:null,occurred_on:null,rating:null,legacy_interview_id:null}]}]:[]}});
   if(path==='/api/clients/partners/3'&&r.request().method()==='PATCH'){const payload=r.request().postDataJSON();partnerWrites.push({path,payload});Object.assign(partnerState,payload);return r.fulfill({json:{data:partnerState}});}
   if(path==='/api/clients/partner-specialists/9'&&r.request().method()==='PATCH'){const payload=r.request().postDataJSON();partnerWrites.push({path,payload});Object.assign(partnerState.specialists[0],payload);return r.fulfill({json:{data:partnerState.specialists[0]}});}
   if(path==='/api/clients/partners') return r.fulfill({json:{data:{partners:[partnerState],unassigned:[]}}});
   if(path.endsWith('/members') && r.request().method()==='POST'){saved.push(r.request().postDataJSON());return r.fulfill({status:201,json:{data:{member_id:71}}});}
   const data=path.endsWith('/employees/access/me')?{services:{clients:true},roles:['platform-admin']}:
    path.endsWith('/permissions/me')?{platform_admin:false,roles:[],permissions:Object.fromEntries(['clients.view','clients.manage','members.view','members.manage'].map(k=>[k,{allowed:membershipAccess==='full'||(k==='clients.view')||(membershipAccess==='members-manage'&&['members.view','members.manage'].includes(k))||(membershipAccess==='members-read'&&k==='members.view'),scope:'all'}]))}:
    path.endsWith('/clients-directory')?{employees:[{id:1,full_name:'Synthetic Employee',department_name:'Synthetic Department'}],departments:[],actor:{id:1}}:
    // Canonical endpoint intentionally omits `active`; rows are already scoped
    // to active specialists and active organizations by the server.
    path.endsWith('/partner-specialists/catalog')?[{id:9,partner_id:3,partner_name:'Synthetic Agency',full_name:'Synthetic Partner'}]:
    path.endsWith('/overview')?{clients:[{id:1,name:'Synthetic Client',projects:[{id:1,is_default:true,members:[{id:2,specialist_name:'Synthetic Existing Member',terms:[{id:2,valid_from:'2000-01-01',valid_to:null}]}]}],client_members:[{id:2,employee_id:1,specialist_name:'Synthetic Existing Member',terms:[{id:2,valid_from:'2000-01-01',valid_to:null}],projects:[{id:1,project_id:1,project_name:'Synthetic Project',valid_from:'2000-01-01',valid_to:null}]}]}],leads:[],contacts:[],requests:[],reportingPeriods:[]}:
    path.endsWith('/catalog')?{technologies:[{id:1,name:'Synthetic Technology'}]}:{};
   return r.fulfill({json:{data}});
  });
  await page.goto(base+'/clients/');
  await page.getByRole('button',{name:'Развернуть клиента',exact:true}).click();
  await page.getByRole('button',{name:'＋ участник',exact:true}).click();
  await page.getByRole('button',{name:'Партнерский специалист',exact:true}).click();
  const partnerSelect=page.getByRole('button',{name:'Выберите из справочника',exact:true});
  await partnerSelect.click();
  await page.getByRole('option',{name:'Synthetic Agency — Synthetic Partner',exact:true}).click();
  assert.equal(await page.getByRole('button',{name:'Выберите специалиста',exact:true}).count(),0);
  await page.getByRole('button',{name:'Сотрудник',exact:true}).click();
  assert.equal(await partnerSelect.count(),0);
  await page.getByRole('button',{name:'Выберите специалиста',exact:true}).click();
  assert.equal(await page.getByRole('option',{name:'Synthetic Employee',exact:true}).count(),1);
  await page.keyboard.press('Escape');
  await page.getByRole('button',{name:'Партнерский специалист',exact:true}).click();
  await page.getByRole('button',{name:'Выберите из справочника',exact:true}).click();
  await page.getByRole('option',{name:'Synthetic Agency — Synthetic Partner',exact:true}).click();
  await page.getByRole('button',{name:'Выберите технологию',exact:true}).click();await page.getByRole('option',{name:'Synthetic Technology',exact:true}).click();
  await page.getByRole('button',{name:'Выберите уровень',exact:true}).click();await page.getByRole('option',{name:'Senior',exact:true}).click();
  await page.getByLabel('Ставка, ₽/ч').fill('100');await page.getByLabel('Начало',{exact:true}).fill('2026-10-01');
  await page.getByRole('button',{name:'Сохранить',exact:true}).click();
  await page.waitForFunction(()=>!document.querySelector('.entity-form'));
  assert.equal(saved.length,1);assert.equal(saved[0].is_external,true);assert.equal(saved[0].specialist_id,null);assert.equal(saved[0].specialist_name,'Synthetic Partner');assert.equal(saved[0].partner_specialist_id,9);
  await page.keyboard.press('Escape');
  // Wait for the preceding save-driven overview refresh and modal teardown.
  await page.waitForFunction(() => !document.querySelector('.entity-form') && !document.querySelector('.irlix-app-topbar__loading'));
  const memberNav = page.getByRole('navigation',{name:'Навигация сервиса клиентов'}).getByRole('button',{name:'Подключения',exact:true});
  await memberNav.click();
  await page.locator('.cm-registry').waitFor({timeout:8000});
  try { await page.getByRole('button',{name:'＋ Подключить специалиста',exact:true}).click({timeout:8000}); }
  catch(error) { console.error('Client member page diagnostic',{url:page.url(),body:(await page.locator('body').innerText()).slice(0,3500),errors}); throw error; }
  await page.getByLabel('Тип специалиста').selectOption('partner');
  await page.getByRole('button',{name:'Выберите специалиста',exact:true}).click();
  await page.getByRole('option',{name:'Synthetic Agency — Synthetic Partner',exact:true}).click();
  await page.getByRole('button',{name:'Создать',exact:true}).click();
  await page.waitForFunction(()=>!document.querySelector('.cm-registry')?.textContent.includes('Новое подключение к клиенту'));
  assert.equal(saved.length,2);assert.equal(saved[1].partner_specialist_id,9);assert.equal(saved[1].employee_id,null);
  // Creating a connection intentionally opens its detail drawer. Close it
  // before navigating away; the modal backdrop must block sidebar clicks.
  // Concurrent refresh can stack detail drawers; close every layer before navigating.
  for (let attempt=0; attempt<6; attempt++) {
    if (!await page.locator('.irlix-drawer-backdrop').count()) break;
    await page.evaluate(() => [...document.querySelectorAll('.irlix-drawer-layer .irlix-drawer-close')].at(-1)?.click());
    await page.waitForTimeout(80);
  }
  assert.equal(await page.locator('.irlix-drawer-backdrop').count(),0,'All drawer layers must be closed before navigating');
  await page.getByRole('navigation',{name:'Навигация сервиса клиентов'}).getByRole('button',{name:'Партнёры',exact:true}).click();
  // The accessible button name includes the displayed specialist count, e.g. 'Synthetic Agency (1)'.
  const agencyRow=page.locator('.partners-registry .partner-parent .partner-name').filter({hasText:'Synthetic Agency'});
  try { await agencyRow.waitFor({timeout:10000}); }
  catch(error) {
   const screenshotDir=process.env.QA_BROWSER_REPORT || '/tmp/qa-browser-report';
   require('node:fs').mkdirSync(screenshotDir,{recursive:true});
   await page.screenshot({path:screenshotDir+'/clients-partner-row-timeout.png',fullPage:true}).catch(()=>{});
   console.error('Partners row diagnostic',{url:page.url(),body:(await page.locator('body').innerText()).slice(0,3500),apiResponses,errors});
   throw error;
  }
  assert.ok(apiResponses.some(r=>r.path==='/api/clients/partners'&&r.method==='GET'&&r.status===200),'Partners registry loads its list on mount');
  // Clear the sidebar's hover labels before targeting controls along the
  // workspace's left edge; the sidebar labels overlay that area while hovered.
  await page.mouse.move(800,500);
  await page.waitForFunction(()=>{
   const labels=document.querySelector('.irlix-app-sidebar .nav-label-viewport');
   return labels&&getComputedStyle(labels).pointerEvents==='none'&&getComputedStyle(labels).visibility==='hidden';
  });
  await page.getByRole('button',{name:'Развернуть',exact:true}).click();
  await page.getByRole('button',{name:'Synthetic Partner',exact:true}).waitFor();

  assert.equal(await page.locator('.partners-registry .partner-pencil').count(),0,'Tree-row pencils were intentionally removed');
  const screenshotDir=process.env.QA_BROWSER_REPORT || '/tmp/qa-browser-report';
  require('node:fs').mkdirSync(screenshotDir,{recursive:true});
  await page.screenshot({path:screenshotDir+'/clients-partner-tree.png',fullPage:true});
  await agencyRow.click();
  assert.equal(await page.getByRole('button',{name:'Редактировать: Наименование партнёра',exact:true}).count(),1);
  await page.getByRole('button',{name:'Редактировать: Наименование партнёра',exact:true}).click();
  await page.getByLabel('Наименование партнёра',{exact:true}).fill('Synthetic Agency Updated');
  let patched=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/clients/partners/3'&&r.request().method()==='PATCH');
  await page.getByRole('button',{name:'Сохранить поле',exact:true}).click();
  assert.equal((await patched).status(),200);
  await page.getByRole('button',{name:'Редактировать: Описание / комментарий',exact:true}).click();
  await page.getByLabel('Описание / комментарий',{exact:true}).fill('Updated synthetic agency profile');
  patched=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/clients/partners/3'&&r.request().method()==='PATCH');
  await page.getByRole('button',{name:'Сохранить поле',exact:true}).click();
  assert.equal((await patched).status(),200);
  assert.deepEqual(partnerWrites.slice(0,2),[
    {path:'/api/clients/partners/3',payload:{name:'Synthetic Agency Updated'}},
    {path:'/api/clients/partners/3',payload:{description:'Updated synthetic agency profile'}}
  ]);
  await page.locator('.irlix-drawer-backdrop').last().click();
  await page.locator('.irlix-drawer-backdrop').waitFor({state:'detached'});
  await page.locator('.partners-registry .partner-parent .partner-name').filter({hasText:'Synthetic Agency Updated'}).waitFor();
  await page.getByRole('button',{name:'Synthetic Partner',exact:true}).click();
  await page.getByRole('button',{name:'Редактировать: ФИО',exact:true}).click();
  await page.getByLabel('ФИО',{exact:true}).fill('Synthetic Partner Updated');
  patched=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/clients/partner-specialists/9'&&r.request().method()==='PATCH');
  await page.getByRole('button',{name:'Сохранить поле',exact:true}).click();
  assert.equal((await patched).status(),200);
  await page.getByRole('button',{name:'Редактировать: Комментарий',exact:true}).click();
  await page.getByLabel('Комментарий',{exact:true}).fill('Updated synthetic member profile');
  patched=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/clients/partner-specialists/9'&&r.request().method()==='PATCH');
  await page.getByRole('button',{name:'Сохранить поле',exact:true}).click();
  assert.equal((await patched).status(),200);
  assert.deepEqual(partnerWrites.slice(2),[
    {path:'/api/clients/partner-specialists/9',payload:{full_name:'Synthetic Partner Updated'}},
    {path:'/api/clients/partner-specialists/9',payload:{comment:'Updated synthetic member profile'}}
  ]);
  await page.locator('.irlix-drawer-backdrop').last().click();
  await page.locator('.irlix-drawer-backdrop').waitFor({state:'detached'});
  await page.reload();
  await page.locator('.partners-registry .partner-parent .partner-name').filter({hasText:'Synthetic Agency Updated'}).waitFor();
  await page.mouse.move(800,500);
  await page.waitForFunction(()=>{
   const labels=document.querySelector('.irlix-app-sidebar .nav-label-viewport');
   return labels&&getComputedStyle(labels).pointerEvents==='none'&&getComputedStyle(labels).visibility==='hidden';
  });
  await page.getByRole('button',{name:'Развернуть',exact:true}).click();
  assert.equal(await page.getByRole('button',{name:'Synthetic Partner Updated',exact:true}).count(),1);
  await page.screenshot({path:screenshotDir+'/clients-partner-tree.png',fullPage:true});
  await page.getByRole('button',{name:'Synthetic Partner Updated',exact:true}).click();
  assert.equal(await page.getByRole('button',{name:'Редактировать: Партнёр',exact:true}).count(),1);
  assert.equal(await page.getByRole('button',{name:'Редактировать: ФИО',exact:true}).count(),1);
  await page.screenshot({path:screenshotDir+'/clients-partner-member.png',fullPage:true});

  membershipAccess='members-manage';
  await page.reload();
  await page.getByRole('navigation',{name:'Навигация сервиса клиентов'}).getByRole('button',{name:'Подключения',exact:true}).click();
  await page.getByRole('button',{name:'＋ Подключить специалиста',exact:true}).waitFor();
  membershipAccess='members-read';
  await page.reload();
  await page.getByRole('navigation',{name:'Навигация сервиса клиентов'}).getByRole('button',{name:'Подключения',exact:true}).click();
  const readonlyMember=page.locator('.cm-registry tr[role="button"]').filter({hasText:'Synthetic Partner'});
  await readonlyMember.waitFor();
  assert.equal(await page.getByRole('button',{name:'＋ Подключить специалиста',exact:true}).count(),0);
  const writesBeforeReadOnly= saved.length;
  await readonlyMember.click();
  assert.equal(await page.locator('.cm-card h3 button').count(),0);
  await page.getByText('Дата: 2026-10-12',{exact:false}).waitFor();
  await page.getByText('Оценка: Strong',{exact:false}).waitFor();
  await page.getByText('Legacy feedback without metadata',{exact:false}).waitFor();
  assert.equal(saved.length,writesBeforeReadOnly);
  await page.reload();
  await page.getByRole('navigation',{name:'Навигация сервиса клиентов'}).getByRole('button',{name:'Партнёры',exact:true}).click();
  await page.locator('.partners-registry .partner-parent .partner-name').filter({hasText:'Synthetic Agency Updated'}).waitFor();
  await page.mouse.move(800,500);
  await page.waitForFunction(()=>{
   const labels=document.querySelector('.irlix-app-sidebar .nav-label-viewport');
   return labels&&getComputedStyle(labels).pointerEvents==='none'&&getComputedStyle(labels).visibility==='hidden';
  });
  await page.getByRole('button',{name:'Развернуть',exact:true}).click();
  assert.equal(await page.locator('.partners-registry .partner-pencil').count(),0);
  assert.equal(await page.getByRole('button',{name:'Редактировать специалиста',exact:true}).count(),0);
  const writesBeforePartnerReadOnly=partnerWrites.length;
  await page.getByRole('button',{name:'Synthetic Partner Updated',exact:true}).click();
  assert.equal(await page.getByRole('button',{name:'Редактировать',exact:true}).count(),0);
  assert.equal(await page.getByLabel('ФИО',{exact:true}).isEnabled(),false);
  assert.equal(partnerWrites.length,writesBeforePartnerReadOnly);
  assert.deepEqual(errors,[]);
  console.log('Partner member browser passed: switching, employee tree, catalog identity and payload.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
