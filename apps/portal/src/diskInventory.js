// Public labels for the fixed, read-only host inventory. Never expose file paths.
export const diskTaskLabels = Object.freeze({
  'migration-employees': 'Migration · точки отката сотрудников',
  'migration-vacations': 'Migration · точки отката отпусков',
  'migration-clients': 'Migration · точки отката клиентов',
  'migration-timesheets': 'Migration · точки отката таймшитов',
  'migration-all': 'Migration · общие точки отката',
  'migration-console-other': 'Migration · служебные файлы снимков',
  'migration-legacy': 'Migration · старые снимки',
  'ci-other': 'CI · артефакты и временные файлы',
  'deployment': 'Развёртывание · рабочие файлы',
  'docker-container-files': 'Docker · файлы контейнеров и логи',
  'docker-buildkit': 'Docker · кеш сборки',
  'system-logs': 'Система · журналы',
  'system-cache': 'Система · кеши',
  'system-tmp': 'Система · временные файлы',
});

export const diskServiceBytes = service => service.disk_attributed_bytes ?? service.disk_bytes;
export const diskServicePartial = service => service.disk_attributed_partial ?? service.disk_partial;

export function diskTasks(snapshot) {
  const sample = snapshot?.disk?.host_inventory;
  if (!sample || !Array.isArray(sample.sections)) return [];
  return sample.sections
    .filter(row => Object.hasOwn(diskTaskLabels, row.id) && Number.isFinite(row.bytes) && row.bytes >= 0)
    .map(row => ({...row, label: diskTaskLabels[row.id]}))
    .sort((a, b) => b.bytes - a.bytes);
}
