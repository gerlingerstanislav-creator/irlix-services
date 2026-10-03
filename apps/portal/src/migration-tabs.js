import { createApp, h, ref } from 'vue';
import { UiTabs } from '@irlix/ui';
import { createBrowserAuth } from '@irlix/auth';

const tabItems = [
  { value: 'transfer', label: 'Перенос данных' },
  { value: 'rollback', label: 'Откат переноса данных' },
  { value: 'connection', label: 'Доступ к БД' },
];

const auth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/' });
const selectedTabs = new Map();
const mountedTabs = new Map();

const ensureStyles = () => {
  if (document.getElementById('migration-module-tabs-styles')) return;
  const style = document.createElement('style');
  style.id = 'migration-module-tabs-styles';
  style.textContent = `
    .migration-module-tabs{margin-top:16px}
    .migration-tab-panel{margin-top:14px}
    .migration-tab-panel>.connection-box,
    .migration-tab-panel>.operations,
    .migration-tab-panel>.module-event-box{margin-top:0}
    .migration-tab-panel>.connection-box,
    .migration-tab-panel>.operations{padding-top:0;border-top:0}
    .migration-tab-panel>.module-event-box{margin-top:14px}
    .migration-rollback-empty{padding:16px;border:1px solid #e5e9ec;border-radius:9px;background:#fafbfb;color:#667085;font-size:11px;line-height:1.45}
  `;
  document.head.appendChild(style);
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

const setActivePanel = (moduleKey, value) => {
  selectedTabs.set(moduleKey, value);
  const module = document.querySelector(`.migration-module[data-module="${CSS.escape(moduleKey)}"]`);
  if (!module) return;
  module.querySelectorAll('[data-migration-tab-panel]').forEach((panel) => {
    panel.hidden = panel.dataset.migrationTabPanel !== value;
  });
};

const deleteSelectedSnapshot = async (module, button) => {
  const select = module.querySelector('[data-snapshot-select]');
  const snapshotId = String(select?.value || '').trim();
  if (!/^\d+$/.test(snapshotId)) return;
  const label = select?.selectedOptions?.[0]?.textContent?.trim() || `#${snapshotId}`;
  if (!window.confirm(`Удалить точку отката ${label}?\n\nЭто удалит архив снимка с сервера. Восстановить удалённую точку будет невозможно.`)) return;

  button.disabled = true;
  const previousLabel = button.textContent;
  button.textContent = 'Удаляем…';
  try {
    const response = await auth.fetch(`/api/migration/snapshots/${encodeURIComponent(snapshotId)}`, {
      method: 'DELETE',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    let payload = null;
    try { payload = await response.json(); } catch (_) { payload = null; }
    if (!response.ok) throw new Error(payload?.message || `Migration API error (${response.status})`);
    showToast(`Точка отката #${snapshotId} удалена.`);
    document.getElementById('migration-refresh')?.click();
  } catch (error) {
    showToast(error instanceof Error ? error.message : String(error), true);
    button.disabled = false;
    button.textContent = previousLabel;
  }
};

const addDeleteSnapshotAction = (module, snapshot) => {
  const select = snapshot.querySelector('[data-snapshot-select]');
  const row = snapshot.querySelector('.button-row');
  if (!select || !row || row.querySelector('[data-action="delete-snapshot"]')) return;
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'btn danger';
  button.dataset.action = 'delete-snapshot';
  button.textContent = 'Удалить точку отката';
  button.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    deleteSelectedSnapshot(module, button);
  });
  row.append(button);
};

const mountTabs = (module, moduleKey, host) => {
  const previous = mountedTabs.get(moduleKey);
  if (previous?.node !== module) previous?.app?.unmount();

  const current = ref(selectedTabs.get(moduleKey) || 'transfer');
  const app = createApp({
    render: () => h(UiTabs, {
      modelValue: current.value,
      items: tabItems,
      'onUpdate:modelValue': (value) => {
        current.value = value;
        setActivePanel(moduleKey, value);
      },
    }),
  });
  app.mount(host);
  mountedTabs.set(moduleKey, { node: module, app });
};

const enhanceModule = (module) => {
  if (module.dataset.migrationTabsReady === '1') return;
  const moduleKey = module.dataset.module;
  if (!moduleKey) return;

  const connection = module.querySelector(':scope > .connection-box');
  const operations = module.querySelector(':scope > .operations');
  const events = module.querySelector(':scope > .module-event-box');
  if (!connection || !operations) return;

  module.dataset.migrationTabsReady = '1';

  const snapshot = operations.querySelector(':scope > .migration-snapshot-box');
  if (snapshot) {
    addDeleteSnapshotAction(module, snapshot);
    snapshot.remove();
  }

  const tabsHost = document.createElement('div');
  tabsHost.className = 'migration-module-tabs';

  const transferPanel = document.createElement('section');
  transferPanel.className = 'migration-tab-panel';
  transferPanel.dataset.migrationTabPanel = 'transfer';
  transferPanel.setAttribute('role', 'tabpanel');
  transferPanel.append(operations);
  if (events) transferPanel.append(events);

  const rollbackPanel = document.createElement('section');
  rollbackPanel.className = 'migration-tab-panel';
  rollbackPanel.dataset.migrationTabPanel = 'rollback';
  rollbackPanel.setAttribute('role', 'tabpanel');
  if (snapshot) {
    rollbackPanel.append(snapshot);
  } else {
    const empty = document.createElement('div');
    empty.className = 'migration-rollback-empty';
    empty.textContent = 'Резервная копия и откат для этого сервиса пока не поддерживаются.';
    rollbackPanel.append(empty);
  }

  const connectionPanel = document.createElement('section');
  connectionPanel.className = 'migration-tab-panel';
  connectionPanel.dataset.migrationTabPanel = 'connection';
  connectionPanel.setAttribute('role', 'tabpanel');
  connectionPanel.append(connection);

  module.append(tabsHost, transferPanel, rollbackPanel, connectionPanel);
  mountTabs(module, moduleKey, tabsHost);
  setActivePanel(moduleKey, selectedTabs.get(moduleKey) || 'transfer');
};

const enhanceAll = () => {
  document.querySelectorAll('.migration-module[data-module]').forEach(enhanceModule);
};

ensureStyles();

const modulesRoot = document.getElementById('migration-modules');
if (modulesRoot) {
  const observer = new MutationObserver(() => enhanceAll());
  observer.observe(modulesRoot, { childList: true });
  enhanceAll();
}
