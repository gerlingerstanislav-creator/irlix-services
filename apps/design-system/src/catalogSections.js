// Labels, order and stable anchors for the catalog and its shared sidebar.
export const catalogSections = [
  { id: 'foundations', label: 'Основы', icon: 'palette', examples: [
    { id: 'theme', label: 'Тема интерфейса' }, { id: 'colors', label: 'Цвета и токены' }, { id: 'typography', label: 'Типографика' },
  ] },
  { id: 'tables-lists', label: 'Таблицы и списки', icon: 'list', examples: [
    { id: 'registry', label: 'Реестры и состояния' }, { id: 'tables', label: 'Таблицы с группами' }, { id: 'hierarchy', label: 'Иерархия' }, { id: 'kanban', label: 'Канбан' },
  ] },
  { id: 'buttons-actions', label: 'Кнопки и действия', icon: 'target', examples: [
    { id: 'buttons', label: 'Кнопки' }, { id: 'card-actions', label: 'Иконки в действиях' }, { id: 'icons', label: 'Каталог иконок' },
  ] },
  { id: 'fields', label: 'Поля ввода и выбор', icon: 'settings', examples: [
    { id: 'forms', label: 'Поля формы' }, { id: 'filters', label: 'Селекты и поиск' }, { id: 'checkbox', label: 'Чекбоксы' }, { id: 'filter-rail', label: 'Панель фильтров и группировки' },
  ] },
  { id: 'statuses', label: 'Статусы и загрузка', icon: 'status', examples: [
    { id: 'badges', label: 'Статусы и подтверждения' }, { id: 'loaders', label: 'Индикаторы загрузки' },
  ] },
  { id: 'tabs', label: 'Вкладки и переключатели', icon: 'dashboard', examples: [
    { id: 'view-controls', label: 'Вкладки и виды' },
  ] },
  { id: 'panels', label: 'Карточки и панели', icon: 'briefcase', examples: [
    { id: 'panel', label: 'Панель содержимого' }, { id: 'client-summary', label: 'Сводка клиента' }, { id: 'drawers', label: 'Боковые карточки' },
  ] },
  { id: 'navigation', label: 'Навигация', icon: 'org', examples: [
    { id: 'topbar', label: 'Верхняя панель' }, { id: 'sidebar', label: 'Боковое меню' },
  ] },
];

export const catalogAnchorIds = catalogSections.flatMap(group => [group.id, ...group.examples.map(example => example.id)]);
export const groupForAnchor = id => catalogSections.find(group => group.id === id || group.examples.some(example => example.id === id))?.id;
