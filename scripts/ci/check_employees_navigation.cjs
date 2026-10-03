// Real deployed frontend assets; synthetic identity and business API only.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const raw = process.env.IRLIX_LOCAL_URL;
  assert(raw, 'IRLIX_LOCAL_URL is required');
  const publicUrl = new URL(raw.includes('://') ? raw : `http://${raw}`);
  const tunnel = 'http://127.0.0.1:18080';
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const issuer = tunnel + '/keycloak/auth/realms/irlix';
    await page.addInitScript(({ issuer }) => {
      const payload = btoa(JSON.stringify({ iss: issuer, exp: Math.floor(Date.now() / 1000) + 3600, preferred_username: 'synthetic-admin' }));
      sessionStorage.setItem('irlix.platform.auth.tokens', JSON.stringify({ access_token: `header.${payload}.signature` }));
    }, { issuer });
    const departments = [
      {id:1,name:'Тестовая компания',parent_id:null,is_production:false,active_employee_count:0},
      {id:2,name:'Тестовый отдел',parent_id:1,is_production:false,active_employee_count:1,alias:null},
      {id:3,name:'Тестовая группа',parent_id:2,is_production:true,active_employee_count:1},
      {id:4,name:'Тестовый сосед',parent_id:1,is_production:false,active_employee_count:0},
    ];
    const positions = Array.from({length:80}, (_, i) => ({id:i+1,name:`Демо-должность ${String(i+1).padStart(2,'0')}`,direction_id:3,base_salary:i===0?123456:null,closed_at:null,employee_count:i===0?1:0,created_at:'2026-10-01T10:00:00Z',updated_at:'2026-10-02T10:00:00Z'}));
    positions.push({id:81,name:'Должность соседа',direction_id:4,closed_at:null,employee_count:0},{id:82,name:'Должность отдела',direction_id:2,closed_at:null,employee_count:1});
    const employees = [
      {id:1,full_name:'Синтетический действующий сотрудник',department_id:3,position_id:1,position:positions[0].name,employment_status:'Трудоустроен'},
      {id:2,full_name:'Синтетический уволенный сотрудник',department_id:3,position_id:1,position:positions[0].name,employment_status:'Уволен'},
      {id:3,full_name:'Синтетический сотрудник отдела',department_id:2,position_id:82,position:'Должность отдела',employment_status:'Трудоустроен'},
    ];
    const mutations = [];
    await page.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.pathname.includes('openid-configuration')) return route.fulfill({ json: { issuer, authorization_endpoint: issuer + '/auth', token_endpoint: issuer + '/token' } });
      if (url.pathname.startsWith('/api/')) {
        const id = Number(url.pathname.split('/').at(/\/(close|reopen)$/.test(url.pathname) ? -2 : -1));
        if (['POST','PUT','PATCH','DELETE'].includes(request.method())) {
          const body = request.method() === 'DELETE' ? {} : (request.postDataJSON() || {}); mutations.push({path:url.pathname,method:request.method(),body});
          const list = url.pathname.includes('/staff-positions') ? positions : url.pathname.includes('/departments') ? departments : employees;
          if (request.method() === 'DELETE') {
            if (employees.some(e=>e.position_id===id && e.employment_status!=='Уволен')) return route.fulfill({status:409,json:{message:'Нельзя удалить должность, на которой есть действующие сотрудники. Переведите сотрудников на другие должности или закройте эту должность.'}});
            const index = list.findIndex(i=>i.id===id); if (index>=0) list.splice(index,1);
          } else if (request.method() === 'POST' && url.pathname.endsWith('/reopen')) { const item=list.find(i=>i.id===id); if(item) item.closed_at=null; } else if (request.method() === 'POST' && url.pathname.endsWith('/close')) { const item=list.find(i=>i.id===id); if(item) item.closed_at='2026-10-03T20:00:00Z'; } else if (request.method() === 'POST' && url.pathname.endsWith('/staff-positions')) list.push({id:83,...body,employee_count:0,closed_at:null});
          else { const item = list.find(i => i.id === id); if (item) { Object.assign(item,body); if (body.position_id === null) item.position = null; } }
        }
        const data = url.pathname.endsWith('/access/me') ? { allowed:true,roles:['platform-admin'],permissions:{'employees.manage':true,'organization.manage':true,'staff_positions.manage':true,'access.manage':true,'audit.read':true} }
          : url.pathname.endsWith('/access/roles') ? [{key:'system-admin',label:'Синтетическая роль',description:'Демо',members:[],member_count:0}]
          : url.pathname.endsWith('/departments') ? departments
          : url.pathname.endsWith('/staff-positions') ? positions
          : url.pathname.endsWith('/reference-data') ? {employee_statuses:['Трудоустроен','Уволен'],work_formats:[],cooperation_types:[],genders:[]}
          : /\/employees\/[0-9]+$/.test(url.pathname) ? {employee:employees.find(e=>e.id===id),employment_periods:[],salary_history:[],status_history:[],assignment_history:[]}
          : url.pathname.endsWith('/employees') ? employees : [];
        return route.fulfill({json:{data}});
      }
      assert.equal(url.origin, tunnel, 'Unexpected navigation origin');
      const response = await fetch(url, {headers:{Host:publicUrl.host},redirect:'manual'});
      const body = Buffer.from(await response.arrayBuffer());
      if (url.pathname.endsWith('.js')) {
        assert(response.headers.get('content-type')?.includes('javascript'));
        console.log(`[browser] deployed JS ${url.pathname}`);
      }
      await route.fulfill({status:response.status,headers:Object.fromEntries([...response.headers].filter(([key])=>!['content-encoding','content-length','transfer-encoding'].includes(key))),body});
    });
    const sidebar = async name => { await page.getByRole('button',{name,exact:true}).first().click(); await page.locator('.irlix-app-topbar').hover(); };
    await page.goto(tunnel + '/employees/');
    await page.getByRole('button',{name:'Орг. структура',exact:true}).first().waitFor();
    await page.evaluate(()=>window.navigationSmokeMarker='same-document');
    await sidebar('Подразделения');
    assert((await page.getByRole('button',{name:'Свернуть Тестовый отдел',exact:true}).textContent()).includes('−'));
    await page.getByRole('button',{name:'Свернуть Тестовый отдел',exact:true}).click();
    await page.getByRole('button',{name:'Развернуть Тестовый отдел',exact:true}).click();
    await sidebar('Орг. структура');
    await page.locator('.staffing-route').waitFor();
    assert.equal(new URL(page.url()).pathname,'/employees/organization');
    assert.equal(await page.evaluate(()=>window.navigationSmokeMarker),'same-document');
    assert.equal(await page.locator('[data-position-id]').count(),0);
    assert.equal(await page.locator('.staffing-route thead th').count(),7);
    assert.equal(await page.locator('.staffing-route button[aria-label^="Редактировать"]').count(),0);
    const scroll = page.getByTestId('staff-tree-scroll');
    assert(await scroll.evaluate(el=>el.clientHeight>0 && el.getBoundingClientRect().bottom<=window.innerHeight+1));
    assert.equal(await page.locator('[data-department-id="3"] .org-position-count').textContent(),'80');
    assert.equal(await page.locator('[data-department-id="2"] .org-position-count').textContent(),'1');
    assert.equal(await page.getByRole('button',{name:/Показать должности/}).count(),0);
    await page.locator('[data-department-id="2"] .org-entity-name').click();
    const drawer = page.locator('.irlix-drawer').filter({has:page.getByTestId('department-card')});
    const positionDrawer = page.locator('.irlix-drawer').filter({has:page.getByTestId('position-card')});
    const creationDrawer = page.locator('.irlix-drawer').filter({has:page.locator('form.staff-position-form')});
    await page.getByTestId('department-card').waitFor();
    assert.equal(await drawer.locator('.irlix-drawer-header strong').textContent(),'Тестовый отдел');
    assert(await drawer.evaluate(el=>Math.abs(el.getBoundingClientRect().width-window.innerWidth*0.4)<2));
    assert.equal(await drawer.locator('.organization-card-field').first().evaluate(el=>getComputedStyle(el).borderBottomWidth),'0px');
    assert(await drawer.locator('.irlix-drawer-close').evaluate(el=>{const a=el.getBoundingClientRect(),b=el.querySelector('svg').getBoundingClientRect();return Math.abs((a.left+a.right-b.left-b.right)/2)<1&&Math.abs((a.top+a.bottom-b.top-b.bottom)/2)<1;}));
    assert.equal(await drawer.getByRole('tab',{name:'Инфо',exact:true}).getAttribute('aria-selected'),'true');
    await drawer.getByRole('button',{name:'Редактировать Алиас',exact:true}).click();
    await drawer.getByRole('textbox',{name:'Алиас',exact:true}).fill('Отменённый алиас');
    await drawer.getByRole('button',{name:'Отменить Алиас',exact:true}).click();
    assert.equal(mutations.length,0);
    await drawer.getByRole('button',{name:'Редактировать Алиас',exact:true}).click();
    await drawer.getByRole('textbox',{name:'Алиас',exact:true}).fill('Синтетический алиас');
    await drawer.getByRole('button',{name:'Сохранить Алиас',exact:true}).click();
    await drawer.getByRole('button',{name:'Редактировать Алиас',exact:true}).waitFor();
    assert.equal(mutations.at(-1).body.alias,'Синтетический алиас');
    await drawer.getByRole('button',{name:'Редактировать Руководитель',exact:true}).click();
    await drawer.getByRole('button',{name:'Руководитель',exact:true}).click();
    const peopleList = drawer.getByRole('listbox');
    await peopleList.getByText('Тестовая компания',{exact:true}).waitFor();
    assert.equal(await peopleList.getByRole('option',{name:'Синтетический уволенный сотрудник',exact:true}).count(),0);
    await drawer.getByPlaceholder('Поиск',{exact:true}).fill('Тестовая компания');
    assert.equal(await peopleList.getByRole('option').count(),2);
    await peopleList.getByRole('option',{name:'Синтетический действующий сотрудник',exact:true}).click();
    await drawer.getByRole('button',{name:'Сохранить Руководитель',exact:true}).click();
    await drawer.getByRole('button',{name:'Редактировать Руководитель',exact:true}).waitFor();
    assert.equal(mutations.at(-1).body.manager_id,1);
    await drawer.getByRole('button',{name:'Редактировать HR',exact:true}).click();
    await drawer.getByRole('button',{name:'HR',exact:true}).click();
    await peopleList.getByRole('option',{name:'Синтетический сотрудник отдела',exact:true}).click();
    await drawer.getByRole('button',{name:'Сохранить HR',exact:true}).click();
    await drawer.getByRole('button',{name:'Редактировать HR',exact:true}).waitFor();
    assert.equal(mutations.at(-1).body.hr_id,3);
    await drawer.getByRole('button',{name:'Закрыть',exact:true}).click();
    await sidebar('Роли');
    await page.getByText('Синтетическая роль',{exact:true}).first().waitFor();
    await page.locator('.irlix-app-topbar').getByRole('button',{name:'+ Назначить',exact:true}).click();
    const roleForm=page.locator('form.role-assign-modal');
    await roleForm.getByRole('button',{name:'Сотрудник для роли',exact:true}).click();
    await roleForm.getByRole('option',{name:'Синтетический действующий сотрудник',exact:true}).click();
    assert(await roleForm.getByRole('button',{name:'Назначить',exact:true}).isEnabled());
    await roleForm.getByRole('button',{name:'Отмена',exact:true}).click();
    await sidebar('История действий');
    await page.getByRole('button',{name:'Сотрудник в журнале',exact:true}).click();
    await page.getByRole('option',{name:'Синтетический уволенный сотрудник',exact:true}).click();
    await page.getByRole('button',{name:'Применить',exact:true}).click();
    await sidebar('Орг. структура');
    await page.getByRole('button',{name:'Открыть должности Тестовая группа',exact:true}).click();
    const positionsTab = drawer.getByRole('tab',{name:/^Должности/});
    assert.equal(await positionsTab.getAttribute('aria-selected'),'true');
    assert.equal(await drawer.locator('[data-card-position-id]').count(),80);
    assert.equal(await drawer.locator('[data-card-position-id="82"]').count(),0);
    assert.match(await drawer.locator('[data-card-position-id="1"] .position-salary').textContent(),/123\D456/);
    const positionsScroll = page.getByTestId('department-positions');
    assert(await positionsScroll.evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Positions exceed the drawer width');
    assert(await positionsScroll.evaluate(el=>el.scrollHeight>el.clientHeight && el.clientHeight>0));
    assert(await positionsScroll.evaluate(el=>el.getBoundingClientRect().bottom<=window.innerHeight+1));
    await positionsScroll.evaluate(el=>el.scrollTop=el.scrollHeight);
    assert(await positionsScroll.evaluate(el=>el.scrollTop>0));
    await positionsScroll.evaluate(el=>el.scrollTop=0);
    const parentResize = await drawer.locator('.irlix-drawer-resize-handle').boundingBox();
    await page.mouse.move(parentResize.x+4,parentResize.y+100); await page.mouse.down();
    await page.mouse.move(parentResize.x-46,parentResize.y+100); await page.mouse.up();
    const departmentWidth = await drawer.evaluate(el=>el.getBoundingClientRect().width);
    assert(departmentWidth>500);
    await drawer.locator('[data-card-position-id="1"] .position-name').click();
    await page.getByTestId('position-card').waitFor();
    assert.equal(await positionDrawer.locator('form').count(),0);
    assert.equal(await page.getByTestId('department-card').count(),1);
    assert.equal(await drawer.getAttribute('inert'),'');
    assert(await positionDrawer.evaluate(el=>Math.abs(el.getBoundingClientRect().width-window.innerWidth*0.3)<2));
    const resize = await positionDrawer.locator('.irlix-drawer-resize-handle').boundingBox();
    await page.mouse.move(resize.x+4,resize.y+100); await page.mouse.down();
    await page.mouse.move(resize.x-96,resize.y+100); await page.mouse.up();
    assert(await positionDrawer.evaluate(el=>el.getBoundingClientRect().width>window.innerWidth*0.3+90));
    assert(await positionDrawer.evaluate(el=>Math.abs(el.getBoundingClientRect().right-window.innerWidth)<2));
    await positionDrawer.getByRole('button',{name:'Редактировать Название',exact:true}).click();
    await positionDrawer.getByRole('textbox',{name:'Название',exact:true}).fill('Обновлённая синтетическая должность');
    await positionDrawer.getByRole('button',{name:'Сохранить Название',exact:true}).click();
    await positionDrawer.getByRole('button',{name:'Редактировать Название',exact:true}).waitFor();
    assert.equal(mutations.at(-1).body.name,'Обновлённая синтетическая должность');
    assert.equal(mutations.at(-1).body.base_salary,123456);
    assert.equal(await positionDrawer.getByRole('tab').count(),0);
    assert.equal(await positionDrawer.getByRole('button',{name:'К подразделению',exact:true}).count(),0);
    for (const label of ['Оклад','Статус','Дата закрытия','Сотрудники','ID','Создана','Обновлена']) await positionDrawer.locator('dt').filter({hasText:new RegExp('^'+label+'$')}).waitFor();
    assert.equal(await positionDrawer.getByRole('button',{name:'Редактировать ID',exact:true}).count(),0);
    const beforeSalary = mutations.length;
    await positionDrawer.getByRole('button',{name:'Редактировать Оклад',exact:true}).click();
    await positionDrawer.getByRole('spinbutton',{name:'Оклад',exact:true}).fill('999');
    await positionDrawer.getByRole('button',{name:'Отменить Оклад',exact:true}).click();
    assert.equal(mutations.length,beforeSalary);
    await positionDrawer.getByRole('button',{name:'Редактировать Оклад',exact:true}).click();
    await positionDrawer.getByRole('spinbutton',{name:'Оклад',exact:true}).fill('234567.89');
    await positionDrawer.getByRole('button',{name:'Сохранить Оклад',exact:true}).click();
    await positionDrawer.getByRole('button',{name:'Редактировать Оклад',exact:true}).waitFor();
    assert.equal(mutations.at(-1).body.base_salary,234567.89);
    await positionDrawer.getByRole('button',{name:'Редактировать Оклад',exact:true}).click();
    await positionDrawer.getByRole('spinbutton',{name:'Оклад',exact:true}).fill('');
    await positionDrawer.getByRole('button',{name:'Сохранить Оклад',exact:true}).click();
    await positionDrawer.getByRole('button',{name:'Редактировать Оклад',exact:true}).waitFor();
    assert.equal(mutations.at(-1).body.base_salary,null);
    await positionDrawer.getByRole('button',{name:'Закрыть',exact:true}).click();
    assert.equal(await positionsTab.getAttribute('aria-selected'),'true');
    assert.equal(await drawer.evaluate(el=>el.getBoundingClientRect().width),departmentWidth);
    assert.equal(await drawer.getAttribute('inert'),null);
    await drawer.locator('[data-card-position-id="1"] a').click();
    await page.locator('.employee-table').waitFor();
    assert.equal(new URL(page.url()).searchParams.get('position_id'),'1');
    assert.equal(new URL(page.url()).searchParams.get('employment_status'),'Трудоустроен');
    assert.equal(await page.locator('.employee-table tbody tr').count(),1);
    assert.equal(await page.evaluate(()=>window.navigationSmokeMarker),'same-document');
    await page.locator('.employee-table tbody tr').click();
    const employeeCard = page.locator('.employee-card-drawer');
    await employeeCard.locator('.editable-attribute').filter({has:page.locator('dt',{hasText:'Должность'})}).hover();
    await employeeCard.getByRole('button',{name:'Редактировать Должность',exact:true}).click();
    const attributeSelect = employeeCard.locator('.attribute-editor select');
    assert.equal(await attributeSelect.locator('option[value="82"]').count(),0);
    await employeeCard.locator('.attribute-cancel').click();
    await employeeCard.locator('.editable-attribute').filter({has:page.locator('dt',{hasText:'Подразделение'})}).hover();
    await employeeCard.getByRole('button',{name:'Редактировать Подразделение',exact:true}).click();
    await attributeSelect.selectOption('2');
    await employeeCard.locator('.attribute-save').click();
    await employeeCard.getByRole('button',{name:'Редактировать Подразделение',exact:true}).waitFor();
    assert.equal(mutations.at(-1).body.position_id,null);
    await employeeCard.locator('.employee-card-top button').click();
    // Closing the card leaves the cursor over the filter rail; leave its hover flyout.
    await page.locator('.irlix-app-topbar').hover();
    console.log('[browser] Department/position view cards and employee inline assignment PASS');
    await page.locator('.irlix-app-topbar').getByRole('button',{name:'+ Сотрудник',exact:true}).click();
    const modal = page.locator('form.employee-modal');
    const departmentSelect = modal.getByRole('button',{name:'Подразделение сотрудника',exact:true});
    const positionSelect = modal.getByRole('combobox',{name:'Должность сотрудника',exact:true});
    assert(await positionSelect.isDisabled());
    await departmentSelect.click();
    const departmentList = modal.getByRole('listbox');
    const rootOption = departmentList.getByRole('option',{name:'Тестовая компания',exact:true});
    const childOption = departmentList.getByRole('option',{name:'Тестовая группа',exact:true});
    assert(await childOption.evaluate(el=>parseFloat(getComputedStyle(el).paddingLeft)) > await rootOption.evaluate(el=>parseFloat(getComputedStyle(el).paddingLeft)));
    await departmentList.getByRole('option',{name:'Тестовый отдел',exact:true}).click();
    assert.deepEqual(await positionSelect.locator('option').evaluateAll(opts=>opts.map(o=>o.value)),['','82']);
    await positionSelect.selectOption('82');
    await departmentSelect.click();
    await modal.getByPlaceholder('Поиск подразделения',{exact:true}).fill('Тестовая группа');
    assert.equal(await departmentList.getByRole('option').count(),1);
    await departmentList.getByRole('option',{name:'Тестовая группа',exact:true}).click();
    assert.equal(await positionSelect.inputValue(),'');
    assert.equal(await positionSelect.locator('option[value="82"]').count(),0);
    await modal.getByRole('button',{name:'Отмена',exact:true}).click();
    await sidebar('Орг. структура');
    await page.getByRole('button',{name:'Открыть должности Тестовый отдел',exact:true}).click();
    await drawer.getByRole('button',{name:'+ Должность',exact:true}).click();
    await creationDrawer.waitFor();
    assert.equal(await page.getByTestId('department-card').count(),1);
    assert.equal(await creationDrawer.locator('.ui-search-select__label').textContent(),'Тестовый отдел');
    await creationDrawer.locator('input[maxlength="255"]').fill('Синтетическая новая должность');
    await creationDrawer.getByRole('button',{name:'Добавить',exact:true}).click();
    await creationDrawer.waitFor({state:'hidden'});
    await page.getByTestId('department-card').waitFor();
    await drawer.locator('[data-card-position-id="83"]').waitFor();
    assert.equal(await positionsTab.getAttribute('aria-selected'),'true');
    assert.equal(await drawer.locator('[data-card-position-id]').count(),2);
    assert.equal(mutations.at(-1).body.direction_id,2);
    const beforeDelete = mutations.length;
    page.once('dialog',dialog=>dialog.dismiss());
    await drawer.getByRole('button',{name:'Удалить должность Синтетическая новая должность',exact:true}).click();
    assert.equal(mutations.length,beforeDelete);
    await drawer.getByRole('button',{name:'Закрыть должность Должность отдела',exact:true}).click();
    await drawer.getByRole('button',{name:'Переоткрыть должность Должность отдела',exact:true}).waitFor();
    await drawer.getByRole('button',{name:'Переоткрыть должность Должность отдела',exact:true}).click();
    await drawer.getByRole('button',{name:'Закрыть должность Должность отдела',exact:true}).waitFor();
    assert.equal(positions.find(p=>p.id===82).closed_at,null);
    await drawer.locator('[data-card-position-id="83"] .position-name').click();
    await positionDrawer.getByRole('button',{name:'Закрыть должность',exact:true}).click();
    await positionDrawer.getByText('Закрыта',{exact:true}).waitFor();
    await positionDrawer.getByRole('button',{name:'Переоткрыть должность',exact:true}).click();
    await positionDrawer.getByText('Открыта',{exact:true}).waitFor();
    assert.equal(positions.find(p=>p.id===83).closed_at,null);
    await positionDrawer.getByRole('button',{name:'Закрыть должность',exact:true}).click();
    await positionDrawer.getByText('Закрыта',{exact:true}).waitFor();
    page.once('dialog',dialog=>dialog.accept());
    await positionDrawer.getByRole('button',{name:'Удалить должность',exact:true}).click();
    await page.getByTestId('position-card').waitFor({state:'hidden'});
    await drawer.locator('[data-card-position-id="83"]').waitFor({state:'hidden'});
    assert.equal(mutations.at(-1).method,'DELETE');
    assert.equal(await page.locator('[data-department-id="2"] .org-position-count').textContent(),'1');
    page.once('dialog',dialog=>dialog.accept());
    await drawer.getByRole('button',{name:'Удалить должность Должность отдела',exact:true}).click();
    await drawer.getByRole('alert').filter({hasText:/Нельзя удалить должность, на которой есть действующие сотрудники/}).waitFor();
    assert.equal(await drawer.locator('[data-card-position-id="82"]').count(),1);
    await drawer.getByRole('button',{name:'Закрыть',exact:true}).click();
    console.log('[browser] Position salary save/cancel/clear, complete fields, no tabs/back, native delete confirmation/cancel/success/409 and closure and reopening from list/card, nested resizable 40%/30% cards, centered close, department creation, fitting position table, tabs, own counts, salaries, field save/cancel, active employee filters, tree department selection/search, dependent position forms and scrolling PASS');
    await page.goBack();
    await page.locator('.registry-scroll-panel').waitFor();
    await page.goForward();
    await page.locator('.staffing-route').waitFor();
    await page.reload();
    await page.locator('.staffing-route').waitFor();
    await page.goto(tunnel + '/employees/staff-positions');
    await page.locator('.staffing-route').waitFor();
    await page.goto(tunnel + '/employees/organization');
    await page.locator('.staffing-route').waitFor();
    await page.setViewportSize({width:390,height:844});
    await page.getByRole('button',{name:'Открыть должности Тестовый отдел',exact:true}).click();
    await drawer.locator('[data-card-position-id="82"]').waitFor();
    assert(await drawer.evaluate(el=>el.getBoundingClientRect().width<=window.innerWidth+1));
    assert(await page.getByTestId('department-positions').evaluate(el=>el.scrollWidth<=el.clientWidth+1));
    await drawer.getByRole('button',{name:'Закрыть',exact:true}).click();
    await page.getByRole('button',{name:'Открыть должности Тестовая компания',exact:true}).click();
    await drawer.getByText('В подразделении пока нет должностей.',{exact:true}).waitFor();
    assert.equal(await drawer.locator('[data-card-position-id]').count(),0);
    assert.deepEqual(errors,[],'Browser runtime errors');
    console.log('[browser] SPA navigation, Back/Forward, refresh, legacy URL and mobile scroll PASS');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});



