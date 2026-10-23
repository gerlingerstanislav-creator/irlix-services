import test from 'node:test';
import assert from 'node:assert/strict';
import { articles, canonicalPath, filterArticles, sectionForPath, sections, statusInfo } from '../src/catalog.js';
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
   assert.deepEqual(article.approval,{scope:'digital',date:'24.10.2026'});
   assert.doesNotMatch(article.description,/гарантируем|100\s*%|2700\s*₽/i);
  }else assert.equal(article.source,null);
 }
 assert.deepEqual(articles.filter(a=>a.status==='approved').map(a=>a.id).sort(),['font','logo-rules','palette','version-1']);
});
test('search works across all sections and does not match unrelated queries',()=>{
 assert.ok(filterArticles(articles,'Финтех','all').length>0);
 assert.ok(filterArticles(articles,'хабр','all').some(article=>article.id==='habr-message'));
 assert.equal(filterArticles(articles,'unlikely-super-rare-search', 'all').length,0);
 assert.ok(filterArticles(articles,'','logo').every(article=>article.section==='logo'));
});

test('site SVG is a public reference, not an approved download',async()=>{
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
