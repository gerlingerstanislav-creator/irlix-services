/** Business-neutral helpers shared by every platform Kanban. */
export function normalizeKanbanColumns(columns = []) {
  return columns.map(column => typeof column === 'string' ? { id: column, label: column } : column);
}
export function kanbanItemsFor(items = [], statusKey = 'status', id) {
  return items.filter(item => item[statusKey] === id);
}
