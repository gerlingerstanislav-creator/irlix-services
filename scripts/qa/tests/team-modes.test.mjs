import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { resolve, dirname, relative } from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// Structural regression checks, not a simulation of model behavior or a claim
// that hosted capabilities can be enabled by committing instructions.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read = p => readFileSync(resolve(root, p), 'utf8');
// Supports a scoped checkout: validate known tracked paths without loading app code.
const tracked = new Set(execFileSync('git', ['ls-tree', '-r', '--name-only', 'HEAD'], {cwd: root, encoding: 'utf8'}).trim().split('\n'));
const present = p => existsSync(resolve(root, p)) || tracked.has(p);
const behaviors = ['analysis','frontend','backend','data','security','integration','verification','delivery'];
const roles = ['PM','Analyst','Designer','Developer','DatabaseEngineer','SecurityEngineer','PlatformEngineer','QA','ProductAdvisor'];
const adapters = ['docs/AI_TEAM.md','docs/AGENT_CONTEXT_PROTOCOL.md','docs/policies/TEAM_RULES.md','docs/CONTEXT_ROUTER.md'];
const walk = dir => readdirSync(resolve(root, dir), {withFileTypes:true}).flatMap(e => e.isDirectory() ? walk(dir+'/'+e.name) : [dir+'/'+e.name]);

test('instruction entry points and adapters resolve their local Markdown links', () => {
  const files = ['AGENTS.md',...walk('docs/agent'),...adapters,...roles.map(r=>'.github/agents/'+r+'.agent.md')];
  for (const file of files) {
    for (const [,href] of read(file).matchAll(/\[[^\]]*\]\(([^)\s]+)\)/g)) {
      if (/^(?:https?:|mailto:|#)/.test(href)) continue;
      const target = relative(root, resolve(root, dirname(file), href.split('#')[0]));
      assert.ok(!target.startsWith('..'), `${file}: escapes repository: ${href}`);
      assert.ok(present(target), `${file}: missing ${target}`);
    }
  }
});

test('each behavior is reachable and legacy profiles delegate to canonical rules', () => {
  const catalog = read('docs/agent/BEHAVIORS.md');
  for (const behavior of behaviors) assert.ok(catalog.includes(`behaviors/${behavior}.md`), behavior);
  for (const role of roles) {
    const text = read(`.github/agents/${role}.agent.md`);
    for (const target of ['WORKFLOW.md','DELEGATION.md','AGENT_SKILLS.md']) assert.ok(text.includes(target), `${role}: ${target}`);
    assert.doesNotMatch(text, /применяй Skill|## Режим [AB]|START\/STOP/);
  }
  for (const path of adapters) assert.match(read(path), /WORKFLOW\.md|DELEGATION\.md|BEHAVIORS\.md/);
});

test('manifest connects knowledge routing to the same workflow and behaviors', () => {
  const manifest = JSON.parse(read('docs/context/MANIFEST.json'));
  assert.equal(manifest.workflow, 'docs/agent/WORKFLOW.md');
  assert.equal(manifest.behaviorIndex, 'docs/agent/BEHAVIORS.md');
  for (const route of Object.values(manifest.horizontal)) {
    for (const entry of route.entry) assert.ok(present(entry), entry);
  }
});

test('routing remains advisory and disabled mechanisms are not reactivated', () => {
  assert.doesNotMatch(read('.github/workflows/ai-team.yml'), /OPENAI_API_KEY|codex exec|api\.openai\.com/);
  assert.match(read('scripts/agents/handoff.mjs'), /НЕ AI-review/);
  for (const path of ['docs/policies/TASK_ESTIMATION.md','.github/workflows/task-timing.yml','scripts/agents/task-metrics.mjs','scripts/agents/task-metrics-github.mjs']) assert.ok(!present(path), path);
  for (const path of ['docs/AGENT_SKILLS.md','skills/README.md']) assert.match(read(path), /отключены/i);
});
