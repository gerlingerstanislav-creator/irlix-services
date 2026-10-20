// Synthetic Chromium contract for the shared flat registry. No real identities or business data.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({
    headless:true, executablePath:process.env.CHROMIUM_EXECUTABLE,
    args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']
  });
  try {
    const page = await browser.newPage({ viewport:{ width:1366, height:900 } });
    const errors = [];
    page.on('pageerror',e=>errors.push(e.message));
    const base = process.env.DESIGN_SYSTEM_PREVIEW || 'http://127.0.0.1:5196';
    const issuer = base+'/keycloak/auth/realms/irlix';
    await page.addInitScript(issuer => sessionStorage.setItem('irlix.platform.auth.tokens',JSON.stringify({
      access_token:'e30.'+btoa(JSON.stringify({
        iss:issuer,exp:Math.floor(Date.now()/1000)+3600,name:'Synthetic Administrator'
      }))+'.synthetic'
    })), issuer);
    await page.route('**/.well-known/openid-configuration', route => route.fulfill({
      json:{issuer,authorization_endpoint:issuer+'/auth',token_endpoint:issuer+'/token'}
    }));
    await page.route('**/api/**',route=>route.fulfill({
      json:{data:{services:{'design-system':true},roles:['platform-admin']}}
    }));

    await page.goto(base+'/design-system/');
    const registry = page.locator('.ui-registry-table').first();
    await registry.waitFor({state:'visible',timeout:15000});
    await registry.scrollIntoViewIfNeeded();
    const scroll = registry.locator('.ui-registry-table__scroll');
    const header = registry.locator('.ui-registry-table__header');
    const headerViewport = registry.locator('.ui-registry-table__header-viewport');

    for(const width of [1366,1024,420]){
      await page.setViewportSize({width,height:900});
      await registry.scrollIntoViewIfNeeded();
      await page.waitForFunction(()=>{
        const root=document.querySelector('.ui-registry-table');
        if(!root)return false;
        const v=root.querySelector('.ui-registry-table__scroll');
        const h=root.querySelector('.ui-registry-table__header');
        return Math.abs(parseFloat(getComputedStyle(h).paddingRight)-(v.offsetWidth-v.clientWidth))<1.2;
      });
      const dimensions=await registry.evaluate(root=>{
        const v=root.querySelector('.ui-registry-table__scroll');
        const h=root.querySelector('.ui-registry-table__header');
        const hr=[...h.querySelectorAll('th')].map(x=>x.getBoundingClientRect());
        const br=[...v.querySelector('tbody tr').children].map(x=>x.getBoundingClientRect());
        return {
          width:root.clientWidth,marginLeft:getComputedStyle(root).marginLeft,
          marginRight:getComputedStyle(root).marginRight,paddingLeft:getComputedStyle(root).paddingLeft,
          paddingRight:getComputedStyle(root).paddingRight,
          border:getComputedStyle(root).borderLeftWidth,radius:getComputedStyle(root).borderTopLeftRadius,
          headerY:hr[0].top,bodyY:br[0].top,scroll: v.scrollHeight>v.clientHeight,
          gutter:v.offsetWidth-v.clientWidth,headerPadding:parseFloat(getComputedStyle(h).paddingRight),
          maxDx:Math.max(...hr.map((r,i)=>Math.abs(r.left-br[i].left))),
          maxDw:Math.max(...hr.map((r,i)=>Math.abs(r.width-br[i].width)))
        };
      });
      assert.equal(dimensions.marginLeft,'0px');
      assert.equal(dimensions.marginRight,'0px');
      assert.equal(dimensions.paddingLeft,'0px');
      assert.equal(dimensions.paddingRight,'0px');
      assert.equal(dimensions.border,'0px');
      assert.equal(dimensions.radius,'0px');
      assert.equal(dimensions.scroll,true,'expected vertical row scrolling at width '+width);
      assert.ok(dimensions.bodyY>=dimensions.headerY+10,'header must be above rows at '+width);
      assert.ok(dimensions.maxDx<2 && dimensions.maxDw<2,'header/body columns match at '+width+': '+JSON.stringify(dimensions));
      await scroll.evaluate(el=>{el.scrollTop=150;});
      const after=await header.locator('th').first().evaluate(el=>el.getBoundingClientRect().top);
      assert.ok(Math.abs(after-dimensions.headerY)<1,'vertical scrolling must not move header at '+width);
      await scroll.evaluate(el=>{el.scrollTop=0;});
      if(width===420){
        await scroll.evaluate(el=>{el.scrollLeft=90;});
        await page.waitForFunction(()=>{
          const root=document.querySelector('.ui-registry-table');
          return Math.abs(root.querySelector('.ui-registry-table__header-viewport').scrollLeft-root.querySelector('.ui-registry-table__scroll').scrollLeft)<1;
        });
        const xDiff=await registry.evaluate(root=>{
          const h=root.querySelector('.ui-registry-table__header th').getBoundingClientRect().left;
          const v=root.querySelector('.ui-registry-table__scroll tbody td').getBoundingClientRect().left;
          return Math.abs(h-v);
        });
        assert.ok(xDiff<2,'horizontal scrolling must move header and body together');
      }
    }
    await page.setViewportSize({width:1366,height:900});
    await scroll.evaluate(el=>{
      const rows=[...el.querySelectorAll('tbody tr')];
      rows.slice(1).forEach(row=>row.remove());
      el.scrollTop=0;
    });
    await page.waitForFunction(()=>{
      const root=document.querySelector('.ui-registry-table');
      const viewport=root.querySelector('.ui-registry-table__scroll');
      const head=root.querySelector('.ui-registry-table__header');
      return viewport.scrollHeight<=viewport.clientHeight &&
        Math.abs(parseFloat(getComputedStyle(head).paddingRight))<1;
    });
    const noScroll=await registry.evaluate(root=>{
      const header=root.querySelector('.ui-registry-table__header th').getBoundingClientRect();
      const body=root.querySelector('.ui-registry-table__scroll tbody td').getBoundingClientRect();
      return Math.max(Math.abs(header.left-body.left),Math.abs(header.width-body.width));
    });
    assert.ok(noScroll<2,'columns stay aligned when scrollbar disappears');
    const preview=page.locator('.ds-loader-preview');
    await preview.scrollIntoViewIfNeeded();
    const loader=preview.getByRole('status',{name:'Загрузка записей'});
    await loader.waitFor({state:'visible'});
    const overlayState=await loader.evaluate(el=>{
      const parent=el.parentElement.getBoundingClientRect(),rect=el.getBoundingClientRect();
      return {position:getComputedStyle(el).position,top:Math.abs(parent.top-rect.top),height:Math.abs(parent.height-rect.height),reducedMotionRule:[...document.styleSheets].some(sheet=>{
        try{return [...sheet.cssRules].some(rule=>rule.conditionText?.includes('prefers-reduced-motion'));}catch{return false;}
      })};
    });
    assert.equal(overlayState.position,'absolute','preview overlay does not enter normal flow');
    assert.ok(overlayState.top<4 && overlayState.height<4,'overlay covers demo viewport inside the 1px panel border: '+JSON.stringify(overlayState));
    const inline=page.locator('#section-loaders .ui-loader:not(.ui-loader--overlay)');
    assert.equal(await inline.count(),2,'design system showcases inline and compact loaders');
    assert.equal(await inline.last().locator('.ui-loader__label').textContent(),'Обновляем…');
    assert.deepEqual(errors,[]);
    console.log('Shared UiRegistryTable Chromium PASS: full-bleed, no border/radius, body-only scroll, fixed aligned header, horizontal sync, no-scroll gutter');
  } finally {
    await browser.close();
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
