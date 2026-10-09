<script setup>
import { computed, ref, watch } from 'vue';
import { useClientLogo } from '../useClientLogo';
import { clientInitial } from '../workflow.js';
const props=defineProps({request:Object,client:Object});const failed=ref(false);const initial=computed(()=>clientInitial(props.client?.name,props.request?.title));watch(()=>props.client?.logo_url,()=>{failed.value=false});
const source=useClientLogo(()=>props.client?.logo_url);
</script>
<template><span class="request-client-logo"><img v-if="source&&!failed" :src="source" :alt="'Логотип '+client.name" loading="lazy" @error="failed=true"><span v-else>{{initial}}</span></span></template>
<style scoped>.request-client-logo{display:grid;place-items:center;flex:none;width:28px;height:28px;overflow:hidden;border-radius:6px;background:#eef2f6;color:#688095;font-size:var(--irlix-font-size-table);font-weight:600}.request-client-logo img{width:100%;height:100%;object-fit:contain}</style>
