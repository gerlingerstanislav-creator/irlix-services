const compareNames = (a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ru');

export function shortEmployeeName(employeeOrName) {
  if (employeeOrName && typeof employeeOrName === 'object') {
    const direct = [employeeOrName.last_name, employeeOrName.first_name].filter(Boolean).join(' ').trim();
    if (direct) return direct;
    employeeOrName = employeeOrName.full_name || '';
  }
  return String(employeeOrName || '').trim().split(/\s+/).filter(Boolean).slice(0, 2).join(' ');
}

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


export function employeeTreeOptions(departments, employees) {
  const byId = new Map(departments.map(d => [String(d.id), d]));
  const required = new Set();
  const byDepartment = new Map();
  const unlinked = [];
  for (const employee of employees) {
    const key = String(employee.department_id ?? '');
    if (!byId.has(key)) { unlinked.push(employee); continue; }
    if (!byDepartment.has(key)) byDepartment.set(key, []);
    byDepartment.get(key).push(employee);
    const seen = new Set(); let current = key;
    while (byId.has(current) && !seen.has(current)) { seen.add(current); required.add(current); current = String(byId.get(current).parent_id ?? ''); }
  }
  const options = [];
  const addPeople = (people, depth) => [...people].sort((a,b) => String(a.full_name || '').localeCompare(String(b.full_name || ''), 'ru')).forEach(e => options.push({ value: String(e.id), label: shortEmployeeName(e), depth }));
  for (const row of buildStaffTree(departments, [])) {
    const key = String(row.item.id);
    if (!required.has(key)) continue;
    options.push({ value: `department:${key}`, label: row.item.name, kind: 'group', depth: row.depth });
    addPeople(byDepartment.get(key) || [], row.depth + 1);
  }
  if (unlinked.length) { options.push({ value: 'department:unlinked', label: 'Без подразделения', kind: 'group', depth: 0 }); addPeople(unlinked, 1); }
  return options;
}


export function staffPositionTreeOptions(departments, positions) {
  const byId = new Map(departments.map(d => [String(d.id), d]));
  const byDepartment = new Map();
  const required = new Set();

  for (const position of positions) {
    const key = String(position.direction_id ?? '');
    if (!byId.has(key)) continue;
    if (!byDepartment.has(key)) byDepartment.set(key, []);
    byDepartment.get(key).push(position);

    const seen = new Set();
    let current = key;
    while (byId.has(current) && !seen.has(current)) {
      seen.add(current);
      required.add(current);
      current = String(byId.get(current).parent_id ?? '');
    }
  }

  const options = [];
  for (const row of buildStaffTree(departments, [])) {
    const key = String(row.item.id);
    if (!required.has(key)) continue;
    options.push({ value: `department:${key}`, label: row.item.name, kind: 'group', depth: row.depth });
    [...(byDepartment.get(key) || [])]
      .sort(compareNames)
      .forEach(position => options.push({ value: String(position.id), label: position.name, depth: row.depth + 1 }));
  }

  return options;
}
