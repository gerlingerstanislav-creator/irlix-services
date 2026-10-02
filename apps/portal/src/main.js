import { createApp, h } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { createBrowserAuth } from '@irlix/auth';
import '@irlix/ui/styles/base.css';

const auth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/' });
let migrationState = null;
let migrationPollTimer = null;
let migrationLoading = false;
let migrationRefreshPending = false;
const migrationConnectionDrafts = new Map();
const migrationPendingActions = new Map();
const migrationRunDetails = new Map();
const migrationSelectedRunIds = new Map();

const ensureMigrationBusyStyles = () => {
  if (document.getElementById('migration-busy-styles')) return;
  const style = document.createElement('style');
  style.id = 'migration-busy-styles';
  style.textContent = `
    .migration-module.pending{border-color:#9fcfc2;box-shadow:0 0 0 3px rgba(14,142,112,.08),0 8px 24px rgba(20,37,63,.05)}
    .migration-wait{display:flex;align-items:center;gap:8px;margin-top:12px;padding:10px 12px;border:1px solid #b9ded3;border-radius:9px;background:#f1fbf8;color:#08775d;font-size:11px;font-weight:700}
    .migration-spinner{width:14px;height:14px;flex:0 0 14px;border:2px solid rgba(14,142,112,.22);border-top-color:#0e8e70;border-radius:50%;animation:migration-spin .7s linear infinite}
    .btn.busy{position:relative;padding-left:30px;opacity:.82!important}
    .btn.busy::before{content:'';position:absolute;left:10px;top:9px;width:12px;height:12px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:migration-spin .7s linear infinite}
    .module-event-box{margin-top:14px;padding-top:14px;border-top:1px solid #edf0f2}
    .module-event-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:7px}
    .module-event-head strong{font-size:11px;color:#475467}
    .module-event-head span{font-size:9px;color:#98a2b3}
    .module-event-log{height:188px;overflow:auto;padding:7px 9px;border:1px solid #e5e9ec;border-radius:9px;background:#fafbfb;font:10px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace;scrollbar-gutter:stable}
    .module-event-line{padding:4px 0;border-bottom:1px solid #edf0f2;color:#475467;white-space:pre-wrap;overflow-wrap:anywhere}
    .module-event-line:last-child{border-bottom:0}.module-event-line.failed{color:#b42318}.module-event-time{color:#98a2b3}.module-event-empty{display:grid;height:100%;place-items:center;color:#98a2b3;font:11px/1.4 Inter,ui-sans-serif,system-ui,sans-serif;text-align:center}
    #migration-run-details{display:none!important}
    @keyframes migration-spin{to{transform:rotate(360deg)}}
  `;
  document.head.appendChild(style);
};

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
  createApp({ render: () => h(UiAppSidebar, {
    section: 'dashboard', items: [{ id: 'dashboard', label: 'Дашборд', icon: 'dashboard' }], currentService: 'dashboard', currentUser: auth.user || {}, ariaLabel: 'Навигация Dashboard',
    'onUpdate:section': () => {}, onLogout: () => auth.logout(),
  }) }).mount(target);
};

const loadPlatformAccess = async () => {
  try {
    const response = await auth.fetch('/api/employees/access/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error(`Employees access lookup failed (${response.status})`);
    const payload = await response.json();
    return payload?.data || null;
  } catch (error) { console.error('Dashboard access lookup failed', error); return null; }
};

const isPlatformAdmin = (access) => Array.isArray(access?.roles) && access.roles.includes('platform-admin');
const showPage = (page) => ['dashboard-page', 'migration-page', 'forbidden-page'].forEach((id) => { const element = document.getElementById(id); if (element) element.hidden = id !== page; });
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
const escapeAttr = escapeHtml;
const formatDate = (value) => { if (!value) return '—'; const date = new Date(String(value).replace(' ', 'T')); return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'medium' }); };
const statusLabel = (status) => ({ queued: 'В очереди', running: 'Выполняется', completed: 'Готово', conflicts: 'Есть конфликты', failed: 'Ошибка' }[status] || status || '—');
const modeLabel = (mode) => ({ inspect: 'Inspect', 'dry-run': 'Dry run', migrate: 'Перенос', validate: 'Validate' }[mode] || mode || '—');
const statusClass = (status) => status === 'completed' ? 'ok' : ['queued', 'running'].includes(status) ? 'run' : status === 'conflicts' ? 'warn' : status === 'failed' ? 'bad' : '';

const showToast = (message, error = false) => {
  const toast = document.getElementById('migration-toast');
  if (!toast) return;
  toast.textContent = message; toast.className = `toast${error ? ' error' : ''}`; toast.hidden = false;
  window.clearTimeout(showToast.timer); showToast.timer = window.setTimeout(() => { toast.hidden = true; }, 4200);
};

const migrationApi = async (path, options = {}) => {
  const headers = { Accept: 'application/json', ...(options.headers || {}) };
  if (options.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
  const response = await auth.fetch(`/api/migration${path}`, { cache: 'no-store', ...options, headers });
  let payload = null; try { payload = await response.json(); } catch (_) { payload = null; }
  if (!response.ok) throw new Error(payload?.message || `Migration API error (${response.status})`);
  return payload?.data ?? payload;
};

const hasUsableDryRun = (module) => {
  if (!module?.connection?.verified_at) return false;
  return (module.recent_runs || []).some((run) => run.mode === 'dry-run' && ['completed', 'conflicts'].includes(run.status) && String(run.started_at || '') >= String(module.connection.verified_at || ''));
};

const pendingButton = (pending, action, normal, busy, mode = null) => ({ label: pending?.action === action && (!mode || pending.mode === mode) ? busy : normal, busy: pending?.action === action && (!mode || pending.mode === mode) });

const renderRunPanel = (run) => {
  if (!run) return '<div class="run-panel"><div class="empty">Запусков для этого сервиса пока нет.</div></div>';
  const active = ['queued', 'running'].includes(run.status);
  return `<div class="run-panel${active ? ' active' : ''}" data-open-run="${run.id}">
    <div class="run-top"><div><div class="run-name">${escapeHtml(modeLabel(run.mode))} · <span class="chip ${statusClass(run.status)}">${escapeHtml(statusLabel(run.status))}</span></div><div class="run-message">${escapeHtml(run.progress_message || run.error || 'Операция завершена.')}</div></div><div class="run-time">${escapeHtml(formatDate(run.started_at))}</div></div>
    ${active ? '<progress class="progress-indeterminate"></progress>' : ''}
    <div class="counters"><div class="counter"><strong>${Number(run.processed_count || 0)}</strong><span>обработано</span></div><div class="counter"><strong>${Number(run.success_count || run.mapped_count || 0)}</strong><span>успешно / mappings</span></div><div class="counter"><strong>${Number(run.warning_count || 0)}</strong><span>warnings</span></div><div class="counter"><strong>${Number(run.conflict_count || 0)}</strong><span>conflicts</span></div></div>
  </div>`;
};

const renderServiceEventLog = (module) => {
  const detail = migrationRunDetails.get(module.key) || null;
  const fallback = module.active_run || module.latest_run || null;
  const run = detail || fallback;
  if (!run) return `<div class="module-event-box"><div class="module-event-head"><strong>События</strong><span>последний запуск</span></div><div class="module-event-log"><div class="module-event-empty">Событий для этого сервиса пока нет.</div></div></div>`;
  const events = Array.isArray(detail?.events) ? detail.events : [];
  const eventLines = events.map((event) => `<div class="module-event-line${event.event === 'failed' ? ' failed' : ''}"><span class="module-event-time">${escapeHtml(formatDate(event.created_at))}</span> · <strong>${escapeHtml(event.event)}</strong> · ${escapeHtml(event.message || '')}</div>`).join('');
  const errorLine = detail?.error && !events.some((event) => String(event.message || '') === String(detail.error)) ? `<div class="module-event-line failed"><strong>error</strong> · ${escapeHtml(detail.error)}</div>` : '';
  return `<div class="module-event-box"><div class="module-event-head"><strong>События run #${Number(run.id)}</strong><span>${escapeHtml(modeLabel(run.mode))} · ${escapeHtml(statusLabel(run.status))}</span></div><div class="module-event-log" data-event-log="${escapeAttr(module.key)}">${eventLines || errorLine ? `${eventLines}${errorLine}` : '<div class="module-event-empty">Детали запуска загружаются…</div>'}</div></div>`;
};

const renderModule = (module) => {
  if (module.status !== 'implemented') return `<article class="migration-module planned"><div class="module-head"><div><h2>${escapeHtml(module.title)}</h2><p>${escapeHtml(module.description)}</p></div><span class="chip">Запланирован</span></div><div class="migration-note">Модуль появится здесь автоматически после добавления схемы и правил переноса. Ядро Migration Service менять не потребуется.</div></article>`;
  const profile = module.connection || {};
  const draft = migrationConnectionDrafts.get(module.key);
  const formProfile = draft ? { ...profile, ...draft } : profile;
  const verified = profile.verification_status === 'verified';
  const active = Boolean(module.active_run);
  const pending = migrationPendingActions.get(module.key) || null;
  const locked = active || Boolean(pending);
  const dryReady = hasUsableDryRun(module);
  const latest = module.active_run || module.latest_run;
  const connectionChip = !module.connection ? '<span class="chip">Доступ не задан</span>' : verified ? `<span class="chip ok">Read-only подтверждён · ${escapeHtml(profile.verified_user || profile.username || '')}</span>` : profile.verification_status === 'failed' ? '<span class="chip bad">Проверка не пройдена</span>' : '<span class="chip warn">Нужна проверка</span>';
  const save = pendingButton(pending, 'save', 'Сохранить доступ', 'Сохраняем…');
  const reachability = pendingButton(pending, 'reachability', 'Проверить доступность сервера', 'Проверяем сервер…');
  const verify = pendingButton(pending, 'verify', 'Проверить подключение', 'Проверяем права…');
  const remove = pendingButton(pending, 'delete', 'Удалить доступ', 'Удаляем…');
  const inspect = pendingButton(pending, 'run', 'Inspect', 'Запускаем Inspect…', 'inspect');
  const dryRun = pendingButton(pending, 'run', 'Dry run', 'Запускаем Dry run…', 'dry-run');
  const migrate = pendingButton(pending, 'run', 'Перенести', 'Запускаем перенос…', 'migrate');
  const validate = pendingButton(pending, 'run', 'Validate', 'Запускаем Validate…', 'validate');
  const busyClass = (button) => button.busy ? ' busy' : '';

  return `<article class="migration-module${pending ? ' pending' : ''}" data-module="${escapeAttr(module.key)}">
    <div class="module-head"><div><h2>${escapeHtml(module.title)}</h2><p>${escapeHtml(module.description)}</p><div class="chips">${connectionChip}${active ? '<span class="chip run">Сейчас выполняется</span>' : ''}${pending ? '<span class="chip run">Ждём ответ сервера</span>' : ''}</div></div></div>
    ${pending ? `<div class="migration-wait"><span class="migration-spinner"></span><span>${escapeHtml(pending.label)}. Дождитесь ответа — повторные действия временно заблокированы.</span></div>` : ''}
    <div class="connection-box"><div class="section-title">Доступ к старой БД</div>
      <form class="migration-connection-form" data-service="${escapeAttr(module.key)}">
        <div class="connection-grid">
          <div class="field"><label>Host</label><input name="host" autocomplete="off" value="${escapeAttr(formProfile.host || '')}" placeholder="10.0.0.15" ${locked ? 'disabled' : ''}></div>
          <div class="field"><label>Port</label><input name="port" type="number" min="1" max="65535" value="${escapeAttr(formProfile.port || 5432)}" ${locked ? 'disabled' : ''}></div>
          <div class="field"><label>Database</label><input name="database" autocomplete="off" value="${escapeAttr(formProfile.database || '')}" placeholder="legacy_${escapeAttr(module.key)}" ${locked ? 'disabled' : ''}></div>
          <div class="field"><label>User</label><input name="username" autocomplete="off" value="${escapeAttr(formProfile.username || '')}" placeholder="migration_reader" ${locked ? 'disabled' : ''}></div>
          <div class="field wide"><label>Password ${profile.password_configured ? '· сохранён зашифрованно' : ''}</label><input name="password" type="password" autocomplete="new-password" value="${escapeAttr(formProfile.password || '')}" placeholder="${profile.password_configured ? 'Оставьте пустым, чтобы не менять' : 'Пароль read-only пользователя'}" ${locked ? 'disabled' : ''}></div>
          <div class="field"><label>SSL mode</label><select name="sslmode" ${locked ? 'disabled' : ''}>${['disable','allow','prefer','require','verify-ca','verify-full'].map((mode) => `<option value="${mode}"${(formProfile.sslmode || 'disable') === mode ? ' selected' : ''}>${mode}</option>`).join('')}</select></div>
        </div>
        <label class="readonly-check"><input name="readonly_acknowledged" type="checkbox" ${formProfile.readonly_acknowledged ? 'checked' : ''} ${locked ? 'disabled' : ''}><span>Подтверждаю, что это отдельный пользователь PostgreSQL только для чтения. Migration Service всё равно самостоятельно проверит реальные привилегии перед работой.</span></label>
        <div class="button-row"><button class="btn${busyClass(save)}" type="submit" ${locked ? 'disabled' : ''}>${save.label}</button><button class="btn${busyClass(reachability)}" type="button" data-action="reachability" data-service="${escapeAttr(module.key)}" ${locked ? 'disabled' : ''}>${reachability.label}</button><button class="btn primary${busyClass(verify)}" type="button" data-action="verify" data-service="${escapeAttr(module.key)}" ${(!module.connection || locked) ? 'disabled' : ''}>${verify.label}</button>${module.connection ? `<button class="btn danger${busyClass(remove)}" type="button" data-action="delete-connection" data-service="${escapeAttr(module.key)}" ${locked ? 'disabled' : ''}>${remove.label}</button>` : ''}</div>
        ${profile.verification_message ? `<div class="operation-hint">${escapeHtml(profile.verification_message)}${profile.verified_at ? ` · ${escapeHtml(formatDate(profile.verified_at))}` : ''}</div>` : ''}
      </form>
    </div>
    <div class="operations"><div class="section-title">Операции</div><div class="button-row">
      <button class="btn${busyClass(inspect)}" type="button" data-action="run" data-mode="inspect" data-service="${escapeAttr(module.key)}" ${(!verified || locked) ? 'disabled' : ''}>${inspect.label}</button>
      <button class="btn${busyClass(dryRun)}" type="button" data-action="run" data-mode="dry-run" data-service="${escapeAttr(module.key)}" ${(!verified || locked) ? 'disabled' : ''}>${dryRun.label}</button>
      <button class="btn primary${busyClass(migrate)}" type="button" data-action="run" data-mode="migrate" data-service="${escapeAttr(module.key)}" ${(!verified || !dryReady || locked) ? 'disabled' : ''}>${migrate.label}</button>
      <button class="btn${busyClass(validate)}" type="button" data-action="run" data-mode="validate" data-service="${escapeAttr(module.key)}" ${(!verified || locked) ? 'disabled' : ''}>${validate.label}</button>
    </div><div class="operation-hint">${verified ? (dryReady ? 'Реальный перенос разрешён: есть актуальный Dry run.' : 'Перед реальным переносом выполните Dry run после последней проверки подключения.') : 'Сначала сохраните доступ и пройдите read-only проверку.'}</div>${renderRunPanel(latest)}</div>
    ${renderServiceEventLog(module)}
  </article>`;
};

const renderHistory = () => {
  const target = document.getElementById('migration-history-table'); if (!target) return;
  const runs = migrationState?.recent_runs || [];
  if (!runs.length) { target.innerHTML = '<div class="empty">Запусков пока нет.</div>'; return; }
  target.innerHTML = `<table class="history-table"><thead><tr><th>ID</th><th>Сервис</th><th>Операция</th><th>Статус</th><th>Фаза</th><th>Обработано</th><th>Warnings</th><th>Conflicts</th><th>Начало</th></tr></thead><tbody>${runs.map((run) => `<tr data-run-id="${run.id}" data-service="${escapeAttr(run.service)}"><td>#${run.id}</td><td>${escapeHtml(run.service)}</td><td>${escapeHtml(modeLabel(run.mode))}</td><td><span class="chip ${statusClass(run.status)}">${escapeHtml(statusLabel(run.status))}</span></td><td>${escapeHtml(run.progress_phase || '—')}</td><td>${Number(run.processed_count || 0)}</td><td>${Number(run.warning_count || 0)}</td><td>${Number(run.conflict_count || 0)}</td><td>${escapeHtml(formatDate(run.started_at))}</td></tr>`).join('')}</tbody></table>`;
};

const updateRefreshButton = () => {
  const refresh = document.getElementById('migration-refresh'); if (!refresh) return;
  refresh.disabled = migrationRefreshPending || migrationLoading;
  refresh.classList.toggle('busy', migrationRefreshPending);
  refresh.textContent = migrationRefreshPending ? 'Обновляем…' : 'Обновить';
};

const renderMigrationState = () => {
  const modules = document.getElementById('migration-modules'); if (modules) modules.innerHTML = (migrationState?.modules || []).map(renderModule).join('') || '<div class="empty">Модули не найдены.</div>';
  renderHistory(); updateRefreshButton();
  const activeRuns = (migrationState?.recent_runs || []).filter((run) => ['queued', 'running'].includes(run.status));
  const dot = document.getElementById('migration-live-dot'); const text = document.getElementById('migration-live-text'); const poll = document.getElementById('migration-poll-label');
  if (dot) dot.classList.toggle('active', activeRuns.length > 0 || migrationPendingActions.size > 0 || migrationRefreshPending);
  if (text) text.textContent = migrationPendingActions.size ? `Ждём ответ сервера: ${migrationPendingActions.size}` : activeRuns.length ? `Активных операций: ${activeRuns.length}` : migrationRefreshPending ? 'Обновляем состояние…' : 'Активных операций нет';
  if (poll) poll.textContent = activeRuns.length ? 'Обновление каждые 2 сек' : 'Обновление каждые 10 сек';
};

const loadRunDetails = async (service, runId, { rerender = true } = {}) => {
  if (!service || !runId) return;
  try {
    const run = await migrationApi(`/runs/${runId}`);
    migrationSelectedRunIds.set(service, run.id);
    migrationRunDetails.set(service, run);
    if (rerender) renderMigrationState();
  } catch (error) {
    migrationRunDetails.set(service, { id: runId, service, mode: '', status: 'failed', events: [], error: error.message });
    if (rerender) renderMigrationState();
  }
};

const refreshModuleRunDetails = async () => {
  const modules = (migrationState?.modules || []).filter((module) => module.status === 'implemented');
  await Promise.all(modules.map(async (module) => {
    const selectedId = migrationSelectedRunIds.get(module.key);
    const latestId = module.active_run?.id || module.latest_run?.id || null;
    const runId = selectedId || latestId;
    if (!runId) { migrationRunDetails.delete(module.key); return; }
    if (selectedId && !((module.recent_runs || []).some((run) => Number(run.id) === Number(selectedId)))) migrationSelectedRunIds.delete(module.key);
    await loadRunDetails(module.key, migrationSelectedRunIds.get(module.key) || latestId, { rerender: false });
  }));
  renderMigrationState();
  document.querySelectorAll('[data-event-log]').forEach((log) => { log.scrollTop = log.scrollHeight; });
};

const scheduleMigrationPoll = () => { window.clearTimeout(migrationPollTimer); const active = (migrationState?.recent_runs || []).some((run) => ['queued', 'running'].includes(run.status)); migrationPollTimer = window.setTimeout(() => loadMigrationState({ silent: true }), active ? 2000 : 10000); };

const loadMigrationState = async ({ silent = false, userInitiated = false } = {}) => {
  if (migrationLoading) return;
  migrationLoading = true; if (userInitiated) migrationRefreshPending = true; updateRefreshButton();
  if (migrationState) renderMigrationState();
  try {
    migrationState = await migrationApi('/state'); renderMigrationState(); await refreshModuleRunDetails();
  } catch (error) { if (!silent) showToast(error.message, true); const text = document.getElementById('migration-live-text'); if (text) text.textContent = 'Migration API недоступен'; }
  finally { migrationLoading = false; migrationRefreshPending = false; updateRefreshButton(); scheduleMigrationPoll(); }
};

const captureConnectionDraft = (form) => { const service = form?.dataset?.service; if (!service) return; const data = Object.fromEntries(new FormData(form).entries()); data.readonly_acknowledged = Boolean(form.elements.readonly_acknowledged?.checked); migrationConnectionDrafts.set(service, data); };

const withServicePending = async (service, pending, task) => {
  if (migrationPendingActions.has(service)) return;
  migrationPendingActions.set(service, pending); renderMigrationState();
  try { return await task(); } finally { migrationPendingActions.delete(service); renderMigrationState(); }
};

const saveConnection = async (form) => {
  const service = form.dataset.service; captureConnectionDraft(form); const data = { ...(migrationConnectionDrafts.get(service) || {}) }; data.port = Number(data.port || 5432);
  await withServicePending(service, { action: 'save', label: 'Сохраняем параметры подключения' }, async () => {
    try { await migrationApi(`/services/${service}/connection`, { method: 'PUT', body: JSON.stringify(data) }); migrationConnectionDrafts.delete(service); showToast(`${service}: доступ сохранён. Теперь выполните проверку read-only.`); await loadMigrationState(); }
    catch (error) { showToast(error.message, true); }
  });
};

const checkServerReachability = async (form) => {
  const service = form.dataset.service; captureConnectionDraft(form); const draft = migrationConnectionDrafts.get(service) || {}; const host = String(draft.host || '').trim(); const port = Number(draft.port || 5432);
  await withServicePending(service, { action: 'reachability', label: `Проверяем доступность ${host || 'сервера'}:${port}` }, async () => {
    try { const result = await migrationApi(`/services/${service}/reachability`, { method: 'POST', body: JSON.stringify({ host, port }) }); showToast(`${service}: сервер ${result.host}:${result.port} доступен по TCP · ${result.latency_ms} мс.`); }
    catch (error) { showToast(error.message, true); }
  });
};

const verifyConnection = async (service) => {
  await withServicePending(service, { action: 'verify', label: 'Проверяем подключение и реальные PostgreSQL-права' }, async () => {
    try { await migrationApi(`/services/${service}/verify`, { method: 'POST' }); showToast(`${service}: подключение безопасно, read-only подтверждён.`); await loadMigrationState(); }
    catch (error) { showToast(error.message, true); await loadMigrationState({ silent: true }); }
  });
};

const deleteConnection = async (service) => {
  if (!window.confirm(`Удалить сохранённый доступ к legacy DB сервиса ${service}?`)) return;
  await withServicePending(service, { action: 'delete', label: 'Удаляем сохранённый доступ' }, async () => {
    try { await migrationApi(`/services/${service}/connection`, { method: 'DELETE' }); migrationConnectionDrafts.delete(service); showToast(`${service}: доступ удалён.`); await loadMigrationState(); }
    catch (error) { showToast(error.message, true); }
  });
};

const startMigrationRun = async (service, mode) => {
  let confirm = false; if (mode === 'migrate') { confirm = window.confirm(`Запустить РЕАЛЬНЫЙ перенос ${service}?\n\nLegacy DB останется read-only. Изменения будут записываться только в новую систему.`); if (!confirm) return; }
  await withServicePending(service, { action: 'run', mode, label: `Передаём ${modeLabel(mode)} в очередь` }, async () => {
    try { const run = await migrationApi(`/services/${service}/runs`, { method: 'POST', body: JSON.stringify({ mode, confirm }) }); migrationSelectedRunIds.set(service, run.id); showToast(`${service}: ${modeLabel(mode)} поставлен в очередь (#${run.id}).`); await loadMigrationState(); }
    catch (error) { showToast(error.message, true); }
  });
};

const bindMigrationUi = () => {
  ensureMigrationBusyStyles();
  const refresh = document.getElementById('migration-refresh'); if (refresh) refresh.addEventListener('click', () => loadMigrationState({ userInitiated: true }));
  const modules = document.getElementById('migration-modules');
  if (modules) {
    const rememberDraft = (event) => { const form = event.target.closest('.migration-connection-form'); if (form) captureConnectionDraft(form); };
    modules.addEventListener('input', rememberDraft); modules.addEventListener('change', rememberDraft);
    modules.addEventListener('submit', (event) => { const form = event.target.closest('.migration-connection-form'); if (!form) return; event.preventDefault(); if (!migrationPendingActions.has(form.dataset.service)) saveConnection(form); });
    modules.addEventListener('click', (event) => {
      const button = event.target.closest('[data-action]');
      if (button) {
        const { action, service, mode } = button.dataset; if (button.disabled || migrationPendingActions.has(service)) return;
        if (action === 'reachability') { const form = button.closest('.migration-connection-form'); if (form) checkServerReachability(form); }
        if (action === 'verify') verifyConnection(service); if (action === 'delete-connection') deleteConnection(service); if (action === 'run') startMigrationRun(service, mode); return;
      }
      const panel = event.target.closest('[data-open-run]'); if (panel) { const module = panel.closest('[data-module]'); const service = module?.dataset?.module; const runId = Number(panel.dataset.openRun); if (service && runId) loadRunDetails(service, runId); }
    });
  }
  const history = document.getElementById('migration-history-table'); if (history) history.addEventListener('click', (event) => {
    const row = event.target.closest('[data-run-id]'); if (!row) return;
    const service = row.dataset.service; const runId = Number(row.dataset.runId); if (!service || !runId) return;
    loadRunDetails(service, runId).then(() => document.querySelector(`[data-module="${CSS.escape(service)}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
  });
};

const showDashboard = async () => {
  const loading = document.getElementById('auth-loading'); const shell = document.getElementById('portal-shell'); loading.hidden = true; loading.style.display = 'none'; shell.hidden = false; mountSidebar();
  const access = await loadPlatformAccess(); const platformAdmin = isPlatformAdmin(access); const migrationCard = document.getElementById('migration-card'); if (migrationCard) migrationCard.hidden = !platformAdmin;
  const migrationRoute = window.location.pathname === '/migration' || window.location.pathname.startsWith('/migration/'); if (!migrationRoute) { showPage('dashboard-page'); return; } if (!platformAdmin) { showPage('forbidden-page'); return; }
  if (window.location.pathname === '/migration') window.history.replaceState({}, '', '/migration/'); showPage('migration-page'); bindMigrationUi(); await loadMigrationState();
};

const start = async () => {
  if (window.location.pathname === '/auth/logout' || window.location.pathname === '/auth/logout/') { await platformLogout(); return; }
  try { const authenticated = await auth.init(); if (!authenticated) return; await showDashboard(); }
  catch (error) { console.error('Dashboard OIDC initialization failed', error); const loading = document.getElementById('auth-loading'); const detail = error instanceof Error ? error.message : String(error || 'Unknown authentication error'); loading.innerHTML = `<strong>Не удалось завершить авторизацию.</strong><br><span style="color:#667085">${escapeHtml(detail)}</span>`; }
};

start();