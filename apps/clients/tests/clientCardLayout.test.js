import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const clientCard = readFileSync(new URL('../src/components/ClientCard.vue', import.meta.url), 'utf8');

test('Client card tabs size to content with equal horizontal padding', () => {
  assert.match(clientCard, /\.client-card-tabs\{[^}]*display:flex;[^}]*overflow-x:auto\}/);
  assert.match(clientCard, /\.client-card-tabs :deep\(\.irlix-tab\)\{flex:0 0 auto;padding-inline:16px\}/);
  assert.doesNotMatch(clientCard, /--irlix-card-tabs-count/);
  assert.doesNotMatch(clientCard, /\.client-card-tabs button\{padding-inline:/);
});

test('Projects use a compact bordered table like legal entities', () => {
  assert.match(clientCard, /class="project-list"><div class="project-list-head">/);
  assert.match(clientCard, /class="project-list-head"><span>Название<\/span><span>Тип проекта<\/span><span>Действия<\/span>/);
  assert.match(clientCard, /\.project-list\{border:1px solid var\(--irlix-color-border\);border-radius:8px;overflow:hidden\}/);
  assert.match(clientCard, /\.project-list-head,\.project-list-row\{display:grid;[^}]*min-height:34px/);
  assert.match(clientCard, /project\.is_default\?'Основной проект':project\.name/);
  assert.match(clientCard, /effectiveCanManage&&!project\.is_default/);
  assert.match(clientCard, /@click="deleteProject\(project\)"/);
});
