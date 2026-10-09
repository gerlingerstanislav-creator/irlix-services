// Non-additive cache and Docker image caveats are kept explicit.
const positive = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
export function resourceRemainder(snapshot) {
  const host = snapshot?.host || {};
  const services = snapshot?.services || [];
  const memoryUsed = positive(host.memory_used);
  const working = services.reduce((sum,row)=>sum+(positive(row.working_bytes)||0),0);
  const diskUsed = positive(host.disk_total) == null || positive(host.disk_available) == null
    ? null : Math.max(0,host.disk_total-host.disk_available);
  const diskAssigned = services.reduce((sum,row)=>sum+(positive(row.disk_bytes)||0),0);
  return {
    ram:{used:memoryUsed,assigned:working,other:memoryUsed==null?null:Math.max(0,memoryUsed-working),
      cache:positive(host.memory_cache),partial:services.some(s=>s.partial)},
    disk:{used:diskUsed,assigned:diskAssigned,other:diskUsed==null?null:Math.max(0,diskUsed-diskAssigned),
      imageExclusive:positive(snapshot?.disk?.images_exclusive_bytes),
      partial:!!snapshot?.disk?.partial||!!snapshot?.disk?.error||!!snapshot?.disk?.stale||services.some(s=>s.disk_partial)},
  };
}
