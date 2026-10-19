import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
const root=resolve(import.meta.dirname,'../../..');
const get=p=>readFileSync(resolve(root,p),'utf8');

test('AI role handoff has no paid OpenAI API dependency',()=>{
 const workflow=get('.github/workflows/ai-team.yml');
 const runner=get('scripts/agents/handoff.mjs');
 assert.doesNotMatch(workflow,/OPENAI_API_KEY|IDEAS_READ_TOKEN|AI_TEAM_ENABLED|openai\.com|codex exec/);
 assert.doesNotMatch(runner,/OPENAI_API_KEY|IDEAS_READ_TOKEN|api\.openai\.com|platform\.openai\.com|chatgpt\.com\/backend-api/);
 assert.ok(!existsSync(resolve(root,'scripts/agents/review.mjs')),'old paid AI reviewer must be removed');
 assert.match(workflow,/ref: \$\{\{ github\.event\.pull_request\.base\.sha \}\}/);
 assert.match(workflow,/pull_request_target:/);
 assert.doesNotMatch(workflow,/github\.event\.pull_request\.head\.sha \}\}/);
});
test('AI handoff visibly distinguishes routing from actual AI review',()=>{
 const text=get('scripts/agents/handoff.mjs');
 assert.match(text,/НЕ AI-review/);
 assert.match(text,/НЕ подтверждение тестирования/);
 assert.match(text,/scripts\/agents\/route\.mjs|routeChanges/);
 assert.match(text,/issues\/comments/);
});
