import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { matchesEmployeeName } from '../src/employeeNameSearch.js';

test('finds name by surname, first name, patronymic and multiword partial query', () => {
  const name = 'Иванов Иван Иванович';
  for (const query of ['иванов', 'ИВАН', 'иванов ива', 'иван иванов', ' ИванОВ   Иван ', 'ванов']) {
    assert.equal(matchesEmployeeName(name, query), true, query);
  }
  assert.equal(matchesEmployeeName(name, 'Петров'), false);
  assert.equal(matchesEmployeeName(name, 'Петров Иван'), false);
});

test('handles blank names/queries and Unicode-normalized text', () => {
  assert.equal(matchesEmployeeName(null, '  '), true);
  assert.equal(matchesEmployeeName(null, 'Иван'), false);
  assert.equal(matchesEmployeeName('Петрова Анна', 'анна\tпет'), true);
  assert.equal(matchesEmployeeName('Ёлкин Пётр', 'ёлкин'), true);
});

test('department calendar and list share name-filtered data, management keeps name search', () => {
  const pages = ['DepartmentAbsencesPage.vue', 'ManageAbsencesPage.vue'];
  const sources = pages.map((name) => readFileSync(fileURLToPath(new URL('../src/pages/'+name, import.meta.url)), 'utf8'));
  for (const source of sources) assert.match(source, /Поиск по ФИО/);
  assert.match(sources[0], /visibleDepartmentEmployees\(/); // delegates name matching to the roster helper
  assert.match(sources[1], /matchesEmployeeName\(/);
  assert.match(sources[0], /visibleItems=computed\(\(\)=>\{[\s\S]*matchingItems\.value/);
  assert.match(sources[0], /grouped=computed\(\(\)=>\{.*visibleItems\.value/);
  assert.match(sources[0], /:items="listItems"/);
});
