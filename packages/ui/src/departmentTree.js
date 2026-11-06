/**
 * A selectable organization tree used by any employee-scoped registry.
 * Parent selections include all descendants; returned nodes are flat rows
 * with indentation so UiSearchSelect can retain its native keyboard/search UX.
 */
export function departmentTreeOptions(departments = []) {
  const byId = new Map();
  for (const row of departments || []) {
    const id = Number(row?.id);
    if (Number.isInteger(id) && id > 0 && !byId.has(String(id))) byId.set(String(id), row);
  }
  const children = new Map();
  for (const [id, row] of byId) {
    const parent = String(row.parent_id ?? '');
    const root = parent && parent !== id && byId.has(parent) ? parent : '';
    if (!children.has(root)) children.set(root, []);
    children.get(root).push(id);
  }
  const ordered = [];
  const seen = new Set();
  const compare = (a, b) => String(byId.get(a)?.name ?? '').localeCompare(
    String(byId.get(b)?.name ?? ''), 'ru', { sensitivity: 'base' });
  const visit = (id, depth, parentValue = null) => {
    if (seen.has(id)) return;
    seen.add(id);
    ordered.push({ value: id, label: String(byId.get(id)?.name ?? id),
      depth, parentValue, kind: 'option' });
    for (const child of (children.get(id) || []).sort(compare)) visit(child, depth + 1, id);
  };
  for (const id of (children.get('') || []).sort(compare)) visit(id, 0);
  // Orphaned cycles must not hide entries or make filtering recursive forever.
  for (const id of [...byId.keys()].sort(compare)) visit(id, 0);
  return ordered;
}

export function normalizeDepartmentSelection(departments = [], values = []) {
  if (!Array.isArray(values)) return [];
  const known = new Set((departments || []).map(x => String(x.id)));
  return [...new Set(values.map(String).filter(x => known.has(x)))];
}

export function employeesWithinDepartments(people = [], departments = [], values = []) {
  const chosen = normalizeDepartmentSelection(departments, values);
  if (!chosen.length) return people || [];
  const tree = departmentTreeOptions(departments);
  const selected = new Set(chosen);
  for (const node of tree) {
    if (node.parentValue !== null && selected.has(node.parentValue)) selected.add(node.value);
  }
  return (people || []).filter(person => selected.has(String(person.department_id)));
}
