const SERVICE_NAMES = {
  timesheets: 'Timesheets',
  clients: 'Clients',
  vacations: 'Vacations',
  specialists: 'Specialists',
  recruitment: 'Recruitment',
  equipment: 'Equipment',
  finance: 'Finance',
  storage: 'Storage',
};

export function employeeDeletionErrorMessage(error) {
  const name = error?.service ? (SERVICE_NAMES[error.service] || error.service) : null;
  if (!name) return error?.message || 'Не удалось удалить сотрудника.';
  const code = Number.isInteger(error.upstreamStatus) ? error.upstreamStatus : null;

  if (code === 423) {
    return `Удаление заблокировано в ${name}: обнаружены защищённые финансовые или учётные записи. Повтор операции не снимет эту блокировку. Для удаления требуется отдельное согласование и сверка данных.`;
  }
  if (code === 409) {
    return `Удаление заблокировано в ${name}: данные сотрудника требуют ручного сопоставления или проверки связей. Данные других сотрудников не изменены этим этапом.`;
  }
  const retry = error?.message === 'Cross-service employee purge failed'
    ? 'Попробуйте повторить операцию; если ошибка повторится, передайте название сервиса администратору.'
    : (error?.message || 'Свяжитесь с администратором.');
  return `Не удалось удалить сотрудника: ошибка очистки ${name} (HTTP ${code ?? 'неизвестен'}). ${retry}`;
}
