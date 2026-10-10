<script setup>
defineProps({
  name: { type: String, default: '' },
  logo: { type: String, default: '' },
  sector: { type: String, default: '' },
  clientType: { type: String, default: '' },
  sales: { type: String, default: '' },
  account: { type: String, default: '' },
  lead: { type: Boolean, default: false },
});
const abbreviated = value => String(value || '').trim().split(/\s+/).slice(0, 2).join(' ');
</script>
<template>
  <section class="irlix-client-summary" aria-label="Краткая карточка клиента">
    <div class="irlix-client-summary__identity">
      <span class="irlix-client-summary__logo"><img v-if="logo" :src="logo" alt="" /><span v-else>{{String(name || "?").trim().slice(0,1).toUpperCase()}}</span></span>
      <div class="irlix-client-summary__details">
        <strong class="irlix-client-summary__name">{{ name }} <small v-if="lead">· лид</small></strong>
        <div class="irlix-client-summary__people">
          <span v-if="sales" class="irlix-client-summary__person"><i class="irlix-client-summary__letter irlix-client-summary__letter--sales">S</i>{{ abbreviated(sales) }}</span>
          <span v-if="account" class="irlix-client-summary__person"><i class="irlix-client-summary__letter irlix-client-summary__letter--account">A</i>{{ abbreviated(account) }}</span>
        </div>
      </div>
    </div>
    <div class="irlix-client-summary__tags">
      <span v-if="sector" class="irlix-client-summary__tag irlix-client-summary__tag--sector">{{sector}}</span>
      <span v-if="clientType" class="irlix-client-summary__tag irlix-client-summary__tag--type">{{clientType}}</span>
    </div>
  </section>
</template>
