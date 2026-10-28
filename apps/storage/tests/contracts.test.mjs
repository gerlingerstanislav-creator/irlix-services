import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const text = path => readFileSync(new URL(path, import.meta.url), 'utf8');
const vue = text('../src/StorageDrive.vue');
const api = text('../../../services/storage-api/app/main.py');
const ingress = text('../../../infra/nginx/irlix-services.conf');
const compose = text('../../../docker-compose.storage.yml');

test('Drive supports real S3-backed browsing, upload, folders and previews', () => {
  for (const path of ['/items', '/folders', '/files']) {
    assert.ok(vue.includes(path), 'missing API operation ' + path);
  }
  for (const text of ['Папка', 'Загрузить файлы', 'Поиск в папке', 'preview=true',
                       '<iframe', '<img', '<pre', 'На дашборд']) {
    assert.ok(vue.includes(text), 'missing UI capability ' + text);
  }
});

test('Storage remains admin-only, with backend gate and never exposes credentials', () => {
  assert.match(api, /async def guard\(request: Request\):/);
  assert.match(api, /_storage_pilot_access/);
  assert.match(api, /X-Irlix-Storage-Request/);
  assert.match(api, /ROOT = "pilot-ui\/"|ROOT = "pilot-ui\/"/);
  assert.doesNotMatch(vue, /STORAGE_S3_SECRET_KEY|STORAGE_S3_ACCESS_KEY|aws_secret_access_key/);
  assert.ok(ingress.includes('auth_request /_storage_pilot_gate;'));
  assert.ok(ingress.includes('127.0.0.1:8114'));
  assert.ok(ingress.includes('127.0.0.1:8115'));
  assert.match(compose, /127\.0\.0\.1:8114:8000/);
  assert.match(compose, /127\.0\.0\.1:8115:80/);
});

test('No business attachment or destructive object endpoints exist in the pilot', () => {
  assert.doesNotMatch(api, /@app\.delete\(/);
  assert.doesNotMatch(api, /@app\.(post|put|patch)\("\/business/);
  assert.ok(api.includes('MAX_FILE = 10 * 1024 * 1024'));
});
