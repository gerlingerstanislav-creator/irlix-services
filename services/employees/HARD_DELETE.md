# Employees hard delete

Hard delete предназначен только для исправления ошибочно созданных записей и не заменяет штатное увольнение сотрудника.

## Доступ

Операция доступна только полному администратору Employees. На текущей модели это `company-admin` или технический `platform-admin`. HR, Finance и руководители не имеют права hard delete.

## Поведение

`DELETE /api/employees/employees/{employee}`:

1. Проверяет авторизацию через общий Employees authorization layer.
2. Если у employee есть `keycloak_user_id`, удаляет соответствующую identity из Keycloak.
3. Только после успешного удаления identity удаляет employee из Employees DB.
4. Связанные записи с FK `cascadeOnDelete` удаляются вместе с employee; ссылки manager/hr в departments настроены через `nullOnDelete`.
5. Если Keycloak недоступен или отказал в удалении, локальная запись не удаляется.

UI показывает кнопку `Удалить пользователя` только пользователю с `access.manage`. Перед вызовом API требуется явное подтверждение необратимого действия.

Для штатного ухода сотрудника используется workflow увольнения, сохраняющий кадровую историю.
