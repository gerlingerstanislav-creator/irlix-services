<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { UiAppShell, UiBadge, UiPanel, UiButton } from '@irlix/ui';
import { articles, canonicalPath, filterArticles, initialSection, sectionForPath, sections, statusInfo } from './catalog.js';

const current = ref(sectionForPath(window.location.pathname));
const search = ref('');
const notice = ref('');
const nav = sections.map(({id,label,icon})=>({id,label,icon,href:canonicalPath(id)}));
const selected = computed(()=>sections.find(item=>item.id===current.value) || sections[0]);
const matches = computed(()=>filterArticles(articles,search.value,search.value.trim()?'all':current.value));
const crumbs = computed(()=>[{label:selected.value.label}]);

function navigate(id,replace=false) {
  const next = sections.some(item=>item.id===id) ? id : initialSection;
  current.value=next;
  search.value='';
  const path=canonicalPath(next);
  if(window.location.pathname!==path) window.history[replace?'replaceState':'pushState']({},'',path);
}
function pop(){current.value=sectionForPath(window.location.pathname);search.value='';}
onMounted(()=>{
  window.addEventListener('popstate',pop);
  if(window.location.pathname!==canonicalPath(current.value))navigate(current.value,true);
});
onBeforeUnmount(()=>window.removeEventListener('popstate',pop));
async function copyText(value){
  try{await navigator.clipboard.writeText(value);notice.value='Скопировано';}
  catch(_){notice.value='Копирование недоступно в этом браузере';}
}
</script>

<template>
  <UiAppShell service="brandbook" service-name="Брендбук IRLIX"
    :section="current" :items="nav" :breadcrumbs="crumbs"
    :account-enabled="false" :service-access="{services:{brandbook:true}}"
    @update:section="navigate">
    <main class="brandbook-page">
      <div class="brandbook-heading">
        <div>
          <p class="brandbook-eyebrow">IRLIX / BRAND REFERENCE</p>
          <h1>{{ selected.label }}</h1>
          <p class="brandbook-subtitle">{{ selected.subtitle }}</p>
        </div>
        <UiBadge tone="warning">Стандарт ещё не утверждён</UiBadge>
      </div>
      <div class="brandbook-disclaimer" role="note">
        <strong>Рабочий исследовательский каталог.</strong>
        Здесь собраны подтверждённые публикациями сведения и вопросы, ожидающие согласования.
        Ни один из приведённых пунктов пока не является официальным правилом бренда IRLIX.
      </div>
      <div class="brandbook-toolbar">
        <label for="brandbook-search">Поиск по разделам</label>
        <input id="brandbook-search" v-model="search" type="search" autocomplete="off" placeholder="Название, текст или источник…" />
        <span v-if="search" class="brandbook-count">Совпадений: {{ matches.length }}</span>
      </div>
      <p v-if="search" class="brandbook-search-note">Результаты поиска по всему опубликованному каталогу</p>
      <div v-if="matches.length" class="brandbook-grid">
        <UiPanel v-for="article in matches" :key="article.id" class="brandbook-card">
          <div class="brandbook-card__head">
            <h2>{{ article.title }}</h2>
            <UiBadge :tone="statusInfo[article.status].tone">{{ statusInfo[article.status].label }}</UiBadge>
          </div>
          <p>{{ article.description }}</p>
          <div class="brandbook-card__footer">
            <a v-if="article.source" :href="article.source.url" target="_blank" rel="noopener noreferrer">{{ article.source.label }} ↗</a>
            <span v-else>Исходник или согласование пока отсутствует</span>
            <span v-if="article.checked" class="brandbook-date">Проверено {{ article.checked }}</span>
          </div>
          <UiButton v-if="article.status==='source'" variant="secondary" type="button" @click="copyText(article.description)">Копировать текст</UiButton>
        </UiPanel>
      </div>
      <div v-else class="brandbook-empty" role="status">
        По запросу ничего не найдено. Измените поисковую фразу.
      </div>
      <p v-if="notice" class="brandbook-notice" role="status" @click="notice=''">{{ notice }}</p>
      <footer class="brandbook-footer">
        Источники проверены 22.10.2026 · Фирменные исходники не предоставлены ·
        <a href="https://irlix.com/" target="_blank" rel="noopener noreferrer">Официальный сайт IRLIX ↗</a>
      </footer>
    </main>
  </UiAppShell>
</template>
