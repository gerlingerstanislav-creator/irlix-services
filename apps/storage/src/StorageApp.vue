<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { UiAppShell, UiErrorState, isPlatformAdministratorAccess } from '@irlix/ui';
import { createBrowserAuth } from '@irlix/auth';
import StorageDrive from './StorageDrive.vue';

// Same OIDC session as Dashboard and every other IRLIX service.
// The short-lived HttpOnly Storage cookie is independently checked by nginx
// and storage-api. A browser-side access check is only for navigation/UX.
const auth = createBrowserAuth({
  storagePrefix: 'irlix.platform.auth',
  defaultReturnTo: '/storage/',
});
const loading = ref(true);
const access = ref(null);
const error = ref('');
const items = [
  { id: 'files', label: 'Мои файлы', icon: 'document', href: '/storage/' },
  { id: 'available', label: 'Доступные мне', icon: 'users', href: '/storage/available' },
];
const route = useRoute();
const router = useRouter();
const activeSection = computed(() => route.meta.section || 'not-found');
const breadcrumbs = computed(() => {
  if (activeSection.value === 'available') return [{ label: 'Доступные мне' }];
  if (activeSection.value !== 'files') return [{ label: 'Страница не найдена' }];
  const path = typeof route.query.folder === 'string' ? route.query.folder : '';
  const segments = path.split('/').filter(Boolean);
  return [
    { label: 'Мои файлы', href: segments.length ? '/storage/' : undefined },
    ...segments.map((label, index) => ({
      label,
      href: index < segments.length - 1
        ? '/storage/?folder=' + encodeURIComponent(segments.slice(0, index + 1).join('/') + '/')
        : undefined,
    })),
  ];
});
function navigateSection(id) {
  if (id === 'files' || id === 'available') router.push({ name: id });
}

onMounted(async () => {
  try {
    if (!await auth.init()) return; // OIDC redirects to the existing corporate login.
    const response = await auth.fetch('/api/employees/access/me', {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    });
    if (!response.ok) throw new Error('Сведения о правах недоступны');
    const result = (await response.json())?.data || null;
    if (!isPlatformAdministratorAccess(result) || result?.services?.storage !== true) {
      error.value = 'Доступ к пилотному хранилищу разрешён только администраторам платформы.';
      return;
    }
    access.value = result;
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
    :platform-admin="true"
    :service-access="access"
    :report-submit="(url, options) => auth.fetch(url, options)"
    :breadcrumbs="breadcrumbs"
    @update:section="navigateSection"
    @logout="auth.logout()"
  >
    <StorageDrive v-if="activeSection === 'files'" />
    <section v-else-if="activeSection === 'available'" class="storage-placeholder">
      <h2>Доступные мне</h2>
      <p>Раздел для файлов, к которым предоставлен доступ другими сотрудниками. Пока общий доступ не подключён — чужие файлы не отображаются.</p>
    </section>
    <UiErrorState v-else :status="404" back-href="/storage/" back-label="Мои файлы" />
  </UiAppShell>
</template>
