// Employee card header actions are defined in one place so every tab and permission set agrees.
export function employeeManagementActions(employee, permissions = {}, { deleting = false, resendingOnboarding = false } = {}) {
  if (!employee) return [];
  const allowed = permission => Boolean(permissions?.[permission]);
  const employed = employee.employment_status === 'Трудоустроен';
  const pending = employee.employment_status === 'Ожидает трудоустройства';
  const dismissed = employee.employment_status === 'Уволен';
  const protectedUser = employee.login === 'admin';
  const actions = [];
  if (pending && allowed('employees.hire')) actions.push({ id: 'hire', label: 'Трудоустроить' });
  if (employed && !protectedUser && allowed('employees.transfer')) actions.push({ id: 'transfer', label: 'Перевести в другое направление' });
  if (employed && allowed('employees.manage')) actions.push({ id: 'cooperation', label: 'Изменить тип сотрудничества' });
  if (dismissed && allowed('employees.manage')) actions.push({ id: 'rehire', label: 'Вернуть в компанию' });
  if (allowed('employees.salary.read') && allowed('employees.salary.manage')) actions.push({ id: 'salary', label: 'Пересмотреть зарплату' });
  if (allowed('access.manage') && employee.keycloak_user_id) actions.push({
    id: 'onboarding',
    label: resendingOnboarding ? 'Отправляем…' : (employee.onboarding_email_status === 'sent' ? 'Отправить письмо повторно' : 'Отправить письмо'),
    disabled: resendingOnboarding,
  });
  if (employed && !protectedUser && allowed('employees.manage')) actions.push({ id: 'dismiss', label: 'Уволить', danger: true });
  if (!protectedUser && allowed('access.manage')) actions.push({ id: 'delete', label: deleting ? 'Удаление…' : 'Удалить пользователя', danger: true, disabled: deleting });
  return actions;
}
