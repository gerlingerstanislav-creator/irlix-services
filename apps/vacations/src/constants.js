export const typeLabels = {
  paid_vacation: 'Оплачиваемый отпуск',
  unpaid_vacation: 'Неоплачиваемый отпуск',
  sick_leave: 'Больничный',
  maternity_leave: 'Декрет',
  day_off: 'Отгул',
};

export const statusLabels = {
  planned: 'Запланировано',
  hr_review: 'Проверка кадровиком',
  account_manager_review: 'Согласование с аккаунт-менеджерами',
  manager_review: 'Согласование с руководителем',
  hr_final_review: 'Итоговое подтверждение кадровиком',
  confirmed: 'Предоставлено',
  rejected: 'Отклонено',
  cancelled: 'Отменено',
};

export const actionLabels = {
  view: 'Открыть',
  edit: 'Редактировать',
  upload_attachment: 'Загрузить заявление / документ',
  view_attachments: 'Документы',
  submit: 'Отправить на согласование',
  approve: 'Согласовать',
  provide: 'Предоставить отпуск',
  return_to_planned: 'Вернуть на доработку',
  history: 'История',
};

export const auditLabels = {
  created: 'Создано отсутствие',
  updated: 'Изменены данные отсутствия',
  submitted: 'Отправлено на согласование',
  approved: 'Согласован этап',
  confirmed: 'Предоставлен отпуск',
  returned_to_planned: 'Возвращено на доработку',
  attachment_uploaded: 'Загружен документ',
  attachment_deleted: 'Удалён документ',
};

export const formatDate = (value) => value
  ? new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T00:00:00`))
  : '—';

export const formatDateTime = (value) => value
  ? new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
  : '—';

export const ownActions = (absence) => {
  const actions = ['view', 'history', 'view_attachments'];
  if (absence.status === 'planned') actions.splice(1, 0, 'edit', 'upload_attachment', 'submit');
  else if (!['confirmed', 'rejected', 'cancelled'].includes(absence.status)) actions.splice(1, 0, 'upload_attachment');
  return [...new Set(actions)];
};
