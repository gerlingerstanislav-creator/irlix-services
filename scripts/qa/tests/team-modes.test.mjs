import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
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
