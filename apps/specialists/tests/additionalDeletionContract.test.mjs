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
