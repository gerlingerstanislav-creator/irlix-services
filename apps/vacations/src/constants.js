export const typeLabels = {
  paid_vacation: 'Оплачиваемый отпуск',
  unpaid_vacation: 'Неоплачиваемый отпуск',
  sick_leave: 'Больничный',
  maternity_leave: 'Декрет',
  day_off: 'Отгул',
};

export const statusLabels = {
  planned: 'Запланировано',
  employee_review: 'Согласование сотрудниками',
  hr_review: 'Первичная проверка',
  account_manager_review: 'Согласование с аккаунт-менеджерами',
  manager_review: 'Согласование с руководителем',
  hr_final_review: 'Предоставление',
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
  const actions = ['view', 'history'];
  if (Number(absence.attachment_count || 0) > 0) actions.push('view_attachments');

  if (absence.status === 'planned') {
    actions.splice(1, 0, 'edit', 'upload_attachment');
    const documentRequired = ['paid_vacation', 'unpaid_vacation', 'sick_leave', 'maternity_leave'].includes(absence.type);
    const documentReady = !documentRequired || Number(absence.attachment_count || 0) > 0;
    const periodReady = !['sick_leave', 'maternity_leave'].includes(absence.type) || Boolean(absence.ends_on);
    if (documentReady && periodReady) actions.splice(3, 0, 'submit');
  } else if (!['confirmed', 'rejected', 'cancelled'].includes(absence.status)) {
    if (Number(absence.attachment_count || 0) === 0) actions.splice(1, 0, 'upload_attachment');
  }
  return [...new Set(actions)];
};
