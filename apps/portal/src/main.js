import { createApp, h } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { createBrowserAuth } from '@irlix/auth';
import '@irlix/ui/styles/base.css';

const auth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/' });
let migrationState = null;
let selectedRunId = null;
let migrationPollTimer = null;
let migrationLoading = false;

const clearPlatformSessionStorage = () => {
  let idToken = null;
  for (let index = sessionStorage.length - 1; index >= 0; index -= 1) {
    const key = sessionStorage.key(index);
    if (!key || !key.startsWith('irlix.') || !key.includes('.auth.')) continue;
    if (!idToken && key.endsWith('.tokens')) { try { idToken = JSON.parse(sessionStorage.getItem(key) || 'null')?.id_token || null; } catch (_) { idToken = null; } }
    sessionStorage.removeItem(key);
  }
  return idToken;
};

const platformLogout = async () => {
  const loading = document.getElementById('auth-loading');
  loading.hidden = false; loading.style.display = 'grid'; loading.textContent = 'Сбрасываем авторизацию…';
  const idToken = clearPlatformSessionStorage();
  try {
    const response = await fetch('/keycloak/auth/realms/irlix/.well-known/openid-configuration', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error(`OIDC discovery failed (${response.status})`);
    const discovery = await response.json();
    if (!discovery.end_session_endpoint) throw new Error('OIDC logout endpoint is unavailable');
    const params = new URLSearchParams({ client_id: 'irlix-services-web', post_logout_redirect_uri: `${window.location.origin}/` });
    if (idToken) params.set('id_token_hint', idToken);
    window.location.replace(`${discovery.end_session_endpoint}?${params.toString()}`);
  } catch (error) {
    console.error('Platform logout failed', error);
    loading.textContent = 'Локальная авторизация сброшена. Возвращаемся на Dashboard…';
    window.setTimeout(() => window.location.replace('/'), 800);
  }
};

const mountSidebar = () => {
  const target = document.getElementById('portal-sidebar');
  if (!target) return;
  createApp({
    render: () => h(UiAppSidebar, {
      section: 'dashboard',
      items: [{ id: 'dashboard', label: 'Дашборд', icon: 'dashboard' }],
      currentService: 'dashboard',
      currentUser: auth.user || {},
      ariaLabel: 'Навигация Dashboard',
      'onUpdate:section': () => {},
      onLogout: () => auth.logout(),
    }),
  }).mount(target);
};

const loadPlatformAccess = async () => {
  try {
    const response = await auth.fetch('/api/employees/access/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error(`Employees access lookup failed (${response.status})`);
    const payload = await response.json();
    return payload?.data || null;
  } catch (error) {
    console.error('Dashboard access lookup failed', error);
    return null;
  }
};

const isPlatformAdmin = (access) => Array.isArray(access?.roles) && access.roles.includes('platform-admin');
const showPage = (page) => {
  ['dashboard-page', 'migration-page', 'forbidden-page'].forEach((id) => {
    const element = document.getElementById(id);
    if (element) element.hidden = id !== page;
  });
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
const escapeAttr = escapeHtml;
const formatDate = (value) => {
  if (!value) return '—';
  const date = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'medium' });
};
const statusLabel = (status) => ({ queued: 'В очереди', running: 'Выполняется', completed: 'Готово', conflicts: 'Есть конфликты', failed: 'Ошибка' }[status] || status || '—');
const modeLabel = (mode) => ({ inspect: 'Inspect', 'dry-run': 'Dry run', migrate: 'Перенос', validate: 'Validate' }[mode] || mode || '—');
const statusClass = (status) => {
  if (status === 'completed') return 'ok';
  if (status === 'queued' || status === 'running') return 'run';
  if (status === 'conflicts') return 'warn';
  if (status === 'failed') return 'bad';
  return '';
};

const showToast = (message, error = false) => {
  const toast = document.getElementById('migration-toast');
  if (!toast) return;
  toast.textContent = message;
  toast.className = `toast${error ? ' error' : ''}`;
  toast.hidden = false;
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => { toast.hidden = true; }, 4200);
};

const migrationApi = async (path, options = {}) => {
  const headers = { Accept: 'application/json', ...(options.headers || {}) };
  if (options.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
  const response = await auth.fetch(`/api/migration${path}`, { cache: 'no-store', ...options, headers });
  let payload = null;
  try { payload = await response.json(); } catch (_) { payload = null; }
  if (!response.ok) throw new Error(payload?.message || `Migration API error (${response.status})`);
  return payload?.data ?? payload;
};

const hasUsableDryRun = (module) => {
  if (!module?.connection?.verified_at) return false;
  return (module.recent_runs || []).some((run) => run.mode === 'dry-run'
    && ['completed', 'conflicts'].includes(run.status)
    && String(run.started_at || '') >= String(module.connection.verified_at || ''));
};

const renderRunPanel = (run) => {
  if (!run) return '<div class="run-panel"><div class="empty">Запусков для этого сервиса пока нет.</div></div>';
  const active = ['queued', 'running'].includes(run.status);
  return `<div class="run-panel${active ? ' active' : ''}" data-open-run="${run.id}">
    <div class="run-top"><div><div class="run-name">${escapeHtml(modeLabel(run.mode))} · <span class="chip ${statusClass(run.status)}">${escapeHtml(statusLabel(run.status))}</span></div><div class="run-message">${escapeHtml(run.progress_message || run.error || 'Операция завершена.')}</div></div><div class="run-time">${escapeHtml(formatDate(run.started_at))}</div></div>
    ${active ? '<progress class="progress-indeterminate"></progress>' : ''}
    <div class="counters"><div class="counter"><strong>${Number(run.processed_count || 0)}</strong><span>обработано</span></div><div class="counter"><strong>${Number(run.success_count || run.mapped_count || 0)}</strong><span>успешно / mappings</span></div><div class="counter"><strong>${Number(run.warning_count || 0)}</strong><span>warnings</span></div><div class="counter"><strong>${Number(run.conflict_count || 0)}</strong><span>conflicts</span></div></div>
  </div>`;
};

const renderModule = (module) => {
  if (module.status !== 'implemented') {
    return `<article class="migration-module planned"><div class="module-head"><div><h2>${escapeHtml(module.title)}</h2><p>${escapeHtml(module.description)}</p></div><span class="chip">Запланирован</span></div><div class="migration-note">Модуль появится здесь автоматически после добавления схемы и правил переноса. Ядро Migration Service менять не потребуется.</div></article>`;
  }

  const profile = module.connection || {};
  const verified = profile.verification_status === 'verified';
  const active = Boolean(module.active_run);
  const dryReady = hasUsableDryRun(module);
  const latest = module.active_run || module.latest_run;
  const connectionChip = !module.connection
    ? '<span class="chip">Доступ не задан</span>'
    : verified
      ? `<span class="chip ok">Read-only подтверждён · ${escapeHtml(profile.verified_user || profile.username || '')}</span>`
      : profile.verification_status === 'failed'
        ? '<span class="chip bad">Проверка не пройдена</span>'
        : '<span class="chip warn">Нужна проверка</span>';

  return `<article class="migration-module" data-module="${escapeAttr(module.key)}">
    <div class="module-head"><div><h2>${escapeHtml(module.title)}</h2><p>${escapeHtml(module.description)}</p><div class="chips">${connectionChip}${active ? '<span class="chip run">Сейчас выполняется</span>' : ''}</div></div></div>
    <div class="connection-box">
      <div class="section-title">Доступ к старой БД</div>
      <form class="migration-connection-form" data-service="${escapeAttr(module.key)}">
        <div class="connection-grid">
          <div class="field"><label>Host</label><input name="host" autocomplete="off" value="${escapeAttr(profile.host || '')}" placeholder="10.0.0.15" ${active ? 'disabled' : ''}></div>
          <div class="field"><label>Port</label><input name="port" type="number" min="1" max="65535" value="${escapeAttr(profile.port || 5432)}" ${active ? 'disabled' : ''}></div>
          <div class="field"><label>Database</label><input name="database" autocomplete="off" value="${escapeAttr(profile.database || '')}" placeholder="legacy_${escapeAttr(module.key)}" ${active ? 'disabled' : ''}></div>
          <div class="field"><label>User</label><input name="username" autocomplete="off" value="${escapeAttr(profile.username || '')}" placeholder="migration_reader" ${active ? 'disabled' : ''}></div>
          <div class="field wide"><label>Password ${profile.password_configured ? '· сохранён зашифрованно' : ''}</label><input name="password" type="password" autocomplete="new-password" placeholder="${profile.password_configured ? 'Оставьте пустым, чтобы не менять' : 'Пароль read-only пользователя'}" ${active ? 'disabled' : ''}></div>
          <div class="field"><label>SSL mode</label><select name="sslmode" ${active ? 'disabled' : ''}>${['disable','allow','prefer','require','verify-ca','verify-full'].map((mode) => `<option value="${mode}"${(profile.sslmode || 'prefer') === mode ? ' selected' : ''}>${mode}</option>`).join('')}</select></div>
        </div>
        <label class="readonly-check"><input name="readonly_acknowledged" type="checkbox" ${profile.readonly_acknowledged ? 'checked' : ''} ${active ? 'disabled' : ''}><span>Подтверждаю, что это отдельный пользователь PostgreSQL только для чтения. Migration Service всё равно самостоятельно проверит реальные привилегии перед работой.</span></label>
        <div class="button-row"><button class="btn" type="submit" ${active ? 'disabled' : ''}>Сохранить доступ</button><button class="btn primary" type="button" data-action="verify" data-service="${escapeAttr(module.key)}" ${(!module.connection || active) ? 'disabled' : ''}>Проверить подключение</button>${module.connection ? `<button class="btn danger" type="button" data-action="delete-connection" data-service="${escapeAttr(module.key)}" ${active ? 'disabled' : ''}>Удалить доступ</button>` : ''}</div>
        ${profile.verification_message ? `<div class="operation-hint">${escapeHtml(profile.verification_message)}${profile.verified_at ? ` · ${escapeHtml(formatDate(profile.verified_at))}` : ''}</div>` : ''}
      </form>
    </div>
    <div class="operations">
      <div class="section-title">Операции</div>
      <div class="button-row">
        <button class="btn" type="button" data-action="run" data-mode="inspect" data-service="${escapeAttr(module.key)}" ${(!verified || active) ? 'disabled' : ''}>Inspect</button>
        <button class="btn" type="button" data-action="run" data-mode="dry-run" data-service="${escapeAttr(module.key)}" ${(!verified || active) ? 'disabled' : ''}>Dry run</button>
        <button class="btn primary" type="button" data-action="run" data-mode="migrate" data-service="${escapeAttr(module.key)}" ${(!verified || !dryReady || active) ? 'disabled' : ''}>Перенести</button>
        <button class="btn" type="button" data-action="run" data-mode="validate" data-service="${escapeAttr(module.key)}" ${(!verified || active) ? 'disabled' : ''}>Validate</button>
      </div>
      <div class="operation-hint">${verified ? (dryReady ? 'Реальный перенос разрешён: есть актуальный Dry run.' : 'Перед реальным переносом выполните Dry run после последней проверки подключения.') : 'Сначала сохраните доступ и пройдите read-only проверку.'}</div>
      ${renderRunPanel(latest)}
    </div>
  </article>`;
};

const renderHistory = () => {
  const target = document.getElementById('migration-history-table');
  if (!target) return;
  const runs = migrationState?.recent_runs || [];
  if (!runs.length) { target.innerHTML = '<div class="empty">Запусков пока нет.</div>'; return; }
  target.innerHTML = `<table class="history-table"><thead><tr><th>ID</th><th>Сервис</th><th>Операция</th><th>Статус</th><th>Фаза</th><th>Обработано</th><th>Warnings</th><th>Conflicts</th><th>Начало</th></tr></thead><tbody>${runs.map((run) => `<tr data-run-id="${run.id}"><td>#${run.id}</td><td>${escapeHtml(run.service)}</td><td>${escapeHtml(modeLabel(run.mode))}</td><td><span class="chip ${statusClass(run.status)}">${escapeHtml(statusLabel(run.status))}</span></td><td>${escapeHtml(run.progress_phase || '—')}</td><td>${Number(run.processed_count || 0)}</td><td>${Number(run.warning_count || 0)}</td><td>${Number(run.conflict_count || 0)}</td><td>${escapeHtml(formatDate(run.started_at))}</td></tr>`).join('')}</tbody></table>`;
};

const renderMigrationState = () => {
  const modules = document.getElementById('migration-modules');
  if (modules) modules.innerHTML = (migrationState?.modules || []).map(renderModule).join('') || '<div class="empty">Модули не найдены.</div>';
  renderHistory();
  const activeRuns = (migrationState?.recent_runs || []).filter((run) => ['queued', 'running'].includes(run.status));
  const dot = document.getElementById('migration-live-dot');
  const text = document.getElementById('migration-live-text');
  const poll = document.getElementById('migration-poll-label');
  if (dot) dot.classList.toggle('active', activeRuns.length > 0);
  if (text) text.textContent = activeRuns.length ? `Активных операций: ${activeRuns.length}` : 'Активных операций нет';
  if (poll) poll.textContent = activeRuns.length ? 'Обновление каждые 2 сек' : 'Обновление каждые 10 сек';
};

const renderRunDetails = async (runId) => {
  const target = document.getElementById('migration-run-details');
  if (!target || !runId) return;
  target.innerHTML = '<div class="empty">Загружаем детали запуска…</div>';
  try {
    const run = await migrationApi(`/runs/${runId}`);
    selectedRunId = run.id;
    const events = run.events || [];
    const conflicts = run.conflicts || [];
    target.innerHTML = `<div class="run-details">
      <div class="detail-box"><h3>События run #${run.id}</h3><div class="event-list">${events.length ? events.map((event) => `<div class="event"><span class="event-time">${escapeHtml(formatDate(event.created_at))}</span> · <strong>${escapeHtml(event.event)}</strong><br>${escapeHtml(event.message)}</div>`).join('') : '<div class="empty">Событий нет.</div>'}</div></div>
      <div class="detail-box"><h3>Warnings / conflicts</h3><div class="conflict-list">${conflicts.length ? conflicts.map((item) => `<div class="conflict ${escapeAttr(item.severity)}"><strong>${escapeHtml(item.code)}</strong> · ${escapeHtml(item.entity_type)}${item.legacy_id ? ` #${escapeHtml(item.legacy_id)}` : ''}<br>${escapeHtml(item.message)}</div>`).join('') : '<div class="empty">Конфликтов нет.</div>'}</div></div>
      <div class="detail-box detail-summary"><h3>Итог / summary</h3><pre>${escapeHtml(JSON.stringify({ status: run.status, error: run.error, summary: run.summary, counters: { processed: run.processed_count, success: run.success_count, warnings: run.warning_count, conflicts: run.conflict_count }, heartbeat_at: run.heartbeat_at }, null, 2))}</pre></div>
    </div>`;
  } catch (error) {
    target.innerHTML = `<div class="empty">${escapeHtml(error.message)}</div>`;
  }
};

const scheduleMigrationPoll = () => {
  window.clearTimeout(migrationPollTimer);
  const active = (migrationState?.recent_runs || []).some((run) => ['queued', 'running'].includes(run.status));
  migrationPollTimer = window.setTimeout(() => loadMigrationState({ silent: true }), active ? 2000 : 10000);
};

const loadMigrationState = async ({ silent = false } = {}) => {
  if (migrationLoading) return;
  migrationLoading = true;
  try {
    migrationState = await migrationApi('/state');
    renderMigrationState();
    const active = (migrationState?.recent_runs || []).find((run) => ['queued', 'running'].includes(run.status));
    if (!selectedRunId && active) selectedRunId = active.id;
    if (selectedRunId) await renderRunDetails(selectedRunId);
  } catch (error) {
    if (!silent) showToast(error.message, true);
    const text = document.getElementById('migration-live-text');
    if (text) text.textContent = 'Migration API недоступен';
  } finally {
    migrationLoading = false;
    scheduleMigrationPoll();
  }
};

const saveConnection = async (form) => {
  const service = form.dataset.service;
  const data = Object.fromEntries(new FormData(form).entries());
  data.port = Number(data.port || 5432);
  data.readonly_acknowledged = form.elements.readonly_acknowledged.checked;
  try {
    await migrationApi(`/services/${service}/connection`, { method: 'PUT', body: JSON.stringify(data) });
    showToast(`${service}: доступ сохранён. Теперь выполните проверку read-only.`);
    await loadMigrationState();
  } catch (error) { showToast(error.message, true); }
};

const verifyConnection = async (service) => {
  try {
    showToast(`${service}: проверяем подключение и реальные права пользователя…`);
    await migrationApi(`/services/${service}/verify`, { method: 'POST' });
    showToast(`${service}: подключение безопасно, read-only подтверждён.`);
    await loadMigrationState();
  } catch (error) {
    showToast(error.message, true);
    await loadMigrationState({ silent: true });
  }
};

const deleteConnection = async (service) => {
  if (!window.confirm(`Удалить сохранённый доступ к legacy DB сервиса ${service}?`)) return;
  try {
    await migrationApi(`/services/${service}/connection`, { method: 'DELETE' });
    showToast(`${service}: доступ удалён.`);
    await loadMigrationState();
  } catch (error) { showToast(error.message, true); }
};

const startMigrationRun = async (service, mode) => {
  let confirm = false;
  if (mode === 'migrate') {
    confirm = window.confirm(`Запустить РЕАЛЬНЫЙ перенос ${service}?\n\nLegacy DB останется read-only. Изменения будут записываться только в новую систему.`);
    if (!confirm) return;
  }
  try {
    const run = await migrationApi(`/services/${service}/runs`, { method: 'POST', body: JSON.stringify({ mode, confirm }) });
    selectedRunId = run.id;
    showToast(`${service}: ${modeLabel(mode)} поставлен в очередь (#${run.id}).`);
    await loadMigrationState();
  } catch (error) { showToast(error.message, true); }
};

const bindMigrationUi = () => {
  const refresh = document.getElementById('migration-refresh');
  if (refresh) refresh.addEventListener('click', () => loadMigrationState());
  const modules = document.getElementById('migration-modules');
  if (modules) {
    modules.addEventListener('submit', (event) => {
      const form = event.target.closest('.migration-connection-form');
      if (!form) return;
      event.preventDefault();
      saveConnection(form);
    });
    modules.addEventListener('click', (event) => {
      const button = event.target.closest('[data-action]');
      if (button && !button.disabled) {
        const { action, service, mode } = button.dataset;
        if (action === 'verify') verifyConnection(service);
        if (action === 'delete-connection') deleteConnection(service);
        if (action === 'run') startMigrationRun(service, mode);
        return;
      }
      const panel = event.target.closest('[data-open-run]');
      if (panel) { selectedRunId = Number(panel.dataset.openRun); renderRunDetails(selectedRunId); }
    });
  }
  const history = document.getElementById('migration-history-table');
  if (history) history.addEventListener('click', (event) => {
    const row = event.target.closest('[data-run-id]');
    if (!row) return;
    selectedRunId = Number(row.dataset.runId);
    renderRunDetails(selectedRunId);
  });
};

const showDashboard = async () => {
  const loading = document.getElementById('auth-loading');
  const shell = document.getElementById('portal-shell');
  loading.hidden = true; loading.style.display = 'none'; shell.hidden = false; mountSidebar();
  const access = await loadPlatformAccess();
  const platformAdmin = isPlatformAdmin(access);
  const migrationCard = document.getElementById('migration-card');
  if (migrationCard) migrationCard.hidden = !platformAdmin;

  const migrationRoute = window.location.pathname === '/migration' || window.location.pathname.startsWith('/migration/');
  if (!migrationRoute) { showPage('dashboard-page'); return; }
  if (!platformAdmin) { showPage('forbidden-page'); return; }
  if (window.location.pathname === '/migration') window.history.replaceState({}, '', '/migration/');
  showPage('migration-page');
  bindMigrationUi();
  await loadMigrationState();
};

const start = async () => {
  if (window.location.pathname === '/auth/logout' || window.location.pathname === '/auth/logout/') { await platformLogout(); return; }
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    await showDashboard();
  } catch (error) {
    console.error('Dashboard OIDC initialization failed', error);
    const loading = document.getElementById('auth-loading');
    const detail = error instanceof Error ? error.message : String(error || 'Unknown authentication error');
    loading.innerHTML = `<strong>Не удалось завершить авторизацию.</strong><br><span style="color:#667085">${escapeHtml(detail)}</span>`;
  }
};

start();
