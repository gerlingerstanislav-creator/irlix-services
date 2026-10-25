#!/usr/bin/env node
// Advisory PR path hints. No model API or background AI is invoked.
// This script is run from the trusted base ref in pull_request_target; it does not check out PR code.
import { readFileSync, appendFileSync } from 'node:fs';
import { routeChanges } from './route.mjs';
import { pathToFileURL } from 'node:url';

const m='<!-- irlix-ai-team-handoff-v2 -->';
const list=xs=>xs.length?xs.map(x=>'- '+JSON.stringify(x).slice(0,200)).join('\n'):'- Нет совпадений по путям.';

export function renderHandoff({prNumber,sha,routing,repo}){
 return [
  m,
  '### Подсказки по изменённым путям · без AI',
  '',
  '**PR:** #'+prNumber+' · **SHA:** `'+sha.slice(0,12)+'`',
  '',
  '**Возможные сервисные контексты (не назначения исполнителей):**',
  list(routing.services),
  '',
  '**Кандидаты поведения (совместимы друг с другом, не обязательные роли):**',
  list(routing.behaviorHints.map(h=>h.behavior+': '+h.reason)),
  '',
  '**Владелец задачи:** прочитать запрос и относящийся diff, оценить намерение, неопределённость и риск; выбрать соразмерные поведения и проверки по доказательствам.',
  '**Неизвестно по путям:** намерение, фактическое влияние, уровень риска и достаточность проверки. Даже известный сервис не подтверждает scope задачи.',
  routing.scopeKnown?'':'**Область по путям не распознана:** уточнить её по запросу и содержимому изменений.',
  '**Статус:** только навигационные подсказки, **НЕ AI-review и НЕ подтверждение тестирования**.',
  'Реальные тесты выполняются обычными GitHub Actions. Данные PASS/FAIL/NOT TESTED берутся только из результатов проверок; пустой результат, неизвестный статус и BLOCKED не означают успех.',
  'При FAIL активный чат исправляет причину и повторяет относящиеся проверки текущего SHA; отдельный AI-разработчик не запускается и события не приходят автоматически в ChatGPT.',
  '',
  'Правила: [AGENTS.md](https://github.com/'+repo+'/blob/development/AGENTS.md) · [WORKFLOW.md](https://github.com/'+repo+'/blob/development/docs/agent/WORKFLOW.md).',
  '',
  '_Приватные ТЗ из ideas не читаются и не публикуются в публичном PR. Изоляция и независимость помощников определяются docs/agent/DELEGATION.md._'
 ].filter(Boolean).join('\n');
}

export async function main(){
 const event=JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH,'utf8'));
 const pr=event.pull_request;
 const repo=process.env.GITHUB_REPOSITORY;
 const token=process.env.GITHUB_TOKEN;
 if(!repo||!token||!pr)throw new Error('Expected a GitHub pull request event and GITHUB_TOKEN');
 if(pr.base?.ref!=='development'||pr.base?.repo?.full_name!==repo||
    pr.head?.repo?.full_name!==repo||pr.draft) {
   console.log('Only non-draft same-repository PRs targeting development are routed.');
   return;
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
 const text=renderHandoff({prNumber,sha,routing,repo});
 const old=await pages('/repos/'+repo+'/issues/'+prNumber+'/comments');
 const existing=old.find(x=>x.user?.login==='github-actions[bot]'&&x.body?.includes(m));
 // A PR opened through an integration can have a read-only GITHUB_TOKEN even
 // with workflow-level issues:write. Preserve the advisory report in Actions Summary
 // rather than failing unrelated CI solely because posting a comment is denied.
 try {
  if(existing)await gh('/repos/'+repo+'/issues/comments/'+existing.id,'PATCH',{body:text});
  else await gh('/repos/'+repo+'/issues/'+prNumber+'/comments','POST',{body:text});
 } catch(error) {
  if(!/GitHub (POST|PATCH) .*: 403 .*Resource not accessible by integration/.test(String(error)))throw error;
  console.warn('Cannot write PR handoff comment with this workflow token (403); using Actions Summary only.');
 }
 if(process.env.GITHUB_STEP_SUMMARY)appendFileSync(process.env.GITHUB_STEP_SUMMARY,text+'\n');
 console.log('Routed #'+prNumber+' at '+sha.slice(0,12)+': '+(routing.services.join(', ')||'path area unknown'));

}

if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href)await main();
