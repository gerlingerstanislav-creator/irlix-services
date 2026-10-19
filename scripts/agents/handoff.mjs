#!/usr/bin/env node
// Deterministic PR handoff for ChatGPT/Codex users. No model, API key or background AI is invoked.
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
const list=xs=>xs.length?xs.map(x=>'- \`'+x.replace(/[`\\r\\n<>]/g,'_').slice(0,160)+'\`').join('\n'):'- Не определены по путям — требуется назначение PM';
const text=[
 m,
 '### AI Team · маршрутизация PR (без API)',
 '',
 '**PR:** #'+prNumber+' · **SHA:** \`'+sha.slice(0,12)+'\`',
 '',
 '**Разработчики по сервисам:**',
 list(routing.developers),
 '',
 '**Роли для проверки:**',
 list(routing.roles.filter(x=>x!=='PM')),
 '',
 '**PM:** сопоставляет замечания ревью с результатами QA/CI и возвращает исправления указанным разработчикам.',
 '',
 routing.scopeKnown?'':'**Внимание:** область по изменённым путям не определена; PM назначает её вручную.',
 '**Статус:** назначение ролей, **НЕ AI-review и НЕ подтверждение тестирования**.',
 'Код проверяют обычные GitHub Actions; для AI-review подключите собственный Codex Code Review к GitHub в настройках Codex.',
 'После нахождения дефекта AI-разработчик не запускается автоматически; исправление назначается через PM/Codex.',
 '',
 '_Приватные ТЗ из ideas не читаются и не публикуются в публичном PR._'
].filter(Boolean).join('\n');
const old=await pages('/repos/'+repo+'/issues/'+prNumber+'/comments');
const existing=old.find(x=>x.user?.login==='github-actions[bot]'&&x.body?.includes(m));
if(existing)await gh('/repos/'+repo+'/issues/comments/'+existing.id,'PATCH',{body:text});
else await gh('/repos/'+repo+'/issues/'+prNumber+'/comments','POST',{body:text});
if(process.env.GITHUB_STEP_SUMMARY)appendFileSync(process.env.GITHUB_STEP_SUMMARY,text+'\n');
console.log('Routed #'+prNumber+' at '+sha.slice(0,12)+': '+(routing.developers.join(', ')||'scope unknown'));
