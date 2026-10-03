const compareNames = (a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ru');

export function buildStaffTree(departments, positions, collapsed = new Set()) {
  const byId = new Map(departments.map(d => [String(d.id), d]));
  const children = new Map();
  for (const d of departments) {
    const parent = d.parent_id != null && byId.has(String(d.parent_id)) ? String(d.parent_id) : '';
    if (!children.has(parent)) children.set(parent, []);
    children.get(parent).push(d);
  }
  for (const list of children.values()) list.sort(compareNames);
  const rows = [];
  const visited = new Set();
  const visit = (d, depth, hidden = false) => {
    const key = String(d.id);
    if (visited.has(key)) return;
    visited.add(key);
    const nested = children.get(key) || [];
    if (!hidden) rows.push({ key: `department:${key}`, kind: 'department', item: d, depth, hasChildren: Boolean(nested.length) });
    const hideChildren = hidden || collapsed.has(key);
    nested.forEach(child => visit(child, depth + 1, hideChildren));
  };
  (children.get('') || []).forEach(d => visit(d, 0));
  [...departments].sort(compareNames).forEach(d => { if (!visited.has(String(d.id))) visit(d, 0); });
  return rows;
}

export function departmentOptions(departments) {
  return buildStaffTree(departments, []).map(row => ({ value: String(row.item.id), label: row.item.name, depth: row.depth }));
}

export function positionsForDepartment(positions, departmentId) {
  if (!departmentId) return [];
  return positions.filter(p => !p.closed_at && String(p.direction_id) === String(departmentId));
}

export function departmentPositions(positions, departmentId) {
  if (!departmentId) return [];
  return positions.filter(p => String(p.direction_id) === String(departmentId)).sort(compareNames);
}
