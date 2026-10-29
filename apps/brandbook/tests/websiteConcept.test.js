import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { canonicalPath, sectionForPath, sections } from '../src/catalog.js';
import { routes } from '../src/routes.js';
import { brandVoice } from '../src/marketing.js';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const preview = read('../src/WebsiteConcept.vue');
const css = read('../src/websiteConcept.css');
const app = read('../src/App.vue');

test('website preview has an addressable brandbook route, sidebar section and 404 fallback', () => {
  assert.equal(canonicalPath('website'), '/brandbook/website/');
  assert.equal(sectionForPath('/brandbook/website/'), 'website');
  assert.equal(sectionForPath('/brandbook/website/not-a-route'), 'not-found');
  assert.equal(sections.find((item) => item.id === 'website')?.label, 'Концепция сайта');
  assert.ok(routes.some((route) => route.name === 'website' && route.path === '/website/'));
  assert.equal(routes.at(-1)?.name, 'not-found');
  assert.match(app, /<WebsiteConcept v-else-if="current === 'website'"\s*\/>/);
});

test('concept reflects approved engineering-led positioning and all three formats', () => {
  assert.equal(brandVoice.headline, 'Инженерная разработка, усиленная ИИ.');
  assert.match(preview, /import \{ brandVoice \} from '\.\/marketing\.js'/);
  assert.match(preview, /\{\{ brandVoice\.description \}\}/);
  for (const name of ['Специалист', 'Команда', 'Под ключ']) {
    assert.ok(preview.includes(name), 'missing work format: ' + name);
  }
  for (const name of ['Понимаем задачу', 'Выбираем подход', 'Проверяем результат']) {
    assert.ok(preview.includes(name), 'missing process step: ' + name);
  }
});

test('preview has accessible internal navigation and working mode buttons', () => {
  assert.match(preview, /role="group" aria-label="Ширина предпросмотра"/);
  assert.match(preview, /:aria-pressed="previewMode === 'mobile'"/);
  assert.match(preview, /aria-controls="site-concept-nav"/);
  assert.match(preview, /:aria-expanded="menuOpen"/);
  for (const target of ['home', 'benefits', 'formats', 'method', 'contact']) {
    assert.ok(preview.includes('id="site-concept-' + target + '"'), 'missing anchor: ' + target);
  }
  assert.match(preview, /function closeMenu\(\)/);
  assert.match(css, /:focus-visible/);
  assert.match(css, /prefers-reduced-motion:reduce/);
});

test('prototype is honest about unverified marketing and does not impersonate live lead capture', () => {
  assert.match(preview, /Внутренний интерактивный прототип/);
  assert.doesNotMatch(preview, /<form[\s>]/);
  assert.doesNotMatch(preview, /href="mailto:/);
  assert.doesNotMatch(preview, /(?:в\s*\d+\s*раз\s*быстрее|ускоряем\s*на\s*\d+%)/i);
  assert.match(preview, /href="https:\/\/irlix\.com\/" target="_blank" rel="noopener noreferrer"/);
});

test('responsive layout works for embedded phone width, not only browser viewport', () => {
  assert.match(css, /\.site-stage\{container-type:inline-size/);
  assert.match(css, /@container\s*\(max-width:680px\)/);
  assert.match(css, /\.site-stage--mobile\{max-width:390px/);
  assert.match(preview, /:class="\{ 'site-stage--mobile': previewMode === 'mobile' \}"/);
  assert.doesNotMatch(preview, /<main[\s>]/); // brandbook shell owns the page's main landmark
});
