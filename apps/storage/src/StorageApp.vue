<script setup>
import { onMounted, ref } from 'vue';
import { UiAppShell, isPlatformAdministratorAccess } from '@irlix/ui';
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
const items = [{ id: 'files', label: 'Мои файлы', icon: 'document' }];

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
    section="files"
    :items="items"
    :current-user="auth.user"
    :platform-admin="true"
    :service-access="access"
    :report-submit="(url, options) => auth.fetch(url, options)"
    :breadcrumbs="[{ label: 'Мои файлы' }]"
    @logout="auth.logout()"
  >
    <StorageDrive />
  </UiAppShell>
</template>
