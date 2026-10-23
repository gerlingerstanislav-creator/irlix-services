// CI-only comparison of real, locally installed open-licensed Cyrillic font files.
// CI artifacts contain screenshots and QA JSON, not font binaries.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const pkgRoot = path.resolve(__dirname,'../node_modules');
const out = path.resolve(__dirname,'../qa-typography');
const requests = [
 {id:'Onest',name:'Onest',pkg:'@fontsource-variable/onest',match:/cyrillic-wght-normal\.woff2$/i,weight:'100 900'},
 {id:'Manrope',name:'Manrope',pkg:'@fontsource-variable/manrope',match:/cyrillic-wght-normal\.woff2$/i,weight:'200 800'},
 {id:'PlexRegular',name:'IBM Plex Sans',pkg:'@fontsource/ibm-plex-sans',match:/cyrillic-400-normal\.woff2$/i,weight:'400'},
 {id:'PlexBold',name:'IBM Plex Sans',pkg:'@fontsource/ibm-plex-sans',match:/cyrillic-700-normal\.woff2$/i,weight:'700'}
];
function readFont(x){
 const folder=path.join(pkgRoot,...x.pkg.split('/'),'files');
 const files=fs.readdirSync(folder).filter(n=>x.match.test(n));
 assert.equal(files.length,1,'expected one Cyrillic WOFF2 in '+folder+': '+files.join(','));
 return {...x,filename:files[0],data:fs.readFileSync(path.join(folder,files[0])).toString('base64')};
}
async function main(){
 const fonts=requests.map(readFont);
 let svg=fs.readFileSync(path.resolve(__dirname,'../src/assets/irlix-site-wordmark.svg'),'utf8');
 assert.equal((svg.match(/<path\b/g)||[]).length,6);
 svg=svg.replace(/fill="white"/g,'fill="#142820"');
 const logo='data:image/svg+xml;base64,'+Buffer.from(svg).toString('base64');
 const text='Инженеры, которые вникают в задачу.';
 const bodyText='Подключаем инженерные компетенции к задачам бизнеса. Разбираемся в контексте, предлагаем подходящий формат сотрудничества.';
 const variants=[
  ['a','ONEST — ЕДИНАЯ СИСТЕМА'],
  ['b','MANROPE + ONEST'],
  ['c','IBM PLEX SANS']
 ];
 const cards=variants.map(v=>'<article class="card '+v[0]+'"><div class="small">'+v[1]+'</div><h2>'+text+'</h2><div class="line"></div><p>'+bodyText+'</p><div class="nums">Ёё ЖЩ ЦЙ · 0123456789</div></article>').join('');
 const css=[
 '*{box-sizing:border-box}body{margin:0;background:#fbfcfa;color:#142820;font-family:Arial,sans-serif}',
 '.wrap{max-width:1440px;margin:auto;padding:44px 48px 54px}.top{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #dae6db;padding:0 0 22px;gap:18px}',
 '.logo{width:160px;max-width:60%;height:auto}.note,.small{font:700 12px Arial,sans-serif;letter-spacing:.09em;color:#087c5a}.note{text-align:right}',
 '.eyebrow{margin:45px 0 15px;color:#087c5a;font:700 12px Arial,sans-serif;letter-spacing:.1em}',
 'h1{font-size:40px;line-height:1.2;margin:0 0 17px;letter-spacing:-.025em}.intro{font-size:16px;max-width:930px;line-height:1.7;color:#4f6559}',
 '.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-top:33px}',
 '.card{padding:26px 21px 30px;background:#fff;border:1px solid #dae6db;border-top:4px solid #008e8c;min-width:0;min-height:410px}',
 '.card h2{font-size:31px;line-height:1.23;letter-spacing:-.04em;font-weight:700;margin:33px 0 24px}',
 '.card p{color:#4f6559;line-height:1.68;font-size:16px;font-weight:400}.nums{font-weight:400;font-size:18px}',
 '.a h2,.a p,.a .nums{font-family:"Onest"}.b h2{font-family:"Manrope"}.b p,.b .nums{font-family:"Onest"}',
 '.c h2,.c p,.c .nums{font-family:"IBM Plex Sans"}',
 '.line{height:3px;background:#47d7ac;width:60px;margin-bottom:24px}.footer{border-top:1px solid #dae6db;margin-top:36px;padding-top:20px;color:#637b6a;font:12px Arial,sans-serif}',
 '@media(max-width:860px){.wrap{padding:26px 18px 36px}.grid{grid-template-columns:1fr;gap:14px}.card{min-height:0;padding:21px}.card h2{font-size:29px;margin:17px 0}h1{font-size:32px}.eyebrow{margin-top:28px}.note{max-width:160px}}'
 ].join('');
 const html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IRLIX / типографика</title><style>'+css+'</style></head><body><main class="wrap"><div class="top"><img class="logo" src="'+logo+'" alt="IRLIX"><div class="note">ЧЕРНОВИК · НЕ УТВЕРЖДЕНО</div></div><div class="eyebrow">ТИПОГРАФИКА / РЕАЛЬНЫЕ ШРИФТЫ</div><h1>Один текст. Три визуальных решения.</h1><p class="intro">Кандидаты на общую типографику бренда. Одинаковые сообщения, отступы и светлый фон; различается только гарнитура. Исходная двухцветная X сохранена.</p><section class="grid">'+cards+'</section><div class="footer">IRLIX • Проба технической отрисовки, а не официально утверждённый брендбук. Результат без файлов шрифтов.</div></main></body></html>';
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:900},deviceScaleFactor:1});
  await page.setContent(html,{waitUntil:'domcontentloaded'});
  const loaded=await page.evaluate(async fonts=>{
   const result=[];
   for(const f of fonts){
    const face=new FontFace(f.name,'url(data:font/woff2;base64,'+f.data+')',{weight:f.weight});
    await face.load();
    document.fonts.add(face);
    if(face.status!=='loaded')throw Error(f.id+' could not load');
    result.push({name:f.id,weight:f.weight,status:face.status});
   }
   await document.fonts.ready;
   for(const [name,weight] of [['Onest',700],['Manrope',700],['IBM Plex Sans',400],['IBM Plex Sans',700]]){
    if(!document.fonts.check(weight+' 32px "'+name+'"','Инженеры и технологии'))throw Error('font unavailable: '+name);
   }
   return result;
  },fonts);
  fs.mkdirSync(out,{recursive:true});
  const screenshots=[];
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:900});
   const m=await page.evaluate(()=>({width:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth}));
   assert.ok(m.scrollWidth<=m.width+1,'horizontal overflow '+width);
   const file='irlix-real-fonts-'+width+'.png';
   await page.screenshot({path:path.join(out,file),fullPage:true});
   screenshots.push({file,width,document:m});
  }
  const report={result:'PASS',status:'PROPOSED, NOT APPROVED',source:'real npm fontsource WOFF2 loaded in Chromium, not fallback',loaded,fonts:fonts.map(f=>({package:f.pkg,filename:f.filename})),screenshots};
  fs.writeFileSync(path.join(out,'verification.json'),JSON.stringify(report,null,2));
  console.log('TYPOGRAPHY ACTUAL FONTS PASS: Onest, Manrope, IBM Plex Sans Cyrillic; 1440 and 390 px screenshots');
 }finally{await browser.close()}
}
main().catch(e=>{console.error(e);process.exitCode=1});
