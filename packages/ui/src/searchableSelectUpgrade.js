const FILTER_SELECT_SELECTOR = [
  '.irlix-toolbar select',
  '.toolbar select',
  '.filters select',
  '.manage-controls select',
  '.filters-inline select',
  '.manage-header-actions select',
].join(',');

const state = new WeakMap();

function labelFor(select) {
  const option = select.options[select.selectedIndex];
  return option?.textContent?.trim() || select.getAttribute('aria-label') || 'Выберите';
}

function closeAll(except = null) {
  document.querySelectorAll('.irlix-search-select-legacy.open').forEach((root) => {
    if (root !== except) root.classList.remove('open');
  });
}

function sync(select) {
  const item = state.get(select);
  if (!item) return;
  item.label.textContent = labelFor(select);
  item.root.classList.toggle('filled', Boolean(select.value));
  item.clear.hidden = !item.hasEmptyOption() || !select.value;
}

function renderOptions(select, query = '') {
  const item = state.get(select);
  if (!item) return;
  const needle = query.trim().toLocaleLowerCase('ru');
  item.options.innerHTML = '';
  let count = 0;

  [...select.options].forEach((option) => {
    if (option.value === '' && option.index === 0) return;
    const text = option.textContent?.trim() || '';
    if (needle && !text.toLocaleLowerCase('ru').includes(needle)) return;
    count += 1;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'irlix-search-select-legacy__option';
    if (option.selected) button.classList.add('selected');
    button.disabled = option.disabled;
    button.setAttribute('role', 'option');
    button.setAttribute('aria-selected', option.selected ? 'true' : 'false');

    const marker = document.createElement('span');
    marker.className = 'irlix-search-select-legacy__marker';
    if (option.selected) marker.appendChild(document.createElement('i'));
    const textNode = document.createElement('span');
    textNode.textContent = text;
    button.append(marker, textNode);
    button.addEventListener('click', () => {
      select.value = option.value;
      select.dispatchEvent(new Event('input', { bubbles: true }));
      select.dispatchEvent(new Event('change', { bubbles: true }));
      sync(select);
      item.root.classList.remove('open');
    });
    item.options.appendChild(button);
  });

  if (!count) {
    const empty = document.createElement('div');
    empty.className = 'irlix-search-select-legacy__empty';
    empty.textContent = 'Ничего не найдено';
    item.options.appendChild(empty);
  }
}

function enhance(select) {
  if (!(select instanceof HTMLSelectElement) || state.has(select)) return;

  const root = document.createElement('div');
  root.className = 'irlix-search-select-legacy';
  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = 'irlix-search-select-legacy__trigger';
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-expanded', 'false');

  const label = document.createElement('span');
  label.className = 'irlix-search-select-legacy__label';
  const actions = document.createElement('span');
  actions.className = 'irlix-search-select-legacy__actions';
  const clear = document.createElement('span');
  clear.className = 'irlix-search-select-legacy__clear';
  clear.textContent = '×';
  clear.title = 'Сбросить фильтр';
  const chevron = document.createElement('span');
  chevron.className = 'irlix-search-select-legacy__chevron';
  chevron.textContent = '⌄';
  actions.append(clear, chevron);
  trigger.append(label, actions);

  const menu = document.createElement('div');
  menu.className = 'irlix-search-select-legacy__menu';
  const search = document.createElement('input');
  search.type = 'search';
  search.className = 'irlix-search-select-legacy__search';
  search.placeholder = 'Поиск';
  const options = document.createElement('div');
  options.className = 'irlix-search-select-legacy__options';
  options.setAttribute('role', 'listbox');
  menu.append(search, options);
  root.append(trigger, menu);

  select.insertAdjacentElement('afterend', root);
  select.classList.add('irlix-search-select-native');

  state.set(select, {
    root, trigger, label, clear, search, options,
    hasEmptyOption: () => [...select.options].some((option) => option.value === ''),
  });

  trigger.addEventListener('click', () => {
    if (select.disabled) return;
    const opening = !root.classList.contains('open');
    closeAll(opening ? root : null);
    root.classList.toggle('open', opening);
    trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
    if (opening) {
      search.value = '';
      renderOptions(select);
      requestAnimationFrame(() => search.focus());
    }
  });
  clear.addEventListener('click', (event) => {
    event.stopPropagation();
    const empty = [...select.options].find((option) => option.value === '');
    if (!empty) return;
    select.value = '';
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
    sync(select);
  });
  search.addEventListener('input', () => renderOptions(select, search.value));
  search.addEventListener('click', (event) => event.stopPropagation());
  select.addEventListener('change', () => sync(select));

  new MutationObserver(() => { sync(select); if (root.classList.contains('open')) renderOptions(select, search.value); })
    .observe(select, { childList: true, subtree: true, attributes: true });

  sync(select);
}

function scan(root = document) {
  root.querySelectorAll?.(FILTER_SELECT_SELECTOR).forEach(enhance);
  if (root.matches?.(FILTER_SELECT_SELECTOR)) enhance(root);
}

if (typeof document !== 'undefined') {
  const start = () => {
    scan();
    new MutationObserver((mutations) => mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
      if (node instanceof Element) scan(node);
    }))).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('pointerdown', (event) => {
      if (!event.target.closest?.('.irlix-search-select-legacy')) closeAll();
    });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeAll(); });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else queueMicrotask(start);
}
