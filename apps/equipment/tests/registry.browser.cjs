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
      id:i+1,inventory_number:'EQ-'+String(i+1).padStart(3,'0'),
      manufacturer:i%2?'Apple':'Lenovo',
      model:i===0?'ThinkPad E595 с дополнительным длинным названием для проверки переноса текста':'ThinkPad E595',
      serial_number:'SERIAL-'+(i+1),type:i%2?'smartphone':'laptop',
      purpose:i%5?'development':'unknown',condition:i%7?'ok':'damaged',
      written_off_at:null,
      assignment:i%3===0?{id:i+1,employee_id:1,starts_on:'2026-01-01',returned_on:null}:null,
      depreciation:{monthly:100,months:4,accumulated:400,residual:500},
      damage_accounting:{damage_count:0,repair_cost:0,value_loss:0,current_value:500}
    }));
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
      else if(path.endsWith('/items')||path.endsWith('/items/'))data=devices;
      else if(itemId){
        const item=devices.find(x=>x.id===itemId);
        data=item?{...item,assignments:item.assignment?[item.assignment]:[],damages:[],write_off:null,history:[]}:{};
      }
      else if(path.endsWith('/assignments'))data=[];
      else if(path.endsWith('/valuation'))data=[];
      else if(path.endsWith('/settings'))data={pc:48,laptop:36,smartphone:36,tablet:36};
      else data={};
      return route.fulfill({json:{data}});
    });
    await page.goto(base+'/equipment/');
    await page.locator('.equipment-table tbody tr').first().waitFor();
    assert.equal(await page.locator('.equipment-table tbody tr').count(),65);
    for(const width of [1366,1024,390]){
      await page.setViewportSize({width,height:820});
      const before=await page.evaluate(()=>{
        const panel=document.querySelector('.equipment-registry-panel');
        const viewport=panel.querySelector('.equipment-table-wrap');
        const table=viewport.querySelector('table');
        const head=table.querySelector('thead tr'),body=table.querySelector('tbody tr');
        const hs=[...head.children].map(e=>e.getBoundingClientRect());
        const cs=[...body.children].map(e=>e.getBoundingClientRect());
        return {
          radius:getComputedStyle(panel).borderTopLeftRadius,
          align:getComputedStyle(body.children[2]).verticalAlign,
          headerY:head.children[0].getBoundingClientRect().top,
          scroll:viewport.scrollHeight>viewport.clientHeight,
          dx:Math.max(...hs.map((h,i)=>Math.abs(h.left-cs[i].left))),
          dw:Math.max(...hs.map((h,i)=>Math.abs(h.width-cs[i].width))),
          modelWhiteSpace:getComputedStyle(body.children[2]).whiteSpace,
          rowHeight:body.getBoundingClientRect().height
        };
      });
      assert.equal(before.radius,'0px','flat Equipment table panel at '+width);
      assert.equal(before.align,'middle','row texts vertically centered at '+width);
      assert.equal(before.scroll,true,'registry needs an internal scrollbar at '+width);
      assert.equal(before.modelWhiteSpace,'normal','long models can wrap');
      assert.ok(before.dx<2&&before.dw<2,'headers match body columns at '+width+': '+JSON.stringify(before));
      assert.ok(before.rowHeight>30,'long models may wrap and grow rows');
      await page.locator('.equipment-table-wrap').evaluate(e=>e.scrollTop=480);
      const after=await page.locator('.equipment-table thead th').first().evaluate(e=>e.getBoundingClientRect().top);
      assert.ok(Math.abs(after-before.headerY)<2,'header stays pinned at '+width);
      await page.locator('.equipment-table-wrap').evaluate(e=>e.scrollTop=0);
    }
    await page.setViewportSize({width:1366,height:820});
    const iconShapes=await page.locator('.equipment-filter-rail .irlix-filter-rail__filters .irlix-filter-rail__icon svg').evaluateAll(xs=>xs.map(x=>x.innerHTML));
    assert.equal(iconShapes.length,6,'all filter icons rendered');
    assert.equal(new Set(iconShapes).size,6,'filter icons have distinct actual paths');
    await page.getByRole('button',{name:'+ Техника'}).click();
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
    assert.ok(await page.locator('.equipment-table tbody tr').count()>1,'holder full-name search matches assigned devices');
    await holderSearch.fill('');
    await page.getByRole('button',{name:'EQ-001'}).click();
    await page.getByRole('button',{name:'Удалить',exact:true}).waitFor();
    page.once('dialog',d=>d.accept());
    await page.getByRole('button',{name:'Удалить',exact:true}).click();
    await page.waitForFunction(()=>document.querySelectorAll('.equipment-table tbody tr').length===64);
    assert.equal(await page.locator('.equipment-table tbody tr').count(),64,'delete removes asset from registry');
    assert.deepEqual(errors,[]);
    console.log('Equipment synthetic Chromium PASS: header alignment/scroll, panel shape, models, icons, labels, FIO search and deletion');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
