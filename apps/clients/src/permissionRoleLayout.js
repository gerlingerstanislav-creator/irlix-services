/**
 * Turns Employees' canonical preordered role tree into grouped options for
 * UiSearchSelect. Only roles not already displayed in the Clients matrix
 * remain selectable. Ancestor groups are kept, empty branches are omitted.
 */
export function roleTreeOptions(tree, availableRoles, configuredRoles) {
  const configured = new Set((configuredRoles || []).map(role => role.key));
  const selectable = new Map((availableRoles || [])
    .filter(role => !role.locked && !configured.has(role.key))
    .map(role => [role.key, role]));
  const entries = Array.isArray(tree) ? tree : [];
  const wanted = new Set();
  const ancestors = [];
  const used = new Set();

  entries.forEach((entry, index) => {
    const depth = Number.isInteger(entry?.depth) ? Math.max(0, entry.depth) : 0;
    if (entry?.kind === 'group') {
      while (ancestors.length && ancestors[ancestors.length - 1].depth >= depth) ancestors.pop();
      ancestors.push({ index, depth });
      return;
    }
    if (entry?.kind !== 'role' || !selectable.has(entry.key) || used.has(entry.key)) return;
    wanted.add(index);
    ancestors.forEach(parent => wanted.add(parent.index));
    used.add(entry.key);
  });

  const result = entries.flatMap((entry, index) => {
    if (!wanted.has(index)) return [];
    const depth = Number.isInteger(entry?.depth) ? Math.max(0, entry.depth) : 0;
    if (entry.kind === 'group') {
      return [{ kind: 'group', value: entry.key ?? `group:${index}`, label: entry.label, depth }];
    }
    const role = selectable.get(entry.key);
    return [{ value: entry.key, label: role.label, depth }];
  });

  const orphanRoles = [...selectable.values()].filter(role => !used.has(role.key));
  if (orphanRoles.length) {
    // Missing/unknown role-tree metadata must never silently hide a platform role.
    result.push({ kind: 'group', value: 'group:other-unmapped', label: 'Прочее', depth: 0 });
    orphanRoles.sort((a, b) => a.label.localeCompare(b.label, 'ru')).forEach(role => {
      result.push({ value: role.key, label: role.label, depth: 1 });
    });
  }
  return result;
}

/**
 * Default: Employees' organization preorder, with non-organizational roles
 * at the bottom. A saved user order only affects presentation, never policy.
 */
export function orderedRoles(roles, tree, savedOrder = []) {
  const byKey = new Map((roles || []).map(role => [role.key, role]));
  const defaultKeys = (Array.isArray(tree) ? tree : [])
    .filter(item => item?.kind === 'role' && byKey.has(item.key))
    .map(item => item.key);
  const uniqueDefault = [...new Set([...defaultKeys, ...byKey.keys()])];
  const requested = Array.isArray(savedOrder) ? savedOrder : [];
  const order = [...new Set([...requested.filter(key => byKey.has(key)), ...uniqueDefault])];
  return order.map(key => byKey.get(key));
}

/** Moves a column into the target's slot, preserving every cell's role identity. */
export function moveRoleTo(roles, sourceKey, targetKey) {
  const from = roles.findIndex(role => role.key === sourceKey);
  const to = roles.findIndex(role => role.key === targetKey);
  if (from < 0 || to < 0 || from === to) return roles;
  const next = [...roles];
  const [item] = next.splice(from, 1);
  next.splice(to, 0, item);
  return next;
}

/** Enables keyboard-friendly one-step left/right column movement. */
export function shiftRole(roles, key, offset) {
  const index = roles.findIndex(role => role.key === key);
  const target = index + offset;
  if (index < 0 || target < 0 || target >= roles.length) return roles;
  const next = [...roles];
  [next[index], next[target]] = [next[target], next[index]];
  return next;
}
