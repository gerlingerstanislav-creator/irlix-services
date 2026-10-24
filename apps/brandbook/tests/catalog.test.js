import test from 'node:test';
import assert from 'node:assert/strict';
import { articles, canonicalPath, filterArticles, sectionForPath, sections, statusInfo } from '../src/catalog.js';
import { logoDownloads, tokenDownloads } from '../src/downloads.js';
import { logoGuidelines, digitalPrinciples, communicationDirections, applicationPrinciples } from '../src/guidelines.js';
import { brandVoice, aiEngineeringPillars, aiTrustPrinciples } from '../src/marketing.js';
import { toneExamples, carrierExamples } from '../src/brandSamples.js';
import { serviceGroups, getVisibleServiceGroups } from '../../../packages/ui/src/serviceCatalog.js';

test('all nine sections have unique stable routes',()=>{
 assert.equal(sections.length,9);
 assert.equal(new Set(sections.map(section=>section.id)).size,sections.length);
 for(const section of sections)assert.equal(sectionForPath(canonicalPath(section.id)),section.id);
 assert.equal(sectionForPath('/brandbook/'),'identity');
 assert.equal(sectionForPath('/brandbook/unknown/'),'identity');
});
test('every article is traceable or explicitly pending',()=>{
 for(const article of articles){
  assert.ok(sections.some(section=>section.id===article.section));
  assert.ok(statusInfo[article.status]);
  if(article.status==='source'){
   assert.match(article.source?.url||'',/^https:\/\//);
   assert.ok(article.checked);
  }else if(article.status==='approved'){
   assert.equal(article.source,null);
   assert.ok(['digital','marketing'].includes(article.approval?.scope));
   assert.equal(article.approval?.date,'24.10.2026');
   assert.doesNotMatch(article.description,/гарантируем|100\s*%|2700\s*₽/i);
  }else assert.equal(article.source,null);
 }
 assert.deepEqual(articles.filter(a=>a.status==='approved').map(a=>a.id).sort(),['asset-library','font','logo-rules','palette','tone','version-1','version-2']);
});
test('search works across all sections and does not match unrelated queries',()=>{
 assert.ok(filterArticles(articles,'Финтех','all').length>0);
 assert.ok(filterArticles(articles,'хабр','all').some(article=>article.id==='habr-message'));
 assert.equal(filterArticles(articles,'unlikely-super-rare-search', 'all').length,0);
 assert.ok(filterArticles(articles,'','logo').every(article=>article.section==='logo'));
});

test('logo download package preserves exact authorized artwork',async()=>{
 const { readFileSync } = await import('node:fs');
 const logo = readFileSync(new URL('../src/assets/irlix-site-wordmark.svg',import.meta.url),'utf8');
 assert.equal((logo.match(/<path\b/g)||[]).length,6);
 assert.match(logo, /#008E8C/i);
 assert.match(logo, /#47D7AC/i);
 const article=articles.find(a=>a.id==='master-logo');
 assert.equal(article.status,'source');
 assert.equal(article.source?.url,'https://irlix.com/');
 assert.equal(articles.find(x=>x.id==='logo-rules').status,'approved');
 const light = readFileSync(new URL('../src/assets/irlix-site-wordmark-light-preview.svg',import.meta.url),'utf8');
 assert.equal((light.match(/<path\b/g)||[]).length,6);
 assert.equal((light.match(/fill="#142820"/g)||[]).length,4);
 assert.equal((light.match(/#008E8C/g)||[]).length,1);
 assert.equal((light.match(/#47D7AC/g)||[]).length,1);
 assert.equal(light.replace(/fill="#142820"/g,'fill="white"'),logo,'light variant changes only the four IRLI fills');
  const fromPublic = n=>readFileSync(new URL('../public/downloads/'+n,import.meta.url),'utf8');
  assert.equal(logoDownloads.length,3);
  assert.equal(new Set(logoDownloads.map(a=>a.href)).size,3);
  for(const entry of logoDownloads){
    assert.equal(entry.href,'/brandbook/downloads/'+entry.filename);
    assert.ok(entry.alt);
    assert.ok(fromPublic(entry.filename).includes('<svg'));
  }
  assert.equal(fromPublic('irlix-logo-dark-background.svg'),logo,'dark version must be original source SVG');
  assert.equal(fromPublic('irlix-logo-light-background.svg'),light,'light version only changes four white fills');
  const darkPaths=[...logo.matchAll(/<path\b[^>]*><\/path>/g)].map(m=>m[0]);
  const symbol=fromPublic('irlix-symbol-x.svg');
  const symbolPaths=[...symbol.matchAll(/<path\b[^>]*><\/path>/g)].map(m=>m[0]);
  assert.deepEqual(symbolPaths,darkPaths.slice(4),'X uses exactly the original two contours');
  assert.match(symbol,/viewBox="70 0 44 44"/);
  assert.doesNotMatch(symbol,/fill="white"/,'symbol contains no letters');

});
test('brandbook is publicly visible once, independent of account permissions',()=>{
 const entries=serviceGroups.flatMap(group=>group.items).filter(item=>item.key==='brandbook');
 assert.equal(entries.length,1,'duplicate service in launcher');
 assert.equal(entries[0].available,true);
 assert.equal(entries[0].public,true);
 assert.equal(entries[0].href,'/brandbook/');
 assert.equal(getVisibleServiceGroups(false,null).flatMap(group=>group.items).filter(item=>item.key==='brandbook').length,1);
 assert.equal(getVisibleServiceGroups(false,{roles:[],services:{brandbook:false}}).flatMap(group=>group.items).filter(item=>item.key==='brandbook').length,1);
});

test('published brand guidance uses approved design principles and no fake claims',()=>{
 assert.equal(logoGuidelines.correct.length,4);
 assert.equal(logoGuidelines.incorrect.length,4);
 assert.equal(digitalPrinciples.length,4);
 assert.equal(communicationDirections.map(x=>x.code).join(''), 'ABC');
 assert.equal(applicationPrinciples.length,4);
 const combined=[...logoGuidelines.correct,...logoGuidelines.incorrect,
   ...digitalPrinciples.map(x=>x.description),...communicationDirections.map(x=>x.description),
   ...applicationPrinciples.map(x=>x.description)].join(' ');
 assert.doesNotMatch(combined,/гарантируем|лучшие в мире|100\s*%|от\s*\d+\s*₽/i);
 assert.match(combined, /Onest|IRLI/);
});
test('public font binary redistribution includes intact original OFL license',async()=>{
 const {readFileSync}=await import('node:fs');
 const license=readFileSync(new URL('../public/licenses/Onest-OFL-1.1.txt',import.meta.url),'utf8');
 assert.match(license,/Copyright 2021 The Onest Project Authors/);
 assert.match(license,/SIL OPEN FONT LICENSE Version 1\.1/);
 assert.match(license,/PERMISSION & CONDITIONS/);
});

test('approved digital tokens download as internally consistent CSS and JSON',async()=>{
 const {readFileSync}=await import('node:fs');
 const raw=readFileSync(new URL('../public/downloads/irlix-digital-v1.json',import.meta.url),'utf8');
 const theme=JSON.parse(raw);
 const css=readFileSync(new URL('../public/downloads/irlix-digital-v1.css',import.meta.url),'utf8');
 assert.equal(theme.brand,'IRLIX');
 assert.equal(theme.scope,'digital-only');
 assert.equal(theme.font.family,'Onest');
 assert.deepEqual(theme.font.weights,[400,700]);
 assert.equal(tokenDownloads.length,2);
 for(const entry of tokenDownloads){
  assert.match(entry.href,/^\/brandbook\/downloads\//);
  assert.ok(entry.filename.endsWith('.css')||entry.filename.endsWith('.json'));
 }
 for(const [key,hex] of Object.entries(theme.colors)){
  const cssKey=key.replace(/[A-Z]/g,letter=>'-'+letter.toLowerCase());
  assert.ok(css.includes('--irlix-brand-'+cssKey+': '+hex+';'),key+' must match CSS');
 }
 assert.equal(theme.colors.logoXPrimary,'#008E8C');
 assert.equal(theme.colors.logoXUpper,'#47D7AC');
 assert.doesNotMatch(raw,/\bCLIENT_SECRET\b|\bPRIVATE_KEY\b/);
});

test('AI-augmented marketing retains technology, human accountability, and verifiable boundaries',()=>{
 assert.equal(brandVoice.headline,'Инженерная разработка, усиленная ИИ.');
 assert.match(brandVoice.description,/инженерный опыт/);
 assert.match(brandVoice.description,/технологий/);
 assert.match(brandVoice.description,/отвечают специалисты/);
 assert.deepEqual(aiEngineeringPillars.map(x=>x.id),['expertise','augment','accountability']);
 assert.deepEqual(aiTrustPrinciples.map(x=>x.id),['verify','privacy','choice']);
 assert.equal(articles.find(a=>a.id==='tone').approval.scope,'marketing');
 assert.equal(articles.find(a=>a.id==='version-2').approval.scope,'marketing');
 assert.equal(articles.find(a=>a.id==='ai-proof').status,'pending');
 assert.doesNotMatch(
   [brandVoice.headline,brandVoice.description,...aiEngineeringPillars.map(x=>x.description)].join(' '),
   /в\s*\d+\s*раз|на\s*\d+\s*%|100\s*%|все наши сотрудники|гарантируем|без ошибок/i
 );
});


test('UiBadge success status meets 4.5:1 contrast in light and dark themes',async()=>{
 const {readFileSync}=await import('node:fs');
 const tokens=readFileSync(new URL('../../../packages/ui/src/styles/tokens.css',import.meta.url),'utf8');
 const badge=readFileSync(new URL('../../../packages/ui/src/components/UiBadge.vue',import.meta.url),'utf8');
 assert.match(badge,/\.ui-badge--success\s*\{\s*background:var\(--irlix-status-success-bg\);\s*color:var\(--irlix-status-success-text\);\s*\}/);
 const light=tokens.match(/:root\s*\{([\s\S]*?)\}/)?.[1];
 const dark=tokens.match(/:root\[data-theme="dark"\]\s*\{([\s\S]*?)\}/)?.[1];
 assert.ok(light&&dark,'Both UI themes must have color tokens');
 function color(source,name) {
  const match=source.match(new RegExp('--'+name+':\\s*(#[0-9a-fA-F]{6})\\s*;'));
  assert.ok(match,'Missing '+name);
  return match[1];
 }
 function relativeLuminance(hex) {
  const vals=[1,3,5].map(i=>parseInt(hex.slice(i,i+2),16)/255).map(v=>v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4);
  return vals[0]*0.2126+vals[1]*0.7152+vals[2]*0.0722;
 }
 for(const [mode,source] of [['light',light],['dark',dark]]) {
  const a=relativeLuminance(color(source,'irlix-status-success-text'));
  const b=relativeLuminance(color(source,'irlix-status-success-bg'));
  const ratio=(Math.max(a,b)+0.05)/(Math.min(a,b)+0.05);
  assert.ok(ratio>=4.5,mode+' success text contrast '+ratio+' below WCAG AA');
 }
});


test('brandbook has concrete, safe verbal examples and three real carrier compositions',()=>{
 assert.equal(toneExamples.length,4,'four practical tone contrasts');
 assert.equal(carrierExamples.length,3,'website, slides and proposal are visually demonstrated');
 assert.deepEqual(carrierExamples.map(sample=>sample.id),['website','presentation','proposal']);
 for(const sample of toneExamples){
  assert.ok(sample.topic&&sample.preferred&&sample.avoid&&sample.reason);
  assert.notEqual(sample.preferred,sample.avoid);
  assert.doesNotMatch(sample.preferred,/гарантируем|в десять раз|за один день|любую информацию/i);
 }
 for(const sample of carrierExamples){
  assert.ok(sample.heading&&sample.description);
  assert.doesNotMatch(sample.heading,/\d+\s*%|гарантируем|в десять раз/i);
 }
 assert.equal(carrierExamples.find(x=>x.id==='website').heading,brandVoice.headline);
 assert.match(carrierExamples.find(x=>x.id==='proposal').description,/вымышленные сроки/);
});
