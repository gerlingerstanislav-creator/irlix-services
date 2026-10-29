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


// Host inode blocks and statvfs counters are the ONLY additive disk accounting.
// Docker image/volume estimates and legacy task probes must never be summed here.
export const physicalDiskLabels = Object.freeze({
  'docker-overlay': 'Docker · образы и записываемые слои',
  'docker-volumes': 'Docker · тома (включая базы данных)',
  'docker-containers': 'Docker · контейнеры и логи',
  'docker-buildkit': 'Docker · кеш сборок',
  'docker-other': 'Docker · остальные файлы',
  'migration-snapshots': 'Migration · точки отката',
  'ci': 'CI · артефакты',
  'deployment': 'Платформа · исходники и развёртывание',
  'system-logs': 'Система · журналы',
  'system-cache': 'Система · кеш',
  'system-temp': 'Система · временные файлы',
  'system-data': 'Система · данные и состояния',
  'os-packages': 'ОС · программы и библиотеки',
  'os-config': 'ОС · конфигурация и загрузка',
  'user-files': 'Пользовательские файлы',
  'other': 'Другие файлы корневого раздела',
  '__unclassified__': 'Не удалось определить по файлам',
  '__reserved__': 'Резерв ФС (не занят файлами)',
  '__free__': 'Доступно',
});

export const physicalDiskRows = inventory =>
  (!inventory?.error && !inventory?.stale && inventory?.filesystem &&
   Array.isArray(inventory?.physical_sections))
    ? inventory.physical_sections
      .filter(row => Object.hasOwn(physicalDiskLabels, row.id) && Number.isFinite(row.bytes) && row.bytes >= 0)
      .map(row => ({...row, label:physicalDiskLabels[row.id]}))
      .sort((a,b) => b.bytes-a.bytes)
    : [];

export function physicalDiskAllocation(inventory) {
  const fs = !inventory?.error && !inventory?.stale ? inventory?.filesystem : null;
  const sections = physicalDiskRows(inventory);
  if (!fs || !Number.isFinite(fs.total_bytes) || fs.total_bytes <= 0 ||
      !Number.isFinite(fs.used_bytes) || fs.overlap_bytes > 0 ||
      sections.length !== 16) {
    return {total:fs?.total_bytes || 0,used:null,segments:[],unavailable:true,incomplete:true,normalized:false};
  }
  // Complete partition: physical used + filesystem reservation + user-available.
  const parts = [
    ...sections.filter(row => row.bytes > 0).map(row => ({
      id:row.id,value:row.bytes,size:row.bytes,color:diskCategoryColor(row.id)
    })),
    {id:'__unclassified__',value:fs.unclassified_bytes,size:fs.unclassified_bytes,
      color:'var(--irlix-color-text-muted)'},
    {id:'__reserved__',value:fs.reserved_bytes,size:fs.reserved_bytes,
      color:'var(--irlix-color-border-strong, #a3a3a3)'},
    {id:'__free__',value:fs.available_bytes,size:fs.available_bytes,
      color:'var(--irlix-color-border)'},
  ];
  return {total:fs.total_bytes,used:fs.used_bytes,
    segments:parts.filter(row => row.size > 0),values:parts,unavailable:false,
    incomplete:!!fs.partial || fs.unclassified_bytes > 0,normalized:false};
}

export function diskCategoryColor(id) {
  const colors = ['#9465c8','#448fd1','#ba723a','#d49a31','#a39139','#d66a82',
    '#ba67ae','#19a180','#627bc0','#8e9d43','#54aab6','#737cc1',
    '#628b72','#7286c7','#bb6470','#379da1'];
  const keys = Object.keys(physicalDiskLabels);
  return colors[keys.indexOf(id)] || 'var(--irlix-color-text-muted)';
}
