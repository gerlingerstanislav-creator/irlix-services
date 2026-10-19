import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { parseTimingEvent, analyzeTiming, renderTimingSummary, durationLabel } from '../../agents/task-metrics.mjs';

const event = (role, action, segment, at, options = {}) => ({
  body: '<!-- irlix-time-event-v1 -->\nrole: ' + role + '\naction: ' + action + '\nsegment: ' + segment,
  created_at: at,
  author_association: options.association ?? 'OWNER',
  id: options.id ?? 1
});

test('validates timing events and ignores junk or spoofed role', () => {
  assert.deepEqual(parseTimingEvent(event('QA', 'start', 'qa-1', '2026-10-20T00:00:00Z').body),
    { role: 'QA', action: 'start', segment: 'qa-1' });
  assert.equal(parseTimingEvent('plain text'), null);
  assert.equal(parseTimingEvent('<!-- irlix-time-event-v1 -->\nrole: Admin\naction: stop\nsegment: x'), null);
});

test('pairs role intervals independently, sums same role, ignores untrusted records', () => {
  const data = [
    event('PM', 'start', 'pm-1', '2026-10-20T08:00:00Z'),
    event('QA', 'start', 'qa-1', '2026-10-20T08:04:00Z'),
    event('PM', 'stop', 'pm-1', '2026-10-20T08:05:00Z'),
    event('QA', 'stop', 'qa-1', '2026-10-20T08:10:00Z'),
    event('QA', 'start', 'qa-2', '2026-10-20T08:11:00Z'),
    event('QA', 'stop', 'qa-2', '2026-10-20T08:14:00Z'),
    event('QA', 'start', 'malicious', '2026-10-20T00:00:00Z', { association: 'NONE' }),
    event('QA', 'stop', 'malicious', '2026-10-20T09:00:00Z', { association: 'NONE' })
  ];
  const report = analyzeTiming(data);
  assert.equal(report.totals.get('PM').ms, 5 * 60000);
  assert.equal(report.totals.get('QA').ms, 9 * 60000);
  assert.equal(report.totals.get('QA').segments, 2);
  assert.equal(report.active.size, 0);
  assert.equal(report.acceptedEvents, 6);
});

test('open or broken segments do not become fabricated measured time', () => {
  const report = analyzeTiming([
    event('Developer:clients', 'start', 'dev-1', '2026-10-20T08:00:00Z'),
    event('QA', 'stop', 'unknown', '2026-10-20T08:11:00Z')
  ]);
  assert.equal(report.active.size, 1);
  assert.equal(report.totals.has('Developer:clients'), false);
  assert.equal(report.issues.length, 1);
  assert.equal(durationLabel(NaN), 'не измерено');
});

test('PR output distinguishes calendar lead time and measured roles', () => {
  const pr = { number: 12, created_at: '2026-10-20T08:00:00Z',
    merged_at: '2026-10-20T09:00:00Z', state: 'closed' };
  const summary = renderTimingSummary({ pr, comments: [
    event('QA', 'start', 'qa-1', '2026-10-20T08:10:00Z'),
    event('QA', 'stop', 'qa-1', '2026-10-20T08:14:00Z'),
    event('QA', 'start', 'late', '2026-10-20T10:00:00Z')
  ], roles: ['PM', 'QA'] });
  assert.match(summary, /Календарное время.*1 ч 0 мин/);
  assert.match(summary, /\\| PM \\| не измерено/);
  assert.match(summary, /\\| QA \\| 4 мин/);
  assert.doesNotMatch(summary, /незавершённые интервалы/);
});

test('all former root AGENTS topics remain in linked policy files', () => {
  const root = resolve(import.meta.dirname, '../../..');
  const read = p => readFileSync(resolve(root, p), 'utf8');
  const agent = read('AGENTS.md');
  for (const path of ['ENGINEERING', 'GIT_RELEASE', 'ISSUES', 'TEAM_RULES', 'TASK_ESTIMATION']) {
    assert.ok(agent.includes('docs/policies/' + path + '.md'));
  }
  const policies = ['ENGINEERING', 'GIT_RELEASE', 'ISSUES', 'TEAM_RULES']
    .map(p => read('docs/policies/' + p + '.md')).join('\n');
  for (const heading of ['Единый реестр задач', 'Чувствительные, реальные и демонстрационные данные',
    'Выбор сотрудников на всей платформе', 'Параллельная разработка и защита изменений',
    'Правила разработки', 'Git и CI/CD — накопительные релизы',
    'Поэтапный прогресс реализации', 'Работа с неоднозначностями']) {
    assert.ok(policies.includes('## ' + heading), heading);
  }
});
