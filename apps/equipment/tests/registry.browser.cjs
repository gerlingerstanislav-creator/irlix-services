// Synthetic Chromium regression suite. No real services or production data.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
  try {
    const page=await browser.newPage({viewport:{width:1366,height:820}}),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    const base=process.env.EQUIPMENT_PREVIEW || 'http://127.0.0.1:5195';
    const issuer=base+'/keycloak/auth/realms/irlix';
    const employee={id:1,full_name:'Иванов Иван Иванович',department_name:'Разработка'};
    let devices=Array.from({length:65},(_,i)=>({
      id:i+1,inventory_number:i===1?'6NXBB21205800336':'EQ-'+String(i+1).padStart(3,'0'),
      manufacturer:i%2?'Apple':'Lenovo',
      model:i===0?'ThinkPad E595 с дополнительным длинным названием для проверки переноса текста':'ThinkPad E595',
      serial_number:'SERIAL-'+(i+1),type:i%2?'smartphone':'laptop',
      purpose:i%5?'development':'unknown',condition:i===2?'needs_repair':i%7?'ok':'damaged',
      written_off_at:null,
      assignment:i%3===0?{id:i+1,employee_id:1,starts_on:'2026-01-01',returned_on:null}:null,
      depreciation:{monthly:100,months:4,accumulated:400,residual:500},
      damage_accounting:{damage_count:0,repair_cost:0,value_loss:0,current_value:500}
    }));
    // Hold the first data request to verify the table does not jump during loading.
    let releaseInitialItems, markInitialItemsRequested;
    const initialItemsGate=new Promise(resolve=>{releaseInitialItems=resolve;});
    const initialItemsRequested=new Promise(resolve=>{markInitialItemsRequested=resolve;});
    let holdInitialItems=true;
    await page.addInitScript(issuer=>sessionStorage.setItem('irlix.platform.auth.tokens',JSON.stringify({
      access_token:'e30.'+btoa(JSON.stringify({iss:issuer,exp:Math.floor(Date.now()/1000)+3600,name:'Synthetic Operator'}))+'.synthetic'
    })),issuer);
    await page.route('**/.well-known/openid-configuration',route=>route.fulfill({json:{issuer,authorization_endpoint:issuer+'/auth',token_endpoint:issuer+'/token'}}));
    await page.route('**/api/**',async route=>{
      const url=new URL(route.request().url()),path=url.pathname;
      const itemId=Number(path.match(/\/items\/(\d+)$/)?.[1]);
      if(route.request().method()==='DELETE'&&itemId){
        devices=devices.filter(x=>x.id!==itemId);
        return route.fulfill({json:{data:{deleted:true}}});
      }
      let data;
      if(path.endsWith('/employees/access/me'))data={services:{equipment:true},roles:['platform-admin']};
      else if(path.endsWith('/permissions'))data={view:true,manage:true,operate:true};
      else if(path.endsWith('/employees-directory'))data=[employee];
      else if(path.endsWith('/items')||path.endsWith('/items/')){
        if(holdInitialItems){holdInitialItems=false;markInitialItemsRequested();await initialItemsGate;}
        data=devices;
      }
      else if(itemId){
        const item=devices.find(x=>x.id===itemId);
        data=item?{...item,assignments:item.assignment?[item.assignment]:[],damages:[],write_off:null,history:[]}:{};
      }
      else if(path.endsWith('/assignments'))data=Array.from({length:35},(_,i)=>({id:i+1,inventory_number:'EQ-'+String(i+1).padStart(3,'0'),manufacturer:'Lenovo',model:'ThinkPad',employee_id:1,starts_on:'2026-01-01',returned_on:null,issued_by:'Synthetic Operator'}));
      else if(path.endsWith('/valuation'))data=Array.from({length:35},(_,i)=>({id:i+1,inventory_number:'EQ-'+String(i+1).padStart(3,'0'),manufacturer:'Lenovo',model:'ThinkPad',purchase_cost:5000,useful_life_months:36,depreciation:{monthly:100,months:4,accumulated:400,residual:4600},damage_accounting:{value_loss:0,current_value:4600}}));
      else if(path.endsWith('/settings'))data={pc:48,laptop:36,smartphone:36,tablet:36};
      else data={};
      return route.fulfill({json:{data}});
    });
    await page.goto(base+'/equipment/');
    await initialItemsRequested;
    const loadingState=page.getByRole('status',{name:'Загрузка техники'});
    await loadingState.waitFor({state:'visible'});
    const headerBefore=await page.locator('.equipment-shared-registry .ui-registry-table__header').boundingBox();
    const loaderGeometry=await loadingState.evaluate(el=>{
      const panel=el.closest('.equipment-registry-panel');
      const overlay=el.getBoundingClientRect(),bounds=panel.getBoundingClientRect();
      return {position:getComputedStyle(el).position,topGap:Math.abs(overlay.top-bounds.top),heightGap:Math.abs(overlay.height-bounds.height)};
    });
    assert.ok(headerBefore,'header exists before initial data resolves');
    assert.equal(loaderGeometry.position,'absolute');
    assert.ok(loaderGeometry.topGap<2 && loaderGeometry.heightGap<2,'loader covers panel without affecting flow');
    assert.equal(await page.locator('.equipment-registry-panel .equipment-empty').count(),0,'no fake empty/loading row before data');
    releaseInitialItems();
    await page.waitForFunction(() => document.querySelectorAll('.ui-registry-table__table--body tbody tr').length === 65, { timeout:15000 });
    await loadingState.waitFor({state:'hidden'});
    const headerAfter=await page.locator('.equipment-shared-registry .ui-registry-table__header').boundingBox();
    assert.ok(Math.abs(headerBefore.y-headerAfter.y)<1,'table header must not jump after loading');
    assert.equal(await page.locator('.ui-registry-table__table--body tbody tr').count(),65);
    // Employees is the reference for 13px type, bold identifiers and UiBadge,
    // but neutral table values must NOT inherit the legacy blue info token.
    const employeeStyle = await page.locator('.ui-registry-table__table--body tbody tr').first().evaluate(row=>{
      const sample=(value)=>{
        const probe=document.createElement('span');
        probe.style.color='var('+value+')';
        row.appendChild(probe);
        const result=getComputedStyle(probe).color;
        probe.remove();
        return result;
      };
      const cells=row.querySelectorAll('td');
      const identity=row.querySelector('.equipment-inventory-link');
      const badge=row.querySelector('.ui-badge');
      return {
        textColor:getComputedStyle(cells[1]).color,
        infoColor:sample('--irlix-status-info-text'),
        identityColor:getComputedStyle(identity).color,
        primaryColor:sample('--irlix-color-text'),
        identityWeight:Number(getComputedStyle(identity).fontWeight),
        fontSize:getComputedStyle(cells[1]).fontSize,
        rootFontFamily:getComputedStyle(document.body).fontFamily,
        rowFontFamily:getComputedStyle(cells[1]).fontFamily,
        badgeSize:getComputedStyle(badge).fontSize,
        badgeClass:badge.className
      };
    });
    assert.equal(employeeStyle.textColor,employeeStyle.primaryColor,'Equipment ordinary cells must be neutral');
    assert.notEqual(employeeStyle.textColor,employeeStyle.infoColor,'blue info text is not suitable for ordinary cells');
    assert.equal(employeeStyle.identityColor,employeeStyle.primaryColor,'inventory numbers use Employees primary text');
    assert.ok(employeeStyle.identityWeight>=700,'inventory number is bold like Employees identity');
    assert.equal(employeeStyle.fontSize,'13px','Employees table typography');
    assert.equal(employeeStyle.badgeSize,'12px','Employees status badge typography');
    assert.equal(employeeStyle.rowFontFamily,employeeStyle.rootFontFamily,'shared Onest font');
    assert.match(employeeStyle.badgeClass,/ui-badge--(success|danger|warning)/,'condition is a shared semantic badge');
    assert.ok(await page.locator('.ui-registry-table__table--body .ui-badge--danger').count(),'damaged state is a danger badge');
    assert.ok(await page.locator('.ui-registry-table__table--body .ui-badge--success').count(),'healthy state is a success badge');
    assert.ok(await page.locator('.ui-registry-table__table--body .ui-badge--warning').count(),'needs-repair state is a warning badge');
    for (const width of [1366,1024,390]) {
      await page.setViewportSize({width,height:820});
      const state=await page.locator('.equipment-shared-registry').evaluate(root=>{
        const viewport=root.querySelector('.ui-registry-table__scroll');
        const header=root.querySelector('.ui-registry-table__header');
        const hs=[...header.querySelectorAll('th')].map(el=>el.getBoundingClientRect());
        const row=viewport.querySelector('tbody tr');
        const cells=[...row.children].map(el=>el.getBoundingClientRect());
        const typeText=document.createRange();
        typeText.selectNodeContents(row.children[1]);
        const typeTextBox=typeText.getBoundingClientRect();
        const rowBox=row.getBoundingClientRect();
        const rail=document.querySelector('.equipment-filter-rail');
        return {
          railOverlap:viewport.getBoundingClientRect().right-rail.getBoundingClientRect().left,
          modelWidth:cells[2].width,
          inventoryAlign:getComputedStyle(row.querySelector('.equipment-inventory-link')).textAlign,
          typeCenterDelta:Math.abs((typeTextBox.top+typeTextBox.bottom-rowBox.top-rowBox.bottom)/2),
          radius:getComputedStyle(root.closest('.equipment-registry-panel')).borderTopLeftRadius,
          align:getComputedStyle(viewport.querySelector('tbody td')).verticalAlign,
          y:hs[0].top, scroll:viewport.scrollHeight>viewport.clientHeight,
          dx:Math.max(...hs.map((h,i)=>Math.abs(h.left-cells[i].left))),
          dw:Math.max(...hs.map((h,i)=>Math.abs(h.width-cells[i].width))),
          modelWhiteSpace:getComputedStyle(viewport.querySelector('tbody tr').children[2]).whiteSpace };
      });
      assert.equal(state.radius,'0px');
      assert.equal(state.align,'middle');
      assert.ok(state.scroll);
      assert.equal(state.modelWhiteSpace,'normal');
      assert.equal(state.inventoryAlign,'left','inventory numbers use left alignment, even if they wrap');
      assert.ok(state.modelWidth>=260 && state.modelWidth<=285,'model column should have a stable width: '+JSON.stringify(state));
      assert.ok(state.railOverlap<=1,'registry scrollbar must stop before filter rail: '+JSON.stringify(state));
      assert.ok(state.typeCenterDelta<5,'single-line cell text should be centered within wrapped rows: '+JSON.stringify(state));
      assert.ok(state.dx<2 && state.dw<2,JSON.stringify(state));
      await page.locator('.ui-registry-table__scroll').evaluate(el=>el.scrollTop=480);
      const next=await page.locator('.ui-registry-table__header th').first().evaluate(el=>el.getBoundingClientRect().top);
      assert.ok(Math.abs(next-state.y)<2);
      await page.locator('.ui-registry-table__scroll').evaluate(el=>el.scrollTop=0);
    }
    await page.setViewportSize({width:1366,height:820});
    const iconShapes=await page.locator('.equipment-filter-rail .irlix-filter-rail__filters .irlix-filter-rail__icon svg').evaluateAll(xs=>xs.map(x=>x.innerHTML));
    assert.equal(iconShapes.length,6,'all filter icons rendered');
    assert.equal(new Set(iconShapes).size,6,'filter icons have distinct actual paths');
    await page.getByRole('button',{name:'+ Техника'}).click();
    await page.locator('.equipment-modal label').first().waitFor({state:'visible',timeout:10000});
    const colors=await page.evaluate(()=>{
      const label=document.querySelector('.equipment-modal label');
      if(!label) throw new Error('Equipment modal field label not found');
      const reference=document.createElement('span');
      reference.className='irlix-field';
      document.body.append(reference);
      try { return [getComputedStyle(label).color,getComputedStyle(reference).color]; }
      finally { reference.remove(); }
    });
    assert.deepEqual(colors.slice(0,1),colors.slice(1),'dialog labels use system field color');
    await page.locator('.equipment-modal-backdrop').click({position:{x:3,y:3}});
    await page.setViewportSize({width:1366,height:820});
    await page.locator('.equipment-filter-rail .irlix-filter-rail__icon').first().hover();
    const holderSearch=page.getByPlaceholder('Номер, модель, серийный №, ФИО');
    await holderSearch.fill('Иванов Иван Иванович');
    assert.ok(await page.locator('.ui-registry-table__table--body tbody tr').count()>1,'holder full-name search matches assigned devices');
    await holderSearch.fill('');
    await page.getByRole('button',{name:'EQ-001'}).click();
    await page.getByRole('button',{name:'Удалить',exact:true}).waitFor();
    page.once('dialog',d=>d.accept());
    await page.getByRole('button',{name:'Удалить',exact:true}).click();
    await page.waitForFunction(()=>document.querySelectorAll('.ui-registry-table__table--body tbody tr').length===64);
    assert.equal(await page.locator('.ui-registry-table__table--body tbody tr').count(),64,'delete removes asset from registry');
    for (const [section, url, expectedColumns] of [['assignments','/equipment/assignments',5],['valuation','/equipment/valuation',11]]) {
      await page.goto(base+url);
      await page.waitForFunction(()=>document.querySelectorAll('.ui-registry-table__table--body tbody tr').length===35);
      const neutralText=await page.locator('.equipment-shared-registry').evaluate(root=>{
        const probe=document.createElement('span');
        probe.style.color='var(--irlix-color-text)';
        root.append(probe);
        const reference=getComputedStyle(probe).color;
        probe.remove();
        const cells=[...root.querySelectorAll('.ui-registry-table__table--body tbody tr:first-child td')];
        return {reference, colors:cells.map(cell=>getComputedStyle(cell).color)};
      });
      assert.ok(neutralText.colors.every(color=>color===neutralText.reference),section+' cells must use neutral text: '+JSON.stringify(neutralText));
      const state=await page.locator('.equipment-shared-registry').evaluate(root=>{
        const v=root.querySelector('.ui-registry-table__scroll');
        const head=root.querySelector('.ui-registry-table__header');
        const h=[...head.querySelectorAll('th')].map(e=>e.getBoundingClientRect());
        const cells=[...v.querySelector('tbody tr').children].map(e=>e.getBoundingClientRect());
        return {headCount:h.length,bodyCount:cells.length,scroll:v.scrollHeight>v.clientHeight,
          dx:Math.max(...h.map((e,i)=>Math.abs(e.left-cells[i].left))),
          dw:Math.max(...h.map((e,i)=>Math.abs(e.width-cells[i].width))),
          headerY:h[0].top};
      });
      assert.equal(state.headCount,expectedColumns,section+' headers');
      assert.equal(state.bodyCount,expectedColumns,section+' body cells');
      assert.ok(state.scroll,section+' has body scrollbar');
      assert.ok(state.dx<2&&state.dw<2,section+' header/body alignment: '+JSON.stringify(state));
      await page.locator('.ui-registry-table__scroll').evaluate(el=>el.scrollTop=300);
      const next=await page.locator('.ui-registry-table__header th').first().evaluate(el=>el.getBoundingClientRect().top);
      assert.ok(Math.abs(next-state.headerY)<2,section+' sticky header');
    }
    assert.deepEqual(errors,[]);
    console.log('Equipment synthetic Chromium PASS: Employees row typography/badges, loading, rail spacing, alignment, scroll and CRUD');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
