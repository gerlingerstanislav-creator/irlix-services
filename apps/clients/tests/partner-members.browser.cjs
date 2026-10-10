// Local preview only; synthetic API/auth fixtures, no production session.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
 const browser = await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try {
  const page=await browser.newPage({viewport:{width:1366,height:900}}), errors=[], saved=[];
  page.on('pageerror',e=>errors.push(e.message));
  const base=process.env.CLIENTS_PREVIEW || 'http://127.0.0.1:5193';
  const issuer=base+'/keycloak/auth/realms/irlix';
  await page.addInitScript(issuer=>sessionStorage.setItem('irlix.platform.auth.tokens',JSON.stringify({access_token:'e30.'+btoa(JSON.stringify({iss:issuer,exp:Math.floor(Date.now()/1000)+3600,name:'Synthetic Operator'}))+'.synthetic'})),issuer);
  await page.route('**/.well-known/openid-configuration',r=>r.fulfill({json:{issuer,authorization_endpoint:issuer+'/auth',token_endpoint:issuer+'/token'}}));
  await page.route('**/api/**',r=>{
   const path=new URL(r.request().url()).pathname;
   if(path.endsWith('/members') && r.request().method()==='POST'){saved.push(r.request().postDataJSON());return r.fulfill({status:201,json:{data:{member_id:71}}});}
   const data=path.endsWith('/permissions/me')?{platform_admin:true,roles:['platform-admin'],permissions:Object.fromEntries(['clients.view','clients.manage','members.view','members.manage'].map(k=>[k,{allowed:true,scope:'all'}]))}:
    path.endsWith('/clients-directory')?{employees:[{id:1,full_name:'Synthetic Employee',department_name:'Synthetic Department'}],departments:[],actor:{id:1}}:
    path.endsWith('/overview')?{clients:[{id:1,name:'Synthetic Client',projects:[{id:1,is_default:true,members:[{id:2,specialist_name:'Synthetic Existing Member',terms:[{id:2,valid_from:'2000-01-01',valid_to:null}]}]}]}],leads:[],contacts:[],requests:[],reportingPeriods:[]}:
    path.endsWith('/catalog')?{technologies:[{id:1,name:'Synthetic Technology'}]}:{};
   return r.fulfill({json:{data}});
  });
  await page.goto(base+'/clients/');
  await page.getByRole('button',{name:'Развернуть клиента',exact:true}).click();
  await page.getByRole('button',{name:'＋ участник',exact:true}).click();
  await page.getByRole('button',{name:'Партнерский специалист',exact:true}).click();
  const name=page.getByLabel('ФИО партнерского специалиста');await name.fill('Synthetic Partner');
  assert.equal(await page.getByRole('button',{name:'Выберите специалиста',exact:true}).count(),0);
  await page.getByRole('button',{name:'Сотрудник',exact:true}).click();
  assert.equal(await name.count(),0);
  await page.getByRole('button',{name:'Выберите специалиста',exact:true}).click();
  assert.equal(await page.getByRole('option',{name:'Synthetic Employee',exact:true}).count(),1);
  await page.keyboard.press('Escape');
  await page.getByRole('button',{name:'Партнерский специалист',exact:true}).click();
  await name.fill('Synthetic Partner');
  await page.getByRole('button',{name:'Выберите технологию',exact:true}).click();await page.getByRole('option',{name:'Synthetic Technology',exact:true}).click();
  await page.getByRole('button',{name:'Выберите уровень',exact:true}).click();await page.getByRole('option',{name:'Senior',exact:true}).click();
  await page.getByLabel('Ставка, ₽/ч').fill('100');await page.getByLabel('Начало',{exact:true}).fill('2026-10-01');
  await page.getByRole('button',{name:'Сохранить',exact:true}).click();
  await page.waitForFunction(()=>!document.querySelector('input[placeholder="Введите ФИО"]'));
  assert.equal(saved.length,1);assert.equal(saved[0].is_external,true);assert.equal(saved[0].specialist_id,null);assert.equal(saved[0].specialist_name,'Synthetic Partner');
  assert.deepEqual(errors,[]);
  console.log('Partner member browser passed: switching, employee tree, partner name and payload.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
