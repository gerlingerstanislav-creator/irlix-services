// Shared column tracks for every Vacations absence registry.
//
// The period is the only flexible track. In a wide table, the browser assigns
// spare space there rather than stretching type/status badges or approval icons.
// Removing the optional employee track therefore leaves the remaining tracks
// identical on "Мои отпуска" and "Управление отсутствиями".
export const PERIOD_MIN_WIDTH = 230;

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
  'auto',
  '110px',
  ...(centerColumns
    ? [`${Math.max(190, typeBadgeWidth + 18)}px`, `${Math.max(150, statusBadgeWidth + 18)}px`]
    : ['205px', '190px']),
  ...(showProgress ? ['112px'] : []),
  ...(showStage ? ['180px'] : []),
  ...(showDocuments ? ['135px'] : []),
  ...(showActions ? ['48px'] : []),
];

export const absenceRegistryMinWidth = (columns) => `${columns.reduce(
  (total, column) => total + (column === 'auto' ? PERIOD_MIN_WIDTH : Number.parseInt(column, 10)),
  0,
)}px`;
