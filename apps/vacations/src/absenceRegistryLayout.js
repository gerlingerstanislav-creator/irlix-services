// One layout for every absence registry: compact period/type on the left,
// status/approval/actions on the right. The flexible spacer absorbs free width.
export const PERIOD_MIN_WIDTH = 310;
export const SPACER_MIN_WIDTH = 12;

export const absenceRegistryColumns = ({
  showEmployee = false,
  showProgress = false,
  showStage = false,
  showDocuments = true,
  showActions = true,
  centerColumns = true,
  typeBadgeWidth = 0,
  statusBadgeWidth = 0,
} = {}) => [
  ...(showEmployee ? ['220px'] : []),
  centerColumns ? `${PERIOD_MIN_WIDTH}px` : 'auto',
  centerColumns ? `${Math.max(215, typeBadgeWidth + 18)}px` : '205px',
  ...(centerColumns ? ['auto'] : []),
  centerColumns ? `${Math.max(160, statusBadgeWidth + 8)}px` : '190px',
  ...(showProgress ? [centerColumns ? '96px' : '112px'] : []),
  ...(showStage ? ['180px'] : []),
  ...(showDocuments ? ['135px'] : []),
  ...(showActions ? [centerColumns ? '40px' : '48px'] : []),
];

export const absenceRegistryMinWidth = (columns, { centerColumns = false } = {}) =>
  `${columns.reduce(
    (total, column) => total + (column === 'auto'
      ? (centerColumns ? SPACER_MIN_WIDTH : PERIOD_MIN_WIDTH)
      : Number.parseInt(column, 10)),
    0,
  )}px`;

export const absenceTypeTone = (type) => ({
  paid_vacation: 'info',
  unpaid_vacation: 'warning',
  sick_leave: 'danger',
  maternity_leave: 'violet',
  day_off: 'success',
}[type] || 'neutral');

// The duration belongs to the period; preserve Russian numeral inflection.
export const formatAbsenceDays = (value) => {
  if (value === null || value === undefined || value === '') return '';
  const days = Number(value);
  if (!Number.isInteger(days) || days < 0) return '';
  const mod100 = days % 100;
  const mod10 = days % 10;
  const form = mod100 >= 11 && mod100 <= 14 ? 'дней'
    : mod10 === 1 ? 'день'
    : mod10 >= 2 && mod10 <= 4 ? 'дня'
    : 'дней';
  return `(${days} ${form})`;
};
