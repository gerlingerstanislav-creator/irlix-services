<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { UiAppShell, UiErrorState, isPlatformAdministratorAccess } from '@irlix/ui';
import { createBrowserAuth } from '@irlix/auth';
import StorageDrive from './StorageDrive.vue';
import StorageTrash from './StorageTrash.vue';
import StorageV1 from './StorageV1.vue';

// Same OIDC session as Dashboard and every other IRLIX service.
// The short-lived HttpOnly Storage cookie is independently checked by nginx
// and storage-api. A browser-side access check is only for navigation/UX.
const auth = createBrowserAuth({
  storagePrefix: 'irlix.platform.auth',
  defaultReturnTo: '/storage/',
});
const loading = ref(true);
const access = ref(null);
const isAdmin = computed(() => isPlatformAdministratorAccess(access.value));
const error = ref('');
// Admin-only fallback preserves old files until V1 metadata and recovery are ready.
const legacyFallback = ref(false);
const allItems = [
  { id: 'files', label: 'Мои файлы', icon: 'file-text', href: '/storage/' },
  { id: 'available', label: 'Доступные мне', icon: 'users', href: '/storage/available' },
  { id: 'trash', label: 'Корзина', icon: 'reset', href: '/storage/trash' },
];
const items = computed(() => allItems);
const route = useRoute();
const router = useRouter();
const folderLabels = ref({});
function onBreadcrumbs(crumbs) {
  folderLabels.value = Object.fromEntries(crumbs.map(crumb => [crumb.path, crumb.name]));
}
const activeSection = computed(() => route.meta.section || 'not-found');
const breadcrumbs = computed(() => {
  if (activeSection.value === 'trash') return [{ label: 'Корзина' }];
  if (activeSection.value === 'available') return [{ label: 'Доступные мне' }];
  if (activeSection.value !== 'files') return [{ label: 'Страница не найдена' }];
  if (!legacyFallback.value && typeof route.query.folder_id === 'string') return [
    { label: activeSection.value === 'available' ? 'Доступные мне' : 'Мои файлы',
      href: activeSection.value === 'available' ? '/storage/available' : '/storage/' },
    { label: folderLabels.value[route.query.folder_id] || 'Папка' },
  ];
  if (!legacyFallback.value) return [{ label: 'Мои файлы' }];
  const path = typeof route.query.folder === 'string' ? route.query.folder : '';
  const segments = path.split('/').filter(Boolean);
  return [
    { label: 'Мои файлы', href: segments.length ? '/storage/' : undefined },
    ...segments.map((label, index) => ({
      label: folderLabels.value[segments.slice(0, index + 1).join('/') + '/'] || label,
      href: index < segments.length - 1
        ? '/storage/?folder=' + encodeURIComponent(segments.slice(0, index + 1).join('/') + '/')
        : undefined,
    })),
  ];
});
function onV1Folder(item) {
  folderLabels.value = { ...folderLabels.value, [item.id]: item.name };
}
function navigateSection(id) {
  if (items.value.some(item => item.id === id)) router.push({ name: id });
}

onMounted(async () => {
  try {
    if (!await auth.init()) return; // OIDC redirects to the existing corporate login.
    const response = await auth.fetch('/api/employees/access/me', {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    });
    if (!response.ok) throw new Error('Сведения о правах недоступны');
    const result = (await response.json())?.data || null;
    if (result?.services?.storage !== true || !Number.isSafeInteger(Number(result.employee_id))) {
      error.value = 'Для использования диска необходима действующая учётная запись сотрудника и доступ к Storage.';
      return;
    }
    access.value = result;
    // The server verifies Keycloak identity and active Employee before issuing
    // a short-lived V1 cookie. A browser role flag has no API authority.
    const v1Session = await auth.fetch('/api/employees/storage-v1/session', {
      method: 'POST', cache: 'no-store',
    });
    if (!v1Session.ok) {
      error.value = 'Не удалось открыть сессию корпоративного диска.';
      return;
    }
    // Read-only V1 gate probe. Fallback is *only* for a platform administrator;
    // other employees must never see the old pilot's objects.
    const probe = await fetch('/api/storage/v1/items?scope=mine', {
      credentials: 'same-origin', cache: 'no-store',
    });
    if (probe.status === 503 && isPlatformAdministratorAccess(result)) {
      legacyFallback.value = true;
      const oldSession = await auth.fetch('/api/employees/storage-pilot/session', {
        method: 'POST', cache: 'no-store',
      });
      if (!oldSession.ok) throw new Error('Old Storage session unavailable');
    } else if (probe.status === 503) {
      error.value = 'Корпоративный диск ещё не подключён к базе метаданных.';
    } else if (!probe.ok) {
      error.value = 'Не удалось проверить доступ к корпоративному диску.';
    }
  } catch (_) {
    error.value = 'Не удалось проверить права доступа. Откройте хранилище через дашборд.';
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div v-if="loading" class="storage-startup" role="status">Проверяем доступ к хранилищу…</div>
  <div v-else-if="error" class="storage-startup" role="alert">
    <h1>Хранилище недоступно</h1><p>{{ error }}</p><a href="/">На дашборд</a>
  </div>
  <UiAppShell
    v-else
    service="storage"
    service-name="Хранилище"
    :section="activeSection"
    :items="items"
    :current-user="auth.user"
    :platform-admin="isAdmin"
    :service-access="access"
    :report-submit="(url, options) => auth.fetch(url, options)"
    :breadcrumbs="breadcrumbs"
    @update:section="navigateSection"
    @logout="auth.logout()"
  >
    <StorageDrive v-if="legacyFallback && activeSection === 'files'" @breadcrumbs="onBreadcrumbs" />
    <StorageTrash v-else-if="legacyFallback && activeSection === 'trash'" />
    <section v-else-if="legacyFallback && activeSection === 'available'" class="storage-placeholder">
      <h2>Доступные мне</h2><p>Общий доступ появится после подключения базы метаданных Storage V1.</p>
    </section>
    <StorageV1 v-else-if="['files', 'available', 'trash'].includes(activeSection)"
      :section="activeSection" :access="access" @folder-open="onV1Folder"
      :directory-fetch="(url, options) => auth.fetch(url, options || {cache: 'no-store'})" />
    <UiErrorState v-else :status="404" back-href="/storage/" back-label="Мои файлы" />
  </UiAppShell>
</template>
