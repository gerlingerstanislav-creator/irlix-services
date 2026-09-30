export const sortNewest = items => [...items].sort((a, b) =>
  String(b.created_at || '').localeCompare(String(a.created_at || '')) || Number(b.id) - Number(a.id));

export function positionMatchesStatus(position, status) {
  if (!status) return true;
  if (status === 'Открыт') return position.status === 'Открыт';
  return (position.display_status || position.status) === status;
}

export function attemptStageColors(attempt) {
  const states = {
    'Новая': ['empty', 'empty', 'empty'],
    'CV отправлено': ['pending', 'empty', 'empty'],
    'Интервью назначено': ['done', 'pending', 'empty'],
    'Интервью пройдено': ['done', 'done', 'empty'],
    'Ожидает подключения': ['done', 'done', 'pending'],
    'Закрыт: успех': ['done', 'done', 'done'],
  };
  if (attempt.status !== 'Закрыт: неудача') return states[attempt.status] || states['Новая'];
  const previous = attempt.closed_from_status;
  if (['Новая', 'CV отправлено'].includes(previous)) return ['failed', 'empty', 'empty'];
  if (['Интервью назначено', 'Интервью пройдено'].includes(previous)) return ['done', 'failed', 'empty'];
  if (previous === 'Ожидает подключения') return ['done', 'done', 'failed'];
  // Historical failures without stage facts do not imply earlier stages were passed.
  return ['empty', 'empty', 'failed'];
}

export function attemptEmployeeOptions(employees, departments, allowedIds) {
  const allowed = new Set(allowedIds.map(Number));
  const people = employees.filter(person => allowed.has(Number(person.id)));
  const production = departments.filter(department => department.is_production === true || Number(department.is_production) === 1);
  const used = new Set(people.map(person => Number(person.department_id)));
  const byId = new Map(departments.map(department => [Number(department.id), department]));
  const productionIds = new Set(production.map(department => Number(department.id)));
  for (const id of [...used]) {
    const seen = new Set([id]);
    let parent = byId.get(id)?.parent_id;
    while (parent && !seen.has(Number(parent))) {
      seen.add(Number(parent));
      if (productionIds.has(Number(parent))) used.add(Number(parent));
      parent = byId.get(Number(parent))?.parent_id;
    }
  }
  const selected = production.filter(department => used.has(Number(department.id)));
  const included = new Set(selected.map(department => Number(department.id)));
  function parentId(department) {
    const seen = new Set([Number(department.id)]);
    let parent = Number(department.parent_id);
    while (parent && !seen.has(parent)) {
      if (included.has(parent)) return parent;
      seen.add(parent);
      parent = Number(byId.get(parent)?.parent_id);
    }
    return 0;
  }
  const alpha = (a, b) => String(a.name || a.full_name || '').localeCompare(String(b.name || b.full_name || ''), 'ru');
  const visited = new Set();
  const result = [];
  function walk(department, depth) {
    const id = Number(department.id);
    if (visited.has(id)) return;
    visited.add(id);
    result.push({ value: 'department:' + id, label: department.name, kind: 'group', depth });
    people.filter(person => Number(person.department_id) === id).sort(alpha).forEach(person =>
      result.push({ value: String(person.id), label: person.full_name || '#' + person.id, depth: depth + 1 }));
    selected.filter(child => parentId(child) === id).sort(alpha).forEach(child => walk(child, depth + 1));
  }
  selected.filter(department => !parentId(department)).sort(alpha).forEach(department => walk(department, 0));
  selected.filter(department => !visited.has(Number(department.id))).sort(alpha).forEach(department => walk(department, 0));
  return result;
}
