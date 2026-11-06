import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = path => readFileSync(new URL('../../../' + path, import.meta.url), 'utf8');

test('additional technology deletion is a dedicated guarded controller/use-case', () => {
  const routes = source('services/specialists/routes/api.php');
  const controller = source('services/specialists/app/Http/Controllers/AdditionalTechnologyCatalogController.php');
  const service = source('services/specialists/app/Services/AdditionalTechnologyCatalogService.php');
  const authorizer = source('services/specialists/app/Support/SpecialistsCatalogAuthorizer.php');
  assert.match(routes, /Route::delete\('\/catalog\/additional-technologies\/\{technology\}', \[AdditionalTechnologyCatalogController::class, 'remove'\]\)->whereNumber/);
  assert.match(controller, /assertCanManage\(\$request\)/);
  assert.match(controller, /'replacement_id' => \['nullable', 'integer', 'min:1'\]/);
  assert.match(controller, /removeOrReplace/);
  assert.doesNotMatch(controller, /DB::table\(|DB::transaction\(/);
  assert.match(authorizer, /specialists\.catalog\.manage/);
  assert.match(authorizer, /scope'\] \?\? ''\) === 'all'/);
  assert.match(authorizer, /platform-admin/);
  assert.match(authorizer, /X-Irlix-Role-Preview/);
  assert.match(service, /DB::transaction\(/);
  assert.match(service, /lockForUpdate\(\)/);
  assert.match(service, /insertOrIgnore/);
  assert.match(service, /SpecialistTimelineEvent::query\(\)->create/);
  assert.match(service, /\$source->active = false/);
  assert.match(service, /where\('additional_technology_id', \$sourceId\)->delete\(\)/);
  assert.doesNotMatch(service, /DB::table\(/);
});

test('profile autosave revalidates active extra catalog inside the same transaction', () => {
  const service = source('services/specialists/app/Services/SpecialistProfessionalService.php');
  assert.match(service, /return DB::transaction\(function \(\) use/);
  assert.match(service, /whereIn\('id', \$secondary\)[\s\S]*?lockForUpdate\(\)[\s\S]*?Некоторые дополнительные технологии уже удалены/);
  assert.match(source('services/specialists/Dockerfile'), /tests\/additional_technology_deletion\.php/);
});

test('admin catalog shows a conditional action and explicit replacement dialog', () => {
  const app = source('apps/specialists/src/App.vue');
  assert.match(app, /v-if="canEditTechnologyCatalog"[\s\S]*?openAdditionalDeletion\(technology\)/);
  assert.match(app, /role="dialog" aria-modal="true"/);
  assert.match(app, /<UiSearchSelect v-model="replacementAdditional"/);
  assert.match(app, /additionalReplacementOptions/);
  assert.match(app, /Number\(technology\.id\) !== Number\(deletingAdditional\.value\?\.id\)/);
  assert.match(app, /method: 'DELETE'/);
  assert.match(app, /replacement_id: replacement \? Number\(replacement\.id\) : null/);
  assert.match(app, /canEditTechnologyCatalog\.value/);
  assert.match(app, /<UiToast v-if="catalogToast"/);
});


test('additional catalog DELETE has an exact method and URL permission gate', () => {
  const middleware = source('services/specialists/app/Http/Middleware/ServiceEntitlement.php');
  assert.ok(middleware.includes("in_array($method, ['PUT','DELETE'], true)"));
  assert.ok(middleware.includes("preg_match('#^api/catalog/additional-technologies/[0-9]+$#',$path)"));
  assert.match(middleware, /specialists\.catalog\.manage/);
  assert.match(source('services/specialists/Dockerfile'), /tests\/additional_delete_admission\.php/);
});

test('specialists deletion modal uses compact shared typography tokens', () => {
  const styles = source('apps/specialists/src/style.css');
  const app = source('apps/specialists/src/App.vue');
  assert.match(styles, /\.modal-card\{[^}]*font-family:var\(--irlix-font-sans\);font-size:var\(--irlix-font-size-body\)/);
  assert.match(styles, /\.modal-head h2\{[^}]*font-size:var\(--irlix-font-size-section-title\)/);
  assert.match(styles, /\.specialists-additional-delete-modal \.specialists-delete-description\{[^}]*font-size:var\(--irlix-font-size-body\)/);
  assert.match(app, /class="modal-card specialists-additional-delete-modal"/);
});


test('additional catalog rename has authorized PUT route and transaction-safe service', () => {
  const routes = source('services/specialists/routes/api.php');
  const controller = source('services/specialists/app/Http/Controllers/AdditionalTechnologyCatalogController.php');
  const service = source('services/specialists/app/Services/AdditionalTechnologyCatalogService.php');
  const gate = source('services/specialists/app/Http/Middleware/ServiceEntitlement.php');
  assert.match(routes, /Route::put\('\/catalog\/additional-technologies\/\{technology\}', \[AdditionalTechnologyCatalogController::class, 'rename'\]\)->whereNumber/);
  assert.match(controller, /public function rename\(Request \$request, int \$technology\)/);
  assert.match(controller, /\$this->authorization->assertCanManage\(\$request\)/);
  assert.match(controller, /\$this->catalog->rename\(/);
  assert.match(gate, /in_array\(\$method, \['PUT','DELETE'\], true\)/);
  assert.match(service, /public function rename\(/);
  assert.match(service, /DB::transaction\(/);
  assert.match(service, /->lockForUpdate\(\)/);
  assert.match(service, /whereRaw\('LOWER\(TRIM\(name\)\) = \?'/);
  assert.match(service, /Переименование дополнительной технологии/);
  assert.match(service, /Новое название совпадает с основной технологией/);
  assert.doesNotMatch(controller, /DB::table\(/);
});

test('inline extra technology rename uses a pencil and replaces its text with an input', () => {
  const app = source('apps/specialists/src/App.vue');
  const style = source('apps/specialists/src/style.css');
  assert.match(app, /Редактировать название/);
  assert.match(app, /✎/);
  assert.match(app, /editingAdditionalId === Number\(technology\.id\)/);
  assert.match(app, /<input ref="additionalRenameInput" v-model="editingAdditionalName"/);
  assert.match(app, /@keydown.enter.prevent="saveAdditionalRename"/);
  assert.match(app, /@keydown.esc.stop.prevent="cancelAdditionalRename"/);
  assert.match(app, /method: 'PUT', body: JSON.stringify\(\{ name \}\)/);
  assert.match(app, /additionalRenameError = ''/);
  assert.match(app, /@click="saveAdditionalRename"/);
  assert.match(app, /@click="cancelAdditionalRename"/);
  assert.match(app, /canEditTechnologyCatalog\.value/);
  assert.match(style, /\.specialists-additional-inline-input\{[\s\S]*font-size:var\(--irlix-font-size-table\)/);
  assert.match(style, /\.specialists-additional-inline-error\{[\s\S]*font-size:var\(--irlix-font-size-caption\)/);
});
