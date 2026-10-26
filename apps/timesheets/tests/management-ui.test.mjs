import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
const compact = readFileSync(new URL('../src/management-compact.css', import.meta.url), 'utf8');
const extra = readFileSync(new URL('../src/extra.css', import.meta.url), 'utf8');
const management = app.split('section === \'management\' && management"')[1]?.split('section === \'analytics\'')[0];

test('management legend uses current lower-cell absence strips without changing the personal calendar', () => {
  assert.ok(management, 'management template is present');
  assert.match(management, /swatch management-absence-confirmed/);
  assert.match(management, /swatch management-absence-pending/);
  assert.match(compact, /\.swatch\.management-absence-confirmed[\s\S]*linear-gradient\(to bottom/);
  assert.match(compact, /\.swatch\.management-absence-pending[\s\S]*linear-gradient\(to bottom/);
  assert.match(app, /class="swatch absence-confirmed"><\/i>Предоставленное отсутствие/);
});

test('management cells keep the editor and selection but have no lock or description icons', () => {
  assert.match(management, /@dblclick="openManagerEdit\(row, cell.date\)"/);
  assert.match(management, /@mousedown="startSelection\(\$event, row, cell.date\)"/);
  assert.doesNotMatch(management, /<svg/);
  assert.doesNotMatch(management, /has-description|client-lock/);
  assert.doesNotMatch(compact, /\.client-lock|\.has-description::after/);
  assert.match(app, /<label>Описание<textarea v-model="project.description"/);
  assert.match(app, /const clientPeriodLocked = /);
});

test('final-approved weekends retain a saturated green and white numbers, while preliminary weekends stay pale', () => {
  assert.match(compact, /\.matrix-cell\.final\.non-working\s*\{[^}]*color-mix\(in srgb, var\(--irlix-color-confirm-final\) 92%, var\(--irlix-color-surface\)\)/);
  assert.match(compact, /\.matrix-cell\.final\.non-working\s*\{[^}]*color:var\(--irlix-color-on-accent\)/);
  assert.match(compact, /\.matrix-cell\.final\.non-working b\s*\{[^}]*color:var\(--irlix-color-on-accent\)/);
  assert.match(compact, /\.matrix-cell\.prelim\.non-working\s*\{[^}]*color-mix\(in srgb, var\(--irlix-color-confirm-preliminary\) 45%, var\(--irlix-color-surface\)\)/);
  assert.match(compact, /\.matrix th\.non-working\s*\{[^}]*background-color:var\(--irlix-color-surface\)/);
});

test('confirmed absence band and legend use orangered, pending absence stays pale', () => {
  assert.match(compact, /\.matrix-cell\.has-absence \.absence-half\s*\{[^}]*background:orangered;/);
  assert.match(compact, /\.swatch\.management-absence-confirmed[\s\S]*?background:linear-gradient\(to bottom,[\s\S]*?orangered 65% 100%\)/);
  assert.match(compact, /\.matrix-cell\.has-absence\.absence-pending \.absence-half\s*\{[^}]*background:var\(--irlix-color-absence-border\)/);
  assert.match(compact, /\.swatch\.management-absence-pending\s*\{[^}]*var\(--irlix-color-absence-border\) 65% 100%/);
});

test('management rows display connection periods and partner marker after name', () => {
  assert.match(app, /import \{ buildConnectionPeriodLabels \} from '\.\/management-connection-periods\.js'/);
  assert.match(management, /row\.isPartner \? ' \(Партнер\)' : ''/);
  assert.match(management, /<small :title="row\.connectionPeriods">\{\{ row\.connectionPeriods \}\}<\/small>/);
  assert.doesNotMatch(management, /row\.clientName \}\} · \{\{ row\.memberName/);
  assert.match(app, /connectionPeriods: staffPeriodLabels\.get/);
  assert.match(app, /connectionPeriods: partnerPeriodLabels\.get/);
});

test('only zero-hour numerals receive muted styling, including final-approved weekends', () => {
  assert.match(management, /<span v-if="cell\.active"><b :class="\{ 'zero-hours': cell\.hours === 0 \}">/);
  assert.match(compact, /\.management-page \.matrix-cell b\.zero-hours\s*\{[^}]*color:var\(--irlix-color-text-muted\) !important;/);
  assert.match(compact, /\.management-page \.matrix-cell\.final\.non-working b\.zero-hours\s*\{[^}]*color:color-mix\(in srgb, var\(--irlix-color-text-muted\) 40%, black\)/);
  assert.match(compact, /\.management-page \.matrix-cell\.final\.non-working b\s*\{[^}]*color:var\(--irlix-color-on-accent\)/);
});

test('management is edge-to-edge with one separator per section', () => {
  assert.match(extra, /\.management-page\s*\{[^}]*margin:0;[^}]*border:0;[^}]*border-radius:0/);
  assert.match(extra, /\.management-page > \.management-filters\s*\{[^}]*padding:10px 12px;[^}]*border:0;[^}]*border-bottom:1px/);
  assert.match(extra, /\.management-page > \.legend\s*\{[^}]*padding:8px 12px;[^}]*border:0;[^}]*border-bottom:1px/);
});
