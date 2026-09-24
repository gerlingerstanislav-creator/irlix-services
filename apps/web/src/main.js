import { createApp, ref, onMounted } from 'vue';
import './style.css';

const App = {
  setup() {
    const services = ref([
      { key: 'platform', name: 'Platform Core', endpoint: '/api/platform/health', status: 'checking' },
      { key: 'employees', name: 'Employees', endpoint: '/api/employees/health', status: 'checking' },
    ]);

    const refresh = async () => {
      await Promise.all(services.value.map(async (service) => {
        service.status = 'checking';
        try {
          const response = await fetch(service.endpoint, { headers: { Accept: 'application/json' } });
          service.status = response.ok ? 'ok' : 'error';
        } catch {
          service.status = 'error';
        }
      }));
    };

    onMounted(refresh);
    return { services, refresh };
  },
  template: `
    <main class="page">
      <section class="hero">
        <div>
          <div class="eyebrow">IRLIX · INTERNAL PLATFORM</div>
          <h1>Сервисы компании</h1>
          <p>Первый стенд новой платформы. Общая оболочка и независимые backend-сервисы уже запускаются через единый Docker-контур.</p>
        </div>
        <button @click="refresh">Проверить сервисы</button>
      </section>

      <section class="grid">
        <article v-for="service in services" :key="service.key" class="card">
          <div class="card-head">
            <span class="dot" :class="service.status"></span>
            <span>{{ service.status === 'ok' ? 'Доступен' : service.status === 'checking' ? 'Проверка…' : 'Недоступен' }}</span>
          </div>
          <h2>{{ service.name }}</h2>
          <p v-if="service.key === 'platform'">Аутентификация, permissions, каталог сервисов и общие платформенные механизмы.</p>
          <p v-else>Сотрудники, оргструктура и жизненный цикл сотрудника. Первый эталонный бизнес-сервис.</p>
        </article>
      </section>
    </main>
  `,
};

createApp(App).mount('#app');
