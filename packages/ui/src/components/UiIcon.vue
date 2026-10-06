<script setup>
defineProps({ name: { type: String, required: true } });
</script>

<template>
  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <template v-if="name === 'chevron-left'"><path d="m14 6-6 6 6 6" /></template>
    <template v-else-if="name === 'chevron-right'"><path d="m10 6 6 6-6 6" /></template>
    <template v-else-if="name === 'chevron-down'"><path d="m6 9 6 6 6-6" /></template>
    <template v-else-if="name === 'users' || name === 'team' || name === 'members'">
      <circle cx="9" cy="8" r="3"/><path d="M3.5 19c.4-4 2.4-6 5.5-6s5.1 2 5.5 6"/><circle cx="17" cy="9" r="2.3"/><path d="M15.5 14.2c3.3-.6 5 1.1 5.5 4.3"/>
    </template>
    <template v-else-if="name === 'org' || name === 'building'">
      <circle v-if="name === 'org'" cx="12" cy="5" r="2.5"/><circle v-if="name === 'org'" cx="6" cy="18" r="2.5"/><circle v-if="name === 'org'" cx="18" cy="18" r="2.5"/><path v-if="name === 'org'" d="M12 7.5v4M6 15.5v-3h12v3"/>
      <template v-else><path d="M4 21V5l8-3 8 3v16"/><path d="M8 8h2M14 8h2M8 12h2M14 12h2M8 16h2M14 16h2M2 21h20"/></template>
    </template>
    <template v-else-if="name === 'roles'">
      <path d="M12 3l7 3v5c0 4.7-2.8 8.1-7 10-4.2-1.9-7-5.3-7-10V6l7-3Z"/><path d="M9 11.5l2 2 4-4"/>
    </template>
    <template v-else-if="name === 'search'">
      <circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/>
    </template>
    <template v-else-if="name === 'filter'">
      <path d="M4 5h16l-6 7v5l-4 2v-7L4 5Z"/>
    </template>
    <template v-else-if="name === 'status'">
      <circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2"/>
    </template>
    <template v-else-if="name === 'calendar' || name === 'vacation' || name === 'reports'">
      <rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>
    </template>
    <template v-else-if="name === 'manage' || name === 'list'">
      <rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/>
    </template>
    <template v-else-if="name === 'briefcase'">
      <rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5h8v2M3 12h18M10 12v2h4v-2"/>
    </template>
    <template v-else-if="name === 'crown'">
      <path d="M4 18h16l-1-10-5 5-2-7-2 7-5-5-1 10Z"/><path d="M6 21h12"/>
    </template>
    <template v-else-if="name === 'contact'">
      <circle cx="12" cy="8" r="3"/><path d="M5 20c.5-4.5 2.8-7 7-7s6.5 2.5 7 7"/><rect x="3" y="3" width="18" height="18" rx="3"/>
    </template>
    <template v-else-if="name === 'target'">
      <circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 4V2M20 12h2"/>
    </template>
    <template v-else-if="name === 'rocket'">
      <path d="M14 4c3-2 5-1 6-1 0 1 1 3-1 6l-5 5-4-1-1-4 5-5Z"/><path d="M9 13l-3 3M7 11l-3 1-1 3 4 1M12 16l1 4 3-1 1-3"/>
    </template>
    <template v-else-if="name === 'cash'">
      <rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 9h.01M18 15h.01"/>
    </template>
    <template v-else-if="name === 'dashboard'">
      <path d="M3 11.5 12 4l9 7.5"/><path d="M5 10.5V20h14v-9.5M9 20v-6h6v6"/>
    </template>
    <template v-else-if="name === 'code'">
      <path d="m9 6-5 6 5 6M15 6l5 6-5 6M13 4l-2 16"/>
    </template>
    <template v-else-if="name === 'hourglass'">
      <path d="M7 3h10M7 21h10M8 3c0 4 2 5 4 7 2-2 4-3 4-7M8 21c0-4 2-5 4-7 2 2 4 3 4 7"/>
    </template>
    <template v-else-if="name === 'assessment' || name === 'chart'">
      <rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 16v-3M12 16V8M16 16v-5"/>
    </template>
    <template v-else-if="name === 'tasks'">
      <path d="M4 7h3l2 2 4-5M4 14h3l2 2 4-5M14 8h6M14 15h6"/>
    </template>
    <template v-else-if="name === 'palette'">
      <path d="M12 3a9 9 0 1 0 0 18h2a2 2 0 0 0 0-4h-1a2 2 0 0 1 0-4h4a4 4 0 0 0 4-4c0-3.3-4-6-9-6Z"/><circle cx="7" cy="10" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7" r="1"/>
    </template>
    <template v-else-if="name === 'audit'">
      <path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/>
    </template>
    <template v-else>
      <circle cx="12" cy="12" r="8"/>
    </template>
  </svg>
</template>
