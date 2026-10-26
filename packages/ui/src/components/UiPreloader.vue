<script setup>
defineProps({
  variant: { type: String, default: 'trace', validator: value => ['assembly', 'trace', 'glitch'].includes(value) },
  label: { type: String, default: 'Загрузка…' },
  showLabel: { type: Boolean, default: false },
  size: { type: String, default: 'normal', validator: value => ['small', 'normal', 'large'].includes(value) },
  overlay: { type: Boolean, default: false },
  page: { type: Boolean, default: false },
});
</script>

<template>
  <div
    class="ui-preloader"
    :class="[`ui-preloader--${variant}`, `ui-preloader--${size}`, { 'ui-preloader--overlay': overlay, 'ui-preloader--page': page }]"
    role="status" aria-live="polite" :aria-label="label"
  >
    <!-- Exact paths and colors from UiAppSidebar.vue. No rotations, scaling or morphing of the logo. -->
    <svg class="ui-preloader__mark" viewBox="0 0 32 32" fill="none" aria-hidden="true" focusable="false">
      <template v-if="variant === 'assembly'">
        <path class="ui-preloader__piece-a" fill-rule="evenodd" clip-rule="evenodd" d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z" />
        <path class="ui-preloader__piece-b" fill-rule="evenodd" clip-rule="evenodd" d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z" />
      </template>
      <template v-else-if="variant === 'trace'">
        <path class="ui-preloader__fill-a" fill-rule="evenodd" clip-rule="evenodd" d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z" />
        <path class="ui-preloader__fill-b" fill-rule="evenodd" clip-rule="evenodd" d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z" />
        <path class="ui-preloader__line-a" pathLength="100" d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z" />
        <path class="ui-preloader__line-b" pathLength="100" d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z" />
      </template>
      <template v-else>
        <g class="ui-preloader__ghost ui-preloader__ghost--cyan">
          <path d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z" /><path d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z" />
        </g>
        <g class="ui-preloader__ghost ui-preloader__ghost--pink">
          <path d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z" /><path d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z" />
        </g>
        <g class="ui-preloader__glitch-main">
          <path class="ui-preloader__piece-a" fill-rule="evenodd" clip-rule="evenodd" d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z" />
          <path class="ui-preloader__piece-b" fill-rule="evenodd" clip-rule="evenodd" d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z" />
        </g>
      </template>
    </svg>
    <span v-if="showLabel" class="ui-preloader__label">{{ label }}</span>
  </div>
</template>

<style scoped>
.ui-preloader { display:flex; align-items:center; justify-content:center; gap:9px; color:var(--irlix-color-text-muted); font-size:var(--irlix-font-size-caption); }
.ui-preloader__mark { display:block; width:38px; height:38px; flex:none; overflow:visible; }
.ui-preloader--small .ui-preloader__mark { width:22px; height:22px; }
.ui-preloader--large .ui-preloader__mark { width:72px; height:72px; }
.ui-preloader--page { width:100%; flex:1 1 auto; min-height:max(220px, calc(100dvh - var(--irlix-topbar-height, 48px) - 80px)); }
.ui-preloader--page .ui-preloader__mark { width:72px; height:72px; }
.ui-preloader--page.ui-preloader--small .ui-preloader__mark { width:22px; height:22px; }
.ui-preloader--page.ui-preloader--large .ui-preloader__mark { width:96px; height:96px; }
.ui-preloader--overlay { position:absolute; inset:0; z-index:3; width:100%; height:100%; background:color-mix(in srgb, var(--irlix-color-surface) 86%, transparent); }
.ui-preloader__piece-a,.ui-preloader__fill-a { fill:var(--irlix-sidebar-logo-dark, #15191f); }
.ui-preloader__piece-b,.ui-preloader__fill-b { fill:var(--irlix-color-primary, #06795e); }
.ui-preloader__piece-a,.ui-preloader__piece-b { transform-box:fill-box; transform-origin:center; }
.ui-preloader--assembly .ui-preloader__piece-a { animation:irlix-assemble-a 1.8s cubic-bezier(.32,0,.2,1) infinite; }
.ui-preloader--assembly .ui-preloader__piece-b { animation:irlix-assemble-b 1.8s cubic-bezier(.32,0,.2,1) infinite; }
@keyframes irlix-assemble-a {
  0%,11%,100% { transform:translate(-3px,3px); opacity:.38; }
  36%,75% { transform:translate(0,0); opacity:1; }
  93% { transform:translate(-2px,2px); opacity:.85; }
}
@keyframes irlix-assemble-b {
  0%,11%,100% { transform:translate(3px,-3px); opacity:.4; }
  36%,75% { transform:translate(0,0); opacity:1; }
  93% { transform:translate(2px,-2px); opacity:.84; }
}
.ui-preloader--trace :is(.ui-preloader__fill-a,.ui-preloader__fill-b) { opacity:0; animation:irlix-trace-fill 2.2s ease-in-out infinite; }
.ui-preloader--trace :is(.ui-preloader__line-a,.ui-preloader__line-b) {
  fill:none; stroke-width:.48; stroke-linejoin:round; stroke-dasharray:100;
  stroke-dashoffset:100; animation:irlix-trace-line 2.2s ease-in-out infinite;
}
.ui-preloader__line-a { stroke:var(--irlix-sidebar-logo-dark, #15191f); }
.ui-preloader__line-b { stroke:var(--irlix-color-primary, #06795e); animation-delay:-.08s !important; }
@keyframes irlix-trace-line {
  0%,5% { stroke-dashoffset:100; opacity:1; }
  45%,67% { stroke-dashoffset:0; opacity:1; }
  83%,100% { stroke-dashoffset:0; opacity:0; }
}
@keyframes irlix-trace-fill {
  0%,37% { opacity:0; }
  51%,75% { opacity:1; }
  95%,100% { opacity:0; }
}
.ui-preloader--glitch .ui-preloader__glitch-main { animation:irlix-glitch-cut 2.3s steps(1,end) infinite; }
.ui-preloader__ghost { opacity:0; }
.ui-preloader--glitch .ui-preloader__ghost { animation:irlix-glitch-echo 2.3s steps(1,end) infinite; }
.ui-preloader__ghost--cyan { fill:#58ffed; transform:translateX(-1.2px); }
.ui-preloader__ghost--pink { fill:#ff4ca2; transform:translateX(1.2px); }
.ui-preloader__ghost--pink { animation-delay:-.05s !important; }
@keyframes irlix-glitch-cut {
  0%,26%,35%,42%,48%,100% { transform:translate(0,0); }
  27%,30% { transform:translate(1px,-.25px); }
  31%,34% { transform:translate(-1.25px,.5px); }
  43%,47% { transform:translate(.5px,0); }
}
@keyframes irlix-glitch-echo {
  0%,25%,35%,41%,49%,100% { opacity:0; }
  26%,34%,42%,48% { opacity:.75; }
}
@media (prefers-reduced-motion:reduce) {
  .ui-preloader * { animation:none !important; }
  .ui-preloader--trace :is(.ui-preloader__fill-a,.ui-preloader__fill-b) { opacity:1; }
  .ui-preloader--trace :is(.ui-preloader__line-a,.ui-preloader__line-b) { opacity:0; }
  .ui-preloader__ghost { opacity:0 !important; }
}
</style>
