export const periodMonths = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
export function parsePeriod(value, mode = 'month') {
  const match = String(value).match(mode === 'year' ? /^(\d{4})$/ : /^(\d{4})-(0[1-9]|1[0-2])$/);
  return match ? { year: Number(match[1]), month: mode === 'year' ? 0 : Number(match[2]) - 1 } : null;
}
export function shiftPeriod(period, delta, mode = 'month') {
  if (mode === 'year') return { year: period.year + delta, month: 0 };
  const total = period.year * 12 + period.month + delta;
  return { year: Math.floor(total / 12), month: ((total % 12) + 12) % 12 };
}
export function formatPeriod(period, mode = 'month') {
  return mode === 'year' ? period.year : `${period.year}-${String(period.month + 1).padStart(2, '0')}`;
}
