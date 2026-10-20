import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../../..');
const read = file => readFileSync(resolve(root, file), 'utf8');

test('every ordinary service registry from the approved migration list uses UiRegistryTable', () => {
  const cases = [
    ['Employees — сотрудники', 'apps/web/src/App.vue', 'aria-label="Сотрудники"'],
    ['Employees — аудит', 'apps/web/src/components/AuditLogView.vue', 'aria-label="История действий сотрудников"'],
    ['Employees — специальные роли', 'apps/web/src/components/SpecialRolesView.vue', 'aria-label="Сотрудники специальной роли"'],
    ['Clients — контактные лица', 'apps/clients/src/App.vue', 'aria-label="Контактные лица"'],
    ['Clients — подключения', 'apps/clients/src/App.vue', 'aria-label="Подключения специалистов"'],
    ['Clients — запросы', 'apps/clients/src/components/RequestsWorkflowView.vue', 'aria-label="Запросы"'],
    ['Clients — позиции', 'apps/clients/src/components/RequestsWorkflowView.vue', 'aria-label="Позиции запросов"'],
    ['Vacations — список для трёх маршрутов', 'apps/vacations/src/components/AbsenceTable.vue', 'aria-label="Отсутствия"'],
    ['Vacations — история', 'apps/vacations/src/pages/ActionHistoryPage.vue', 'aria-label="История действий по отсутствиям"'],
    ['Timesheets — аналитика', 'apps/timesheets/src/App.vue', 'aria-label="Коммерческая загрузка"'],
    ['Timesheets — аудит', 'apps/timesheets/src/App.vue', 'aria-label="История изменений таймшитов"'],
    ['Specialists — сотрудники', 'apps/specialists/src/App.vue', 'aria-label="Специалисты"'],
    ['Recruitment — заявки', 'apps/recruitment/src/AppMvp.vue', 'aria-label="Заявки на подбор"'],
    ['Recruitment — кандидаты', 'apps/recruitment/src/AppMvp.vue', 'aria-label="Кандидаты"'],
    ['Recruitment — интервью', 'apps/recruitment/src/AppMvp.vue', 'aria-label="Интервью"'],
    ['Recruitment — трудоустройство', 'apps/recruitment/src/AppMvp.vue', 'aria-label="Трудоустройство"'],
    ['Clients — контакты в карточке', 'apps/clients/src/components/ClientContactsTab.vue', 'aria-label="Контакты клиента"'],
  ];
  assert.match(read('apps/recruitment/src/main.js'), /import App from '\.\/AppMvp\.vue'/, 'verify the actual Recruitment entry point');
  for (const [label, path, expected] of cases) {
    const source = read(path);
    assert.match(source, /<UiRegistryTable\b/, label);
    assert.ok(source.includes(expected), label + ': missing accessible table name');
  }
  const vacationPages = [
    'apps/vacations/src/pages/MyAbsencesPage.vue',
    'apps/vacations/src/pages/ManageAbsencesPage.vue',
    'apps/vacations/src/pages/DepartmentAbsencesPage.vue'
  ];
  for (const page of vacationPages) assert.match(read(page), /<AbsenceTable\b/, page);
});

test('embedded Clients contacts preserve keyboard actions, empty state and scrollable shared header', () => {
  const s = read('apps/clients/src/components/ClientContactsTab.vue');
  assert.match(s, /:columns="\['38%','auto','42px'\]"/);
  assert.match(s, /<template #head><tr><th>ФИО<\/th><th>Должность<\/th><th aria-label="Редактирование"><\/th><\/tr><\/template>/);
  assert.match(s, /<tr v-for="contact in contacts"[^>]*class="contact-row" tabindex="0" role="button"/);
  assert.match(s, /@click="openContact\(contact\)" @keydown\.enter\.prevent="openContact\(contact\)" @keydown\.space\.prevent="openContact\(contact\)"/);
  assert.match(s, /\{\{ contact\.relation_role \|\| contact\.position \|\| '—' \}\}/);
  assert.match(s, /<tr v-if="!contacts\.length"><td class="contact-empty" colspan="3">/);
  assert.match(s, /<ContactPersonCard/);
  assert.doesNotMatch(s, /class="contact-head"/);
});
