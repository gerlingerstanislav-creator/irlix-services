// Management rows refer to ClientMember connections, not project assignments.
// Multiple historical terms may exist for one connection; keep each period visible.
const asDate = (value) => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : null;
const shortDate = (iso) => `${iso.slice(8, 10)}.${iso.slice(5, 7)}.${iso.slice(0, 4)}`;

export function buildConnectionPeriodLabels(records = []) {
  const periods = new Map();
  for (const record of records) {
    const memberId = Number(record.client_member_id);
    const from = asDate(record.valid_from);
    if (!Number.isFinite(memberId) || !from) continue;
    const to = asDate(record.valid_to);
    if (!periods.has(memberId)) periods.set(memberId, new Set());
    periods.get(memberId).add(`${from}|${to || ''}`);
  }
  return new Map([...periods].map(([memberId, terms]) => [
    memberId,
    [...terms].sort().map((term) => {
      const [from, to] = term.split('|');
      return to ? `${shortDate(from)}–${shortDate(to)}` : `с ${shortDate(from)}`;
    }).join('; '),
  ]));
}
