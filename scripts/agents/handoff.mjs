#!/usr/bin/env node
// Deterministic PR scope handoff for parallel ChatGPT chats. No model, external API or background AI is invoked.
// This script is run from the trusted base ref in pull_request_target; it does not check out PR code.
import { readFileSync, appendFileSync } from 'node:fs';
import { routeChanges } from './route.mjs';

const event=JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH,'utf8'));
const pr=event.pull_request;
const repo=process.env.GITHUB_REPOSITORY;
const token=process.env.GITHUB_TOKEN;
if(!repo||!token||!pr)throw new Error('Expected a GitHub pull request event and GITHUB_TOKEN');
if(pr.base?.ref!=='development'||pr.base?.repo?.full_name!==repo||
   pr.head?.repo?.full_name!==repo||pr.draft) {
  console.log('Only non-draft same-repository PRs targeting development are routed.');
  process.exit(0);
}
const prNumber=pr.number;
if(!Number.isInteger(prNumber)||prNumber<=0)throw new Error('Invalid PR number');
const sha=pr.head.sha;
if(!/^[0-9a-f]{40}$/.test(sha))throw new Error('Invalid PR SHA');
const api='https://api.github.com';
async function gh(path,method='GET',payload){
 const response=await fetch(api+path,{
  method,headers:{
   Authorization:'Bearer '+token,Accept:'application/vnd.github+json',
   'X-GitHub-Api-Version':'2022-11-28',
   ...(payload?{'Content-Type':'application/json'}:{})
  },
  ...(payload?{body:JSON.stringify(payload)}:{}),
  signal:AbortSignal.timeout(30000)
 });
 if(!response.ok)throw new Error('GitHub '+method+' '+path+': '+response.status+' '+(await response.text()).slice(0,180));
 return response.json();
}
async function pages(path,limit=5){
 const all=[];
 for(let page=1;page<=limit;page++){
  const items=await gh(path+(path.includes('?')?'&':'?')+'per_page=100&page='+page);
  if(!Array.isArray(items))throw new Error('Unexpected GitHub page response');
  all.push(...items);
  if(items.length<100)return all;
 }
 throw new Error('Too many pages: refusing to create an incomplete scope report');
}
const files=await pages('/repos/'+repo+'/pulls/'+prNumber+'/files');
const paths=files.map(f=>f.filename);
const routing=routeChanges(paths);
const m='<!-- irlix-ai-team-handoff-v2 -->';
const list=xs=>xs.length?xs.map(x=>'- ' + JSON.stringify(x).slice(0,200)).join('\n'):'- Не определены по путям — требуется назначение PM';
const text=[
 m,
 '### Маршрутизация задачи по сервисам · без AI',
 '',
 '**PR:** #'+prNumber+' · **SHA:** \`'+sha.slice(0,12)+'\`',
 '',
 '**Контексты разработки:**',
 list(routing.developers),
 '',
 '**Этапы текущего чата (включать по необходимости):**',
 list(routing.roles.filter(x=>x!=='PM')),
 '',
 '**PM:** тот же чат последовательно выполняет анализ, разработку и критический QA-проход. При ошибках CI исправляет код и повторно проверяет текущий SHA.',
 '',
 routing.scopeKnown?'':'**Внимание:** область по изменённым путям не определена; PM назначает её вручную.',
 '**Статус:** автоматическое определение контекстов, **НЕ AI-review и НЕ подтверждение тестирования**.',
 'Реальные тесты выполняются обычными GitHub Actions. Данные PASS/FAIL/NOT TESTED берутся только из результатов проверок.',
 'При FAIL исправление делает активный чат в своей feature-ветке; отдельный AI-разработчик не запускается и события не приходят автоматически в ChatGPT.',
 '',
 '_Приватные ТЗ из ideas не читаются и не публикуются в публичном PR. Параллельные чаты используют разные ветки от development._'
].filter(Boolean).join('\n');
const old=await pages('/repos/'+repo+'/issues/'+prNumber+'/comments');
const existing=old.find(x=>x.user?.login==='github-actions[bot]'&&x.body?.includes(m));
// A PR opened through an integration can have a read-only GITHUB_TOKEN even
// with workflow-level issues:write. Preserve the role report in Actions Summary
// rather than failing unrelated CI solely because posting a comment is denied.
try {
 if(existing)await gh('/repos/'+repo+'/issues/comments/'+existing.id,'PATCH',{body:text});
 else await gh('/repos/'+repo+'/issues/'+prNumber+'/comments','POST',{body:text});
} catch(error) {
 if(!/GitHub (POST|PATCH) .*: 403 .*Resource not accessible by integration/.test(String(error)))throw error;
 console.warn('Cannot write PR handoff comment with this workflow token (403); using Actions Summary only.');
}
if(process.env.GITHUB_STEP_SUMMARY)appendFileSync(process.env.GITHUB_STEP_SUMMARY,text+'\n');
console.log('Routed #'+prNumber+' at '+sha.slice(0,12)+': '+(routing.developers.join(', ')||'scope unknown'));
