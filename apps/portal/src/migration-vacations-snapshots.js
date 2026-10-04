import { createBrowserAuth } from '@irlix/auth';

const auth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/' });
let state = null;
let selectedId = null;
let loading = false;
let timer = null;

const toast = (message, error = false) => {
  const node = document.getElementById('migration-toast');
  if (!node) return;
  node.textContent = message;
  node.className = `toast${error ? ' error' : ''}`;
  node.hidden = false;
  window.clearTimeout(toast.timer);
  toast.timer = window.setTimeout(() => { node.hidden = true; }, 4200);
};

const api = async (path, options = {}) => {
  const response = await auth.fetch(`/api/migration${path}`, {
    cache: 'no-store',
    headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}) },
    ...options,
  });
  let payload = null;
  try { payload = await response.json(); } catch (_) { payload = null; }
  if (!response.ok) throw new Error(payload?.message || `Migration API error (${response.status})`);
  return payload?.data ?? payload;
};

const formatDate = (value) => {
  const date = new Date(String(value || '').replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? String(value || '—') : date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'medium' });
};

const host = () => document.querySelector('.migration-module[data-module="vacations"] [data-migration-tab-panel="rollback"]');

const render = () => {
  const target = host();
  if (!target) return;
  const operation = state?.operation || {};
  const busy = loading || ['queued', 'running'].includes(operation.state);
  const snapshots = (state?.snapshots || []).filter((item) => !item.restored);
  if (!selectedId || !snapshots.some((item) => item.id === selectedId)) selectedId = snapshots[0]?.id || null;
  target.innerHTML = `<div class="migration-snapshot-box">
    <div class="section-title">Снимок перед переносом</div>
    <div class="operation-hint">Сохраняется только схема Vacations и metadata Migration Service. Employees при откате не изменяется.</div>
    ${snapshots.length ? `<label>Точка отката <select data-vacations-snapshot-select>${snapshots.map((item) => `<option value="${item.id}"${item.id === selectedId ? ' selected' : ''}>#${item.id} · ${formatDate(item.created_at)}</option>`).join('')}</select></label>` : '<div class="operation-hint">Готовой точки отката нет. Создайте снимок до реального переноса.</div>'}
    ${operation.state === 'failed' ? `<div class="operation-hint">Ошибка: ${operation.message || 'Операция не выполнена.'}</div>` : ''}
    ${operation.state === 'completed' ? `<div class="operation-hint">${operation.message || 'Готово'} #${operation.snapshot_id || ''}</div>` : ''}
    <div class="button-row">
      <button class="btn${busy && operation.action === 'snapshot' ? ' busy' : ''}" type="button" data-vacations-snapshot-create ${busy ? 'disabled' : ''}>${busy && operation.action === 'snapshot' ? 'Создаём снимок…' : 'Создать снимок'}</button>
      <button class="btn danger${busy && operation.action === 'restore' ? ' busy' : ''}" type="button" data-vacations-snapshot-restore ${busy || !selectedId ? 'disabled' : ''}>${busy && operation.action === 'restore' ? 'Восстанавливаем…' : 'Откатить к снимку'}</button>
      <button class="btn danger" type="button" data-vacations-snapshot-delete ${busy || !selectedId ? 'disabled' : ''}>Удалить точку отката</button>
    </div>
  </div>`;

  target.querySelector('[data-vacations-snapshot-select]')?.addEventListener('change', (event) => { selectedId = event.target.value; });
  target.querySelector('[data-vacations-snapshot-create]')?.addEventListener('click', createSnapshot);
  target.querySelector('[data-vacations-snapshot-restore]')?.addEventListener('click', restoreSnapshot);
  target.querySelector('[data-vacations-snapshot-delete]')?.addEventListener('click', deleteSnapshot);
};

const refresh = async (silent = false) => {
  const target = host();
  if (!target) return;
  try { state = await api('/vacations/snapshots'); }
  catch (error) { if (!silent) toast(error.message, true); }
  render();
  window.clearTimeout(timer);
  if (['queued', 'running'].includes(state?.operation?.state)) timer = window.setTimeout(() => refresh(true), 1200);
};

const createSnapshot = async () => {
  if (loading) return;
  loading = true; render();
  try {
    const result = await api('/vacations/snapshots', { method: 'POST', body: '{}' });
    state = { ...(state || {}), operation: result.operation };
    toast(`Vacations: снимок #${result.operation.snapshot_id} создаётся.`);
  } catch (error) { toast(error.message, true); }
  finally { loading = false; await refresh(true); }
};

const restoreSnapshot = async () => {
  if (!selectedId || loading) return;
  const confirmation = window.prompt(`Откат Vacations к снимку #${selectedId} вернёт схему Vacations и metadata Migration Service к выбранной точке. Employees не изменяется.\n\nВведите RESTORE VACATIONS:`, '');
  if (confirmation !== 'RESTORE VACATIONS') return;
  loading = true; render();
  try {
    const result = await api(`/vacations/snapshots/${selectedId}/restore`, { method: 'POST', body: JSON.stringify({ confirmation }) });
    state = { ...(state || {}), operation: result.operation };
    toast(`Vacations: откат к снимку #${selectedId} запущен.`);
  } catch (error) { toast(error.message, true); }
  finally { loading = false; await refresh(true); }
};

const deleteSnapshot = async () => {
  if (!selectedId || loading) return;
  if (!window.confirm(`Удалить точку отката Vacations #${selectedId}? Восстановить её после удаления будет невозможно.`)) return;
  loading = true; render();
  try {
    await api(`/vacations/snapshots/${selectedId}`, { method: 'DELETE' });
    toast(`Vacations: точка отката #${selectedId} удалена.`);
    selectedId = null;
  } catch (error) { toast(error.message, true); }
  finally { loading = false; await refresh(true); }
};

const root = document.getElementById('migration-modules');
if (root) {
  const observer = new MutationObserver(() => {
    if (host() && !host().querySelector('[data-vacations-snapshot-create]')) refresh(true);
  });
  observer.observe(root, { childList: true, subtree: true });
  window.setTimeout(() => refresh(true), 0);
}
