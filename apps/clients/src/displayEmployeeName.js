/** Format employee names for display without changing editable entity values. */
export function displayEmployeeName(value) {
  return String(value ?? '').trim().split(/\s+/u).filter(Boolean).slice(0, 2).join(' ');
}
