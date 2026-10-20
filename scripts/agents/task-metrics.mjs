// Pure analysis of trusted GitHub PR timing comments; no external dependencies.
export const EVENT_MARKER = '<!-- irlix-time-event-v1 -->';
export const SUMMARY_MARKER = '<!-- irlix-time-summary-v1 -->';
const trusted = new Set(['OWNER', 'MEMBER', 'COLLABORATOR']);
const validRole = /^(?:PM|Analyst|Designer|Developer(?::[a-z0-9-]{1,40})?|QA|PlatformEngineer|ProductAdvisor)$/;

export function parseTimingEvent(body) {
  if (typeof body !== 'string' || !body.includes(EVENT_MARKER)) return null;
  const data = {};
  for (const line of body.slice(body.indexOf(EVENT_MARKER) + EVENT_MARKER.length).split(/\r?\n/)) {
    const m = /^(role|action|segment):\s*(\S+)\s*$/.exec(line.trim());
    if (m && !Object.hasOwn(data, m[1])) data[m[1]] = m[2];
  }
  if (!validRole.test(data.role ?? '') || !['start', 'stop'].includes(data.action) ||
      !/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/.test(data.segment ?? '')) return null;
  return data;
}

export function durationLabel(ms) {
  if (!Number.isFinite(ms) || ms < 0) return 'не измерено';
  if (ms < 60000) return Math.round(ms / 1000) + ' с';
  const mins = Math.round(ms / 60000);
  return mins < 60 ? mins + ' мин' : Math.floor(mins / 60) + ' ч ' + (mins % 60) + ' мин';
}

export function analyzeTiming(comments, options = {}) {
  const endedAt = options.endedAt ? Date.parse(options.endedAt) : Infinity;
  const active = new Map();
  const totals = new Map();
  const issues = [];
  const events = (Array.isArray(comments) ? comments : [])
    .filter(c => trusted.has(c.author_association) && Number.isFinite(Date.parse(c.created_at)))
    .map(c => ({ record: parseTimingEvent(c.body), at: Date.parse(c.created_at), id: Number(c.id) || 0 }))
    .filter(x => x.record && x.at <= endedAt)
    .sort((a, b) => a.at - b.at || a.id - b.id);
  for (const { record: ev, at } of events) {
    const key = ev.role + ':' + ev.segment;
    if (ev.action === 'start') {
      if (active.has(key)) { issues.push('duplicate start ' + key); continue; }
      active.set(key, at);
      continue;
    }
    const start = active.get(key);
    if (start === undefined) { issues.push('stop without start ' + key); continue; }
    active.delete(key);
    const prev = totals.get(ev.role) ?? { ms: 0, segments: 0 };
    prev.ms += Math.max(0, at - start);
    prev.segments++;
    totals.set(ev.role, prev);
  }
  return { totals, active, issues, acceptedEvents: events.length };
}

export function renderTimingSummary({ pr, comments, roles = [], now = new Date().toISOString() }) {
  const result = analyzeTiming(comments, { endedAt: pr.merged_at ?? null });
  const names = [...new Set([...roles, ...result.totals.keys(), ...[...result.active.keys()].map(x => x.split(':')[0])])];
  const start = Date.parse(pr.created_at);
  const end = Date.parse(pr.merged_at ?? now);
  const lead = Number.isFinite(start) && Number.isFinite(end) && end >= start ? durationLabel(end - start) : 'не измерено';
  const rows = names.map(role => {
    const t = result.totals.get(role);
    const n = [...result.active.keys()].filter(k => k.startsWith(role + ':')).length;
    const value = t ? durationLabel(t.ms) + (n ? ' + незавершённые интервалы' : '') : (n ? 'измерение не завершено' : 'не измерено');
    return '| ' + role + ' | ' + value + ' | ' + (t?.segments ?? 0) + ' |';
  });
  const sum = [...result.totals.values()].reduce((n, v) => n + v.ms, 0);
  const state = pr.merged_at ? 'merged' : (pr.state === 'closed' ? 'closed without merge' : 'open');
  return [SUMMARY_MARKER, '### Время задачи · измерения GitHub',
    '', '**PR:** #' + pr.number + ' · **Состояние:** ' + state,
    '**Календарное время от создания PR:** ' + lead + (pr.merged_at ? '' : ' (пока PR не объединён)'),
    '',
    '| Роль / этап | Подтверждённое время | Завершённых сегментов |',
    '|---|---:|---:|',
    ...(rows.length ? rows : ['| — | не измерено | 0 |']),
    '',
    '**Сумма подтверждённых интервалов ролей:** ' + durationLabel(sum) + '.',
    'Это elapsed time START→STOP из доверенных комментариев, **не** CPU-время и не гарантированное активное время работы. Роли могут работать параллельно, сумма не равна календарному сроку.',
    'Интервалы до создания PR не учитываются, пока не подтверждены отдельно. Отсутствие события = «не измерено», а не 0 минут.',
    result.issues.length ? 'Непарные/повторные метки: ' + result.issues.length + ' (проверьте журнал).' : '',
    '',
    '_PR-метрики рассчитываются автоматически. Отдельные AI-агенты этим workflow не запускаются._'
  ].filter(Boolean).join('\n');
}
