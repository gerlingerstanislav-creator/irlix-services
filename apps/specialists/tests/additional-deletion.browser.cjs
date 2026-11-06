// Synthetic Chromium regression: no real specialists, auth or production APIs.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({
    headless: true, executablePath: process.env.CHROMIUM_EXECUTABLE,
    args: ['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'],
  });
  try {
    const page = await browser.newPage({viewport:{width:1366,height:770}});
    const errors = [];
    page.on('pageerror',e=>errors.push(e.message));
    const base = process.env.SPECIALISTS_PREVIEW || 'http://127.0.0.1:5197';
    const issuer = base+'/keycloak/auth/realms/irlix';
    let catalog = [
      {id:1,name:'Legacy Demo'}, {id:2,name:'Replacement Demo'},
      {id:3,name:'Other Demo'}, {id:4,name:'Archived Demo'},
    ];
    let canManage = true;
    let failOnce = true;
    let failRenameOnce = true;
    const deletionRequests = [];
    const renameRequests = [];
    await page.addInitScript(iss=>sessionStorage.setItem('irlix.platform.auth.tokens',JSON.stringify({
      access_token:'e30.'+btoa(JSON.stringify({iss,exp:Math.floor(Date.now()/1000)+3600,name:'Synthetic Catalog User'}))+'.synthetic',
    })),issuer);
    await page.route('**/.well-known/openid-configuration',route=>route.fulfill({
      json:{issuer,authorization_endpoint:issuer+'/auth',token_endpoint:issuer+'/token'},
    }));
    await page.route('**/api/**',route=>{
      const {pathname} = new URL(route.request().url());
      const method=route.request().method();
      const deletedId=Number(pathname.match(/\/specialists\/catalog\/additional-technologies\/(\d+)$/)?.[1]);
      if(method==='PUT' && deletedId){
        const payload=route.request().postDataJSON();
        renameRequests.push({id:deletedId,payload});
        if(!canManage)return route.fulfill({status:403,json:{message:'Недостаточно прав'}});
        if(failRenameOnce){
          failRenameOnce=false;
          return route.fulfill({status:422,json:{message:'Это название уже используется'}});
        }
        const found=catalog.find(t=>t.id===deletedId);
        if(!found)return route.fulfill({status:404,json:{message:'Технология не найдена'}});
        found.name=payload.name;
        return route.fulfill({json:{data:{id:deletedId,name:found.name,affected_specialists:3,changed:true}}});
      }
      if(method==='DELETE' && deletedId){
        const payload=route.request().postDataJSON();
        deletionRequests.push({id:deletedId,payload});
        if(!canManage)return route.fulfill({status:403,json:{message:'Нет разрешения на управление каталогом'}});
        if(failOnce){failOnce=false;return route.fulfill({status:409,json:{message:'Конфликт назначений: выберите другую технологию'}});}
        catalog=catalog.filter(t=>t.id!==deletedId);
        return route.fulfill({json:{data:{deleted:true,id:deletedId,replacement_id:payload.replacement_id,affected_specialists:3}}});
      }
      let data;
      if(pathname.endsWith('/employees/access/me'))data={services:{specialists:true},roles:['platform-admin']};
      else if(pathname.endsWith('/specialists/permissions'))data={
        profiles_view:true,profiles_manage:true,catalog_view:true,catalog_manage:canManage,
      };
      else if(pathname.endsWith('/specialists/role-permissions'))data={can_manage:false};
      else if(pathname.endsWith('/specialists/workspace'))data={
        people:[],departments:[],actor:{id:77},scope:'all',managed_department_ids:[],
        catalog:{technologies:[],additional_technologies:catalog,competencies:[]},
      };
      else if(pathname.endsWith('/specialists/catalog'))data={
        technologies:[],additional_technologies:catalog,competencies:[],
      };
      else data={};
      return route.fulfill({json:{data}});
    });

    await page.goto(base+'/specialists/technologies');
    const entries = page.locator('.specialists-catalog-section tbody tr');
    await page.waitForFunction(()=>document.querySelectorAll('.specialists-catalog-section tbody tr').length===4);
    assert.equal(await entries.count(),4);
    assert.equal(await page.getByRole('button',{name:'Удалить дополнительную технологию Legacy Demo'}).count(),1);

    await page.getByRole('button',{name:'Удалить дополнительную технологию Legacy Demo'}).click();
    const modal=page.getByRole('dialog',{name:'Удалить «Legacy Demo»?'});
    await modal.waitFor({state:'visible'});
    assert.match(await modal.innerText(),/всех специалистов/);
    // Regress the owner's screenshot: dialog typography matches the platform,
    // rather than inheriting the oversized page-heading / unstyled 16px body.
    const type = await modal.evaluate(element => {
      const root = getComputedStyle(document.documentElement);
      const read = css => root.getPropertyValue(css).trim();
      const size = selector => getComputedStyle(element.querySelector(selector)).fontSize;
      return {
        family: getComputedStyle(element).fontFamily,
        modal: getComputedStyle(element).fontSize,
        heading: size('h2'),
        description: size('.specialists-delete-description'),
        label: size('.specialists-delete-replacement-label'),
        expectedHeading: read('--irlix-font-size-section-title'),
        expectedBody: read('--irlix-font-size-body'),
        expectedCaption: read('--irlix-font-size-caption'),
      };
    });
    assert.equal(type.modal, type.expectedBody, 'dialog root must use normal body typography');
    assert.equal(type.heading, type.expectedHeading, 'dialog heading must NOT use page title scale');
    assert.equal(type.description, type.expectedBody, 'paragraph must match platform body');
    assert.equal(type.label, type.expectedCaption, 'replacement label must use platform caption');
    assert.match(type.family, /Onest/i, 'dialog must use the platform font family');

    const replacement=modal.getByRole('button',{name:'Дополнительная технология для замены'});
    await replacement.click();
    assert.equal(await page.getByRole('option',{name:'Legacy Demo'}).count(),0,
      'deleted source must not be available as its own replacement');
    await page.getByRole('option',{name:'Replacement Demo'}).click();
    await modal.getByRole('button',{name:'Заменить и удалить'}).click();
    await modal.getByRole('alert').waitFor({state:'visible'});
    assert.match(await modal.getByRole('alert').innerText(),/Конфликт назначений/);
    const errorSize = await modal.getByRole('alert').evaluate(element=>getComputedStyle(element).fontSize);
    assert.equal(errorSize, '13px', 'inline validation error must not be oversized');
    assert.equal(await entries.count(),4,'backend conflict must not mutate catalog UI or close dialog');
    await modal.getByRole('button',{name:'Заменить и удалить'}).click();
    await modal.waitFor({state:'hidden'});
    await page.waitForFunction(()=>document.querySelectorAll('.specialists-catalog-section tbody tr').length===3);
    assert.deepEqual(deletionRequests.slice(0,2).map(x=>x.payload),[{replacement_id:2},{replacement_id:2}]);
    assert.match(await page.locator('.irlix-toast').innerText(),/заменена на/);

    await page.getByRole('button',{name:'Удалить дополнительную технологию Other Demo'}).click();
    const second=page.getByRole('dialog',{name:'Удалить «Other Demo»?'});
    await second.waitFor({state:'visible'});
    await second.getByRole('button',{name:'Удалить у всех'}).click();
    await second.waitFor({state:'hidden'});
    assert.deepEqual(deletionRequests.at(-1),{id:3,payload:{replacement_id:null}},
      'no replacement must explicitly remove the technology from all specialists');
    await page.waitForFunction(()=>document.querySelectorAll('.specialists-catalog-section tbody tr').length===2);

    // Inline rename: pencil swaps the name for a focused field; Escape
    // cancels, blank names and failed saves preserve state and are retryable.
    await page.getByRole('button',{name:'Редактировать название Replacement Demo'}).click();
    let inline=page.getByRole('textbox',{name:'Новое название технологии Replacement Demo'});
    await inline.waitFor({state:'visible'});
    assert.equal(await inline.inputValue(),'Replacement Demo');
    assert.ok(await inline.evaluate(input => document.activeElement===input),'rename field should autofocus');
    await inline.fill(' ');
    await inline.press('Enter');
    assert.match(await page.getByRole('alert').last().innerText(),/Введите название/);
    assert.equal(renameRequests.length,0,'blank rename never reaches server');
    await inline.fill('Updated Demo');
    await inline.press('Enter');
    assert.match(await page.getByRole('alert').last().innerText(),/уже используется/);
    assert.equal(await inline.inputValue(),'Updated Demo','failed PUT must preserve the draft');
    await page.getByRole('button',{name:'Сохранить название Replacement Demo'}).click();
    await page.waitForFunction(()=>[...document.querySelectorAll('.specialists-catalog-section tbody tr')]
      .some(row=>row.textContent.includes('Updated Demo')));
    assert.deepEqual(renameRequests.map(x=>x.payload),[{name:'Updated Demo'},{name:'Updated Demo'}]);
    assert.match(await page.locator('.irlix-toast').innerText(),/переименована/);
    await page.getByRole('button',{name:'Редактировать название Archived Demo'}).click();
    inline=page.getByRole('textbox',{name:'Новое название технологии Archived Demo'});
    await inline.fill('Abandoned Draft');
    await inline.press('Escape');
    assert.equal(await page.getByRole('textbox',{name:'Новое название технологии Archived Demo'}).count(),0,
      'Escape cancels editing without persisting');
    assert.equal(renameRequests.length,2);

    canManage=false;
    await page.reload();
    await page.waitForFunction(()=>document.querySelectorAll('.specialists-catalog-section tbody tr').length===2);
    assert.equal(await page.getByRole('button',{name:/Удалить дополнительную технологию/}).count(),0,
      'read-only catalog users must never see destructive controls');
    assert.equal(await page.getByRole('button',{name:/Редактировать название/}).count(),0,
      'read-only catalog users must not see rename controls');
    assert.deepEqual(errors,[]);
    console.log('Specialists additional catalog deletion PASS: dialog, selection, error/retry, global replacement, without replacement and inline rename, read-only role');
  } finally {
    await browser.close();
  }
})().catch(e=>{console.error(e);process.exitCode=1;});
