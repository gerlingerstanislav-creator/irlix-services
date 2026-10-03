const compareNames = (a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ru');

export function buildStaffTree(departments, positions, collapsed = new Set(), positionModes = new Set()) {
  const byId = new Map(departments.map(d => [String(d.id), d]));
  const children = new Map();
  const byDepartment = new Map();
  for (const d of departments) {
    const parent = d.parent_id != null && byId.has(String(d.parent_id)) ? String(d.parent_id) : '';
    if (!children.has(parent)) children.set(parent, []);
    children.get(parent).push(d);
  }
  for (const p of positions) {
    const key = String(p.direction_id ?? '');
    if (!byDepartment.has(key)) byDepartment.set(key, []);
    byDepartment.get(key).push(p);
  }
  for (const list of children.values()) list.sort(compareNames);
  for (const list of byDepartment.values()) list.sort((a, b) => Number(Boolean(a.closed_at)) - Number(Boolean(b.closed_at)) || compareNames(a, b));
  const rows = [];
  const visited = new Set();
  const visit = (d, depth, hidden = false) => {
    const key = String(d.id);
    if (visited.has(key)) return;
    visited.add(key);
    const nested = children.get(key) || [];
    const own = byDepartment.get(key) || [];
    const showsPositions = positionModes.has(key);
    if (!hidden) rows.push({ key: `department:${key}`, kind: 'department', item: d, depth, hasChildren: Boolean(nested.length), showsPositions });
    const hideChildren = hidden || collapsed.has(key) || showsPositions;
    if (!hidden && showsPositions) for (const p of own) rows.push({ key: `position:${p.id}`, kind: 'position', item: p, depth: depth + 1 });
    if (!hidden && showsPositions && !own.length) rows.push({ key: `empty:${key}`, kind: 'empty', item: { name: 'Должностей в подразделении нет' }, depth: depth + 1 });
    nested.forEach(child => visit(child, depth + 1, hideChildren));
  };
  (children.get('') || []).forEach(d => visit(d, 0));
  [...departments].sort(compareNames).forEach(d => { if (!visited.has(String(d.id))) visit(d, 0); });
  // Surface unexpected legacy records rather than silently losing them from the list.
  const unlinked = positions.filter(p => !byId.has(String(p.direction_id ?? '')));
  if (unlinked.length) {
    rows.push({ key: 'unlinked', kind: 'department', item: { id: 'unlinked', name: 'Требуется привязка к подразделению' }, depth: 0, hasChildren: false });
    unlinked.sort(compareNames).forEach(p => rows.push({ key: `position:${p.id}`, kind: 'position', item: p, depth: 1 }));
  }
  return rows;
}

export function departmentOptions(departments) {
  return buildStaffTree(departments, []).map(row => ({ value: String(row.item.id), label: row.item.name, depth: row.depth }));
}

export function positionsForDepartment(positions, departmentId) {
  if (!departmentId) return [];
  return positions.filter(p => !p.closed_at && String(p.direction_id) === String(departmentId));
}
