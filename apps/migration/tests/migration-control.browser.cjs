// All identities and API responses in this browser scenario are synthetic.
const assert=require('node:assert/strict');const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--use-gl=angle','--use-angle=swiftshader','--disable-gpu']});const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));const embedded=process.env.MIGRATION_EMBEDDED==='1';const routePath=embedded?'/system/migration/control/':'/migration/control/';const storagePrefix=embedded?'irlix.system.auth':'irlix.migration.auth';const base=process.env.MIGRATION_PREVIEW||'http://127.0.0.1:5173/';const issuer=new URL('/keycloak/auth/realms/irlix',base).href;
await page.addInitScript(({issuer,storagePrefix})=>{sessionStorage.setItem(storagePrefix+'.tokens',JSON.stringify({access_token:`e30.${btoa(JSON.stringify({iss:issuer,exp:Math.floor(Date.now()/1000)+3600,name:'Synthetic Operator'}))}.synthetic`}));Object.defineProperty(navigator,'clipboard',{value:{writeText:()=>Promise.reject(Error('Denied'))}});},{issuer,storagePrefix});let status='running',offline=false,forbidden=false,accessOffline=false,slowReport=false,reports=0,active=0,maxActive=0;const calls=[];
const op=id=>({id,action:'migrate',scope:'clients',services:['clients'],status:id==='99'?'cancelled':status,phase:'migrate',updated_at:status,started_at:1700000000,data_outcome:'partial',cancel_reason:id==='99'?'Synthetic manual stop':'',runs:[{id:3,service:'clients',mode:'migrate',status}],snapshot_ids:{clients:'55'}});
const report=id=>({operation:op(id),chat_summary:`Synthetic report ${id}`,implementation_revision:'synthetic-revision',
 coverage:{expected_unprocessed_rows:108,semantic_complete:false},
 service_evidence:[{service:'clients',status:'partial',write_processed:133,write_total:241,evidence_status:'partial'}],
 inventory:[{service:'clients',complete:true,tables:[
  {name:'users',total:201,expectation:{status:'expected'},columns:[{name:'unknown_flag',type:'text',filled:null,null_count:3,mapping:{fate:'unmapped'},values:[{value:'new_status',count:198}]}]},
  {name:'cache',total:40,expectation:{status:'expected'},columns:[{name:'key',type:'text',filled:40,null_count:0,mapping:{fate:'direct'},values:[]}]}
 ]}],
 root_cause_groups:[...Array.from({length:31},(_,i)=>({service:'clients',table:'users',code:`ROOT_${i}`,severity:'error',count:201,affected_rows:198,message:`Synthetic root ${i}`})),
 {service:'clients',table:'cache',code:'CACHE_ERR',severity:'error',count:7,affected_rows:7,message:'Synthetic cache warning'}],
 runs:[{service:'clients',mode:'migrate',status,run_id:3,tables:[
  {table_name:'users',total:201,processed_count:100,source_outcomes:{domain_written:10,metadata_only:12,unprocessed:101}},
  {table_name:'cache',total:40,processed_count:33,source_outcomes:{domain_written:33,unprocessed:7}}
 ]}]});
await page.route('**/.well-known/openid-configuration',r=>r.fulfill({json:{issuer,authorization_endpoint:issuer+'/auth',token_endpoint:issuer+'/token'}}));await page.route('**/api/employees/access/me',r=>r.fulfill(accessOffline?{status:503,json:{message:'Synthetic access service unavailable'}}:{json:{data:{roles:forbidden?[]:['platform-admin'],services:{migration:!forbidden}}}}));await page.route('**/api/migration/**',async r=>{const req=r.request(),url=new URL(req.url()),path=url.pathname.replace('/api/migration','');calls.push({path,query:url.search,body:req.postDataJSON()});if(path==='/console/state')return r.fulfill(offline?{status:503,json:{message:'Synthetic API offline'}}:{json:{data:{history:[],snapshots:{},operation:null}}});if(path==='/state')return r.fulfill({json:{data:{modules:[{key:'clients',title:'Clients',status:'implemented',dependencies:[],connection:{host:'synthetic-db',database:'synthetic',username:'synthetic',password_set:true,readonly_acknowledged:true,verified_at:'2026-01-01T00:00:00Z'}}]}}});if(path==='/control/state'){active++;maxActive=Math.max(active,maxActive);await new Promise(resolve=>setTimeout(resolve,60));active--;return r.fulfill(offline?{status:503,json:{message:'Synthetic offline'}}:{json:{data:{operation:op('100'),history:[op('100'),op('99')],restore_history:[],console_history:[]}}});}if(path.includes('/report')){reports++;if(slowReport&&!url.search)await new Promise(resolve=>setTimeout(resolve,4800));if(url.search)await new Promise(resolve=>setTimeout(resolve,150));return r.fulfill({json:{data:report(path.split('/')[3])}});}if(path==='/control/start')status='running';if(path==='/control/cancel')status='cancelled';return r.fulfill({json:{data:{operation:op('100')}}});});
await page.goto(new URL(routePath+'?service=clients',base).href);
await page.getByText('Весь перенос #100',{exact:true}).waitFor();
assert.equal(await page.locator('.irlix-app-shell').count(),1,'one host shell');
assert.equal(await page.getByRole('tab').count(),0,'control is a page, not a tab');
if(embedded)assert.equal(await page.locator('.migration-embedded').count(),1,'actual embedded facade');
assert.equal(await page.getByRole('button',{name:'Новый перенос',exact:true}).isDisabled(),true);
assert.equal(await page.getByRole('button',{name:'Открыть пульт переноса'}).count(),0,'redundant console link removed from control header');
const toolbar=await page.evaluate(()=>{
 const header=document.querySelector('main.mctrl .ui-page-header').getBoundingClientRect();
 const picker=document.querySelector('.mctrl-operation-picker .ui-search-select').getBoundingClientRect();
 const launch=[...document.querySelectorAll('.mctrl-toolbar-actions button')].find(x=>x.textContent.trim()==='Новый перенос').getBoundingClientRect();
 const table=document.querySelector('.mctrl-tree-scroll').getBoundingClientRect();
 return {pickerWidth:picker.width,pickerY:picker.y,launchY:launch.y,tableGap:table.top-header.bottom,actions:!!document.querySelector('main.mctrl .ui-page-header .mctrl-toolbar-actions')};
});
assert.ok(toolbar.actions,'operation picker and action buttons are in the page header');
assert.ok(toolbar.pickerWidth<=290,'operation picker is compact rather than full width');
assert.ok(Math.abs(toolbar.pickerY-toolbar.launchY)<15,'new transfer stays in the same desktop row as current operation');
assert.ok(toolbar.tableGap>=0&&toolbar.tableGap<90,'no oversized gap above transfer table');
assert.equal(await page.locator('.mctrl-tree-root').count(),1,'one operation root');
assert.equal(await page.locator('.mctrl-tree-service').count(),1,'service row below root');
assert.deepEqual((await page.locator('.mctrl-tree-table thead th').allTextContents()).map(v=>v.trim()),
 ['Перенос / сервис / сущность','Статус','Обработано / всего','Без результата','Ошибки','Предупреждения','Полнота','Действия']);
assert.equal(await page.locator('.mctrl-tree-table tbody tr.mctrl-tree-entity').count(),0,'entities collapsed initially');
assert.equal(await page.locator('.mctrl-tree-table .mctrl-tree-service td').count(),7,'service metrics share eight table columns');
const aligned=await page.evaluate(()=>{
 const x=selector=>document.querySelector(selector).getBoundingClientRect().left;
 return Math.abs(x('.mctrl-tree-root td:nth-child(3)')-x('.mctrl-tree-service td:nth-child(3)'))<1;
});
assert.equal(aligned,true,'root and service progress metrics stay in the same column');
assert.equal(await page.getByRole('button',{name:'Запустить перенос: Клиенты'}).isDisabled(),true,'running operation locks single-service launch');
// A service reports both the table data and a progress bar when totals are known.
await page.getByRole('button',{name:'Развернуть Клиенты'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),2,'two synthetic entity rows');
assert.equal(await page.getByRole('button',{name:'Скрытые строки: Клиенты'}).count(),0,'do not show empty hidden-rows action');
assert.equal(await page.getByRole('progressbar',{name:'Прогресс сервиса Клиенты'}).count(),1);
assert.equal(await page.getByRole('progressbar',{name:'Прогресс всего переноса'}).count(),1);
assert.equal(await page.getByRole('progressbar',{name:'Прогресс сущности cache'}).count(),1);
assert.match(await page.locator('.mctrl-tree-service td:nth-child(3)').innerText(),/133 \/ 241/);
assert.match(await page.locator('.mctrl-tree-root td:nth-child(4)').innerText(),/108/);
await page.getByRole('button',{name:'Скрыть строку Клиенты / cache'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),1,'cache removed from visible rows');
assert.match(await page.locator('.mctrl-tree-service td:nth-child(3)').innerText(),/100 \/ 201/,'cache excluded from service totals');
assert.match(await page.locator('.mctrl-tree-service td:nth-child(4)').innerText(),/101/,'cache excluded from unprocessed');
assert.match(await page.locator('.mctrl-tree-root td:nth-child(4)').innerText(),/101/,'root aggregation excludes hidden cache');
assert.match(await page.locator('.mctrl-tree-service td:nth-child(5)').innerText(),/6231/,'service excludes cache error count');
assert.equal(await page.getByRole('progressbar',{name:'Прогресс сервиса Клиенты'}).getAttribute('value'),'50');
assert.equal(await page.getByRole('progressbar',{name:'Прогресс всего переноса'}).getAttribute('value'),'50');
// On refresh the report is fetched again, but visibility remains local and stable.
await page.reload();
await page.getByText('Весь перенос #100',{exact:true}).waitFor();
await page.getByRole('button',{name:'Развернуть Клиенты'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),1,'hidden cache survives full reload');
assert.match(await page.locator('.mctrl-tree-service td:nth-child(3)').innerText(),/100 \/ 201/);
await page.waitForTimeout(1700);
assert.equal(await page.locator('.mctrl-tree-entity').count(),1,'hidden cache survives state polling');
await page.getByRole('button',{name:'Скрыть строку Клиенты / users'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),0,'all individual entity rows may be hidden');
await page.getByRole('button',{name:'Скрытые строки: Клиенты'}).click();
assert.equal(await page.getByRole('button',{name:/Показать строку/}).count(),2);
await page.getByRole('button',{name:'Показать все'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),2,'bulk restore returns all rows');
await page.getByRole('button',{name:'Закрыть',exact:true}).last().click();
assert.equal(await page.getByRole('button',{name:'Скрытые строки: Клиенты'}).count(),0,'empty action disappears after restore');
await page.getByRole('button',{name:'Скрыть строку Клиенты / cache'}).click();
await page.getByRole('button',{name:'Скрытые строки: Клиенты'}).click();
await page.getByText('Скрытые строки · Клиенты').last().waitFor();
assert.equal(await page.getByRole('button',{name:'Показать строку cache'}).count(),1);
const fontSize=await page.evaluate(()=>{
 const table=document.querySelector('.mctrl-tree-table tbody td');
 const dialog=document.querySelector('.mctrl-hidden-list p');
 return [getComputedStyle(table).fontFamily,getComputedStyle(table).fontSize,
   getComputedStyle(dialog).fontFamily,getComputedStyle(dialog).fontSize];
});
assert.equal(fontSize[0],fontSize[2],'all fonts including Teleported drawer share family');
assert.equal(fontSize[1],fontSize[3],'all fonts including Teleported drawer share size');
await page.getByRole('button',{name:'Показать строку cache'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),2,'restored entity returns to table');
assert.match(await page.locator('.mctrl-tree-service td:nth-child(3)').innerText(),/133 \/ 241/);
assert.match(await page.locator('.mctrl-tree-root td:nth-child(4)').innerText(),/108/);
await page.getByRole('button',{name:'Закрыть',exact:true}).last().click();
await page.getByRole('button',{name:'Развернуть Клиенты'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),0,'collapse before diagnostic checks');
const before=reports;status='partial';await page.waitForTimeout(3500);
assert.ok(reports>before,'terminal report is fetched');
await page.locator('.mctrl-tree-root td:nth-child(5) button').click();
await page.getByText('Synthetic root 0').first().waitFor();
assert.equal(await page.locator('.mctrl-tree-issue').count(),32,'full error groups available on click');
assert.ok(calls.some(c=>c.path==='/control/operations/100/report'&&c.query==='?download=1'),'full details loaded lazily');
await page.getByRole('button',{name:'Закрыть',exact:true}).last().click();
await page.getByRole('button',{name:'Скопировать диагностический текст'}).click();
assert.equal(await page.getByRole('textbox',{name:'Диагностический текст'}).inputValue(),'Synthetic report 100');
await page.getByRole('button',{name:'Закрыть',exact:true}).last().click();
await page.getByRole('button',{name:'Развернуть Клиенты'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),2,'entity rows inserted in same table');
assert.equal(await page.evaluate(()=>{
 const x=selector=>document.querySelector(selector).getBoundingClientRect().left;
 return Math.abs(x('.mctrl-tree-service td:nth-child(3)')-x('.mctrl-tree-entity td:nth-child(3)'))<1;
}),true,'entity processing metric aligns with service');
await page.locator('.mctrl-tree-entity').filter({hasText:'users'}).getByRole('button',{name:'Поля · 1'}).click();
await page.getByText(/unknown_flag/).first().waitFor();
await page.getByRole('button',{name:'Закрыть',exact:true}).last().click();
await page.getByRole('button',{name:'История операций'}).click();
await page.getByRole('option',{name:/#99/}).click();
await page.getByText('Весь перенос #99',{exact:true}).waitFor();
assert.equal(await page.locator('.mctrl-tree-service').count(),1,'historical service selected');
await page.getByRole('button',{name:'Развернуть Клиенты'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),2,'historical version uses shared per-service row visibility');
await page.getByRole('button',{name:'Скрыть строку Клиенты / cache'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),1);
await page.getByRole('button',{name:'Скрытые строки: Клиенты'}).click();
await page.getByRole('button',{name:'Показать все'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),2);
await page.getByRole('button',{name:'Закрыть',exact:true}).last().click();
const downloadPromise=page.waitForEvent('download');
await page.getByRole('button',{name:'Скачать полный JSON'}).click();
await page.getByRole('button',{name:'История операций'}).click();
await page.getByRole('option',{name:'Текущая операция',exact:true}).click();
const download=await downloadPromise;
assert.equal(download.suggestedFilename(),'migration-control-99.json');
await page.getByText('Весь перенос #100',{exact:true}).waitFor();
await page.waitForTimeout(150);
await page.getByRole('button',{name:'Развернуть Клиенты'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),2,'returning to active version preserves visibility preferences');
await page.getByRole('button',{name:'Скрыть строку Клиенты / cache'}).click();
assert.equal(await page.locator('.mctrl-tree-entity').count(),1);
assert.ok(calls.some(c=>c.path==='/control/operations/99/report'&&c.query==='?download=1'));
await page.getByRole('button',{name:'Новый перенос',exact:true}).click();
await page.getByRole('button',{name:'Подтвердить и запустить'}).click();
await page.getByRole('button',{name:'Остановить',exact:true}).click();
await page.getByRole('textbox',{name:'Причина остановки'}).fill('Synthetic stop reason');
await page.getByRole('button',{name:'Остановить после этапа'}).click();
await page.getByText(/Остановка принята/).waitFor();
await page.waitForTimeout(200);
assert.ok(calls.some(c=>c.path==='/control/start'&&c.body.scope==='all'&&c.body.confirm===true));
assert.ok(calls.some(c=>c.path==='/control/cancel'&&c.body.reason==='Synthetic stop reason'));
await page.getByRole('button',{name:'Запустить перенос: Клиенты'}).click();
await page.getByText('Запуск переноса сервиса').waitFor();
await page.getByRole('button',{name:'Запустить перенос',exact:true}).click();
await page.waitForTimeout(150);
assert.ok(calls.some(c=>c.path==='/control/start'&&c.body.scope==='clients'&&c.body.confirm===true),'rerun uses existing scoped control/start');
// A stalled full diagnostic response must never stall lightweight status polling.
const pollsBeforeSlowReport=calls.filter(c=>c.path==='/control/state').length;
slowReport=true;status='queued';
await page.waitForTimeout(4200);
assert.ok(calls.filter(c=>c.path==='/control/state').length>=pollsBeforeSlowReport+2,'state polling continues while report response is slow');
slowReport=false;status='running';
const treeTopBefore=await page.locator('.mctrl-tree-scroll').evaluate(el=>el.getBoundingClientRect().top);
offline=true;await page.getByText(/Нет связи с Migration/).waitFor({timeout:8000});await page.locator('.mctrl-connection-loader').waitFor();assert.ok(await page.evaluate(()=>document.querySelector('.ui-page-header .mctrl-connection-loader')!==null),'offline spinner stays in the top toolbar, not in a standalone row');assert.ok(await page.evaluate(()=>{const t=document.querySelector('.mctrl-status-toast');return t&&getComputedStyle(t).position==='fixed';}),'network error floats without moving the page');assert.ok(Math.abs((await page.locator('.mctrl-tree-scroll').evaluate(el=>el.getBoundingClientRect().top))-treeTopBefore)<2,'offline message does not shift transfer table');assert.equal(await page.getByRole('button',{name:'Остановить',exact:true}).isEnabled(),true,'cancel stays available when GET polling fails');await page.getByRole('button',{name:'Остановить',exact:true}).click();await page.getByRole('textbox',{name:'Причина остановки'}).fill('Synthetic safe stop during offline GET');await page.getByRole('button',{name:'Остановить после этапа'}).click();await page.waitForTimeout(180);assert.ok(calls.some(c=>c.path==='/control/cancel'&&c.body.reason==='Synthetic safe stop during offline GET'),'cancel POST works even if state GET is temporarily offline');assert.match(await page.locator('.mctrl-tree-root .mctrl-tree-title').innerText(),/Весь перенос\s*#100/);assert.equal(maxActive,1);await page.screenshot({path:process.env.MIGRATION_CONTROL_SCREENSHOT||'/tmp/migration-control-desktop.png',fullPage:true});await page.setViewportSize({width:390,height:844});await page.screenshot({path:'/tmp/migration-control-mobile.png',fullPage:true});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'mobile document does not overflow');
assert.ok(await page.evaluate(()=>{const el=document.querySelector('.mctrl-tree-scroll');return el.scrollWidth>el.clientWidth;}),'mobile table scrolls inside its own container');offline=false;await page.locator('.mctrl-connection-loader').waitFor({state:'hidden',timeout:8000});if(embedded)await page.goto(new URL('/system/migration/?service=clients',base).href);else await page.locator('.irlix-app-sidebar nav.icon-nav').getByRole('button',{name:'Пульт переноса'}).click();
await page.getByRole('navigation',{name:'Очередь переноса'}).waitFor();
assert.equal(await page.locator('main.mctrl').count(),0,'control unmounts on the legacy console URL');
assert.ok(new URL(page.url()).pathname.endsWith('/migration/'),'legacy console has its own URL');
assert.equal(new URL(page.url()).searchParams.get('service'),'clients','other query parameters survive');
assert.equal(await page.locator('.irlix-app-shell').count(),1,'legacy console uses host shell');
await page.getByRole('button',{name:'Настройки БД · Клиенты',exact:true}).click();
await page.getByRole('textbox',{name:'Хост',exact:true}).waitFor();
assert.equal(await page.getByRole('textbox',{name:'Хост',exact:true}).inputValue(),'synthetic-db');
await page.getByRole('button',{name:'Закрыть',exact:true}).last().click();
await page.getByRole('button',{name:'Бэкапы · Клиенты',exact:true}).click();
await page.getByText('Бэкапов пока нет.',{exact:true}).waitFor();
await page.getByRole('button',{name:'Закрыть',exact:true}).last().click();
assert.ok(calls.some(c=>c.path==='/console/state')&&calls.some(c=>c.path==='/state'),'legacy API remains available');
offline=true;await page.locator('.mc-backend-loader .ui-loader').waitFor({timeout:8000});assert.match(await page.locator('.mc-backend-loader').innerText(),/Ожидаем восстановления Migration API/);offline=false;await page.locator('.mc-backend-loader').waitFor({state:'hidden',timeout:8000});
await page.goBack();await page.locator('main.mctrl').waitFor();
assert.ok(new URL(page.url()).pathname.endsWith('/migration/control/'),'browser history restores control URL');
assert.equal(new URL(page.url()).searchParams.get('service'),'clients');await page.goForward();
await page.getByRole('navigation',{name:'Очередь переноса'}).waitFor();
assert.ok(new URL(page.url()).pathname.endsWith('/migration/'),'browser forward restores console URL');
forbidden=true;await page.reload();await page.getByText(embedded?'Доступ разрешён только администратору платформы.':'Требуется роль platform-admin',{exact:true}).waitFor();if(!embedded){forbidden=false;accessOffline=true;await page.reload();await page.getByText('Ожидаем восстановления проверки доступа…').waitFor({timeout:9000});assert.equal(await page.locator('#auth-loading .ui-loader').count(),1);accessOffline=false;await page.getByRole('navigation',{name:'Очередь переноса'}).waitFor({timeout:9000});}assert.deepEqual(errors,[]);await browser.close();console.log(`PASS Migration control ${embedded?'actual System embedded route':'standalone'} synthetic browser, mobile and auth scenarios`);})().catch(e=>{console.error(e);process.exit(1)});
