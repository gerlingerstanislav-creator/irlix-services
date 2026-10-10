export const shortEmployeeName = name => String(name || '').trim().split(/\s+/).slice(0,2).join(' ') || '—';
export function compactNumber(value) {
  if(value === null || value === undefined || value === '') return '—';
  const number=Number(String(value).replace(',','.'));
  return Number.isFinite(number) ? new Intl.NumberFormat('ru-RU',{maximumFractionDigits:10}).format(number) : String(value);
}
export function rateDate(value) {
  if(!value) return 'по н.в.';
  const [year,month,day]=String(value).slice(0,10).split('-');
  return `${Number(day)}.${month}.${year}`;
}
export const ascendingTerms = member => [...(member.terms || [])].sort((a,b)=>String(a.valid_from).localeCompare(String(b.valid_from)) || Number(a.id)-Number(b.id));
