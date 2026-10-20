import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Regression guard for mode-specific instructions. This does not test ChatGPT's
// hosted subagent availability, which cannot be enabled via a GitHub commit.
const root = resolve(import.meta.dirname, '../../..');
const read = p => readFileSync(resolve(root, p), 'utf8');

test('Chat and Work are different, explicit execution modes', () => {
  const rules = read('AGENTS.md');
  const guide = read('docs/AI_TEAM.md');
  assert.match(rules, /Обычный Chat:.*один ассистент/);
  assert.match(rules, /ChatGPT Work.*subagents/);
  assert.match(guide, /## Режим A: обычный Chat/);
  assert.match(guide, /## Режим B: ChatGPT Work/);
  assert.match(guide, /Если у текущей сессии нет инструментов делегирования/);
  assert.match(guide, /Никакой GitHub Actions не вызывает AI автоматически/);
});

test('Work requires independent QA and waiting for actual results', () => {
  const pm = read('.github/agents/PM.agent.md');
  const qa = read('.github/agents/QA.agent.md');
  assert.match(pm, /реальные состояния subagents/);
  assert.match(pm, /дождись их результатов/);
  assert.match(qa, /отдельного QA subagent с собственным контекстом/);
  assert.match(qa, /не рассуждения разработчика/);
  assert.match(qa, /не запускает.*модель/);
});

test('Work delegation does not turn GitHub routing into an AI runner', () => {
  const workflow = read('.github/workflows/ai-team.yml');
  const handoff = read('scripts/agents/handoff.mjs');
  assert.doesNotMatch(workflow, /OPENAI_API_KEY|codex exec|api\.openai\.com/);
  assert.match(handoff, /НЕ AI-review/);
  assert.match(read('docs/AI_TEAM.md'), /Production только по/);
});

test('Task-time estimation remains paused without losing other project policies', () => {
  for (const missing of [
    'docs/policies/TASK_ESTIMATION.md',
    '.github/workflows/task-timing.yml',
    'scripts/agents/task-metrics.mjs',
    'scripts/agents/task-metrics-github.mjs',
    'scripts/qa/tests/task-metrics.test.mjs'
  ]) {
    assert.ok(!existsSync(resolve(root, missing)), missing + ' must be absent');
  }
  for (const policy of ['ENGINEERING', 'GIT_RELEASE', 'ISSUES', 'TEAM_RULES']) {
    assert.ok(existsSync(resolve(root, 'docs/policies/' + policy + '.md')));
  }
  assert.ok(!read('AGENTS.md').includes('TASK_ESTIMATION.md'));
  assert.match(read('docs/policies/TEAM_RULES.md'), /приостановлены/);
});

test('Every specialized agent has an explicit ChatGPT Skill mapping', () => {
  const mapping = read('docs/AGENT_SKILLS.md');
  const named = [
    ['Analyst', 'Senior Business & System Analyst'],
    ['Designer', 'Senior Product UI/UX Designer'],
    ['Developer', 'Senior PHP / Laravel Backend Engineer'],
    ['Developer', 'Senior JavaScript / Vue Frontend Engineer'],
    ['QA', 'Senior QA Engineer']
  ];
  for (const [role, skill] of named) {
    assert.ok(mapping.includes(skill), 'Skill mapping missing: ' + skill);
    assert.ok(read('.github/agents/' + role + '.agent.md').includes(skill),
      role + ' instructions must reference: ' + skill);
  }
});

test('Installed Skills do not override project rules or imply availability in Work', () => {
  const mapping = read('docs/AGENT_SKILLS.md');
  assert.match(mapping, /не гарантирует/);
  assert.match(mapping, /не запускают AI самостоятельно/);
  assert.match(mapping, /репозиторным инструкциям/);
  for (const role of ['PM', 'Analyst', 'Designer', 'Developer', 'QA', 'PlatformEngineer', 'ProductAdvisor']) {
    assert.ok(read('.github/agents/' + role + '.agent.md').includes('AGENT_SKILLS.md'));
  }
  assert.ok(read('AGENTS.md').includes('AGENT_SKILLS.md'));
  assert.ok(read('docs/AI_TEAM.md').includes('AGENT_SKILLS.md'));
});
