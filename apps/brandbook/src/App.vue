<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { UiAppShell, UiBadge, UiPanel, UiButton } from '@irlix/ui';
import { articles, canonicalPath, filterArticles, initialSection, sectionForPath, sections, statusInfo } from './catalog.js';
import siteLogo from './assets/irlix-site-wordmark.svg';
import lightLogo from './assets/irlix-site-wordmark-light-preview.svg';

// Only project-approved, non-client-specific digital tokens are published here.
const brandColors = Object.freeze([
  {name:'Основной фон',value:'#FFFFFF',description:'Основные светлые поверхности'},
  {name:'Мягкая поверхность',value:'#F7FAF8',description:'Секции и нейтральные блоки'},
  {name:'Основной текст',value:'#142820',description:'Текст и буквы IRLI на светлом'},
  {name:'Текстовый акцент',value:'#087C5A',description:'Ссылки и сдержанные акценты'},
  {name:'Вторичный текст',value:'#52685D',description:'Читаемые подписи'},
  {name:'Основной цвет X',value:'#008E8C',description:'Геометрия X без изменений'},
  {name:'Верхний штрих X',value:'#47D7AC',description:'Знак; не мелкий текст на белом'},
]);

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
  try{await navigator.clipboard.writeText(value);notice.value='Скопировано с источником и пометкой о статусе';}
  catch(_){notice.value='Копирование недоступно в этом браузере';}
}
function copyArticle(article) {
  if (!['source','approved'].includes(article.status)) return;
  const provenance = article.status === 'source'
    ? ['Источник: ' + article.source.label + ' — ' + article.source.url,
       'Проверено: ' + article.checked,
       'Статус: опубликовано в источнике; не утверждённый бренд-стандарт IRLIX']
    : ['Согласование: digital-основа v1.0, ' + article.approval.date + ' (уровень проекта)',
       'Граница: не подтверждает права на мастер-файлы, сторонние материалы и production release'];
  const text = [article.description,...provenance].join('\n');
  return copyText(text);
}
</script>

<template>
  <a class="brandbook-skip-link" href="#brandbook-main">К основному содержимому</a>
  <UiAppShell service="brandbook" service-name="Брендбук IRLIX"
    :section="current" :items="nav" :breadcrumbs="crumbs"
    :account-enabled="false" :service-access="{services:{brandbook:true}}"
    @update:section="navigate">
    <main id="brandbook-main" class="brandbook-page" tabindex="-1">
      <div class="brandbook-heading">
        <div>
          <p class="brandbook-eyebrow">IRLIX / BRAND REFERENCE</p>
          <h1>{{ selected.label }}</h1>
          <p class="brandbook-subtitle">{{ selected.subtitle }}</p>
        </div>
        <UiBadge tone="info">Digital v1.0 · согласовано в проекте</UiBadge>
      </div>
      <div class="brandbook-disclaimer" role="note">
        <strong>Цифровая основа IRLIX v1.0 согласована внутри проекта.</strong>
        Логотип для экрана, палитра и Onest утверждены как рабочие правила. Публичные факты по-прежнему сопровождаются источниками.
        Права на мастер-активы, отдельные рекламные утверждения, печать и выпуск официального пакета ещё проходят проверку.
      </div>
      <section v-if="current === 'logo' && !search" class="brandbook-logo-source" aria-labelledby="brandbook-logo-source-title">
        <div class="brandbook-logo-source__variants">
          <div class="brandbook-logo-source__preview">
            <img :src="siteLogo" width="212" height="92" alt="IRLIX — исходные белые буквы и двухцветная X для тёмного фона" />
          </div>
          <div class="brandbook-logo-source__preview brandbook-logo-source__preview--light">
            <img :src="lightLogo" width="212" height="92" alt="IRLIX — тёмные буквы и исходная двухцветная X для светлого фона" />
          </div>
        </div>
        <div class="brandbook-logo-source__content">
          <h2 id="brandbook-logo-source-title">Два цифровых варианта · v1.0</h2>
          <p>Проектно согласованы шесть исходных контуров с сайта. Для светлого фона меняется только цвет букв IRLI на #142820; оба цвета X сохраняются. Изолированная X допустима для небольших иконок.</p>
          <p>Представленные SVG — справочные веб-превью, не юридически подтверждённый мастер-пакет для распространения или печати.</p>
          <a href="https://irlix.com/" target="_blank" rel="noopener noreferrer">Исходный образец: irlix.com ↗</a>
        </div>
      </section>
      <section v-if="current === 'colors' && !search" class="brandbook-swatches" aria-label="Согласованная палитра для цифровых носителей">
        <div v-for="color in brandColors" :key="color.value" class="brandbook-swatch">
          <span class="brandbook-swatch__sample" :style="{backgroundColor:color.value}" aria-hidden="true"></span>
          <div class="brandbook-swatch__meta"><strong>{{ color.name }}</strong><code>{{ color.value }}</code><span>{{ color.description }}</span></div>
        </div>
      </section>
      <section v-if="current === 'typography' && !search" class="brandbook-type-preview" aria-label="Образец основного шрифта">
        <small>ONEST · 700 / 400 · DIGITAL V1.0</small>
        <h2>Вникаем в задачи.<br>Разрабатываем решения.</h2>
        <p>Демонстрационный текст для проверки кириллицы, чисел 0123456789 и межстрочного интервала. Это образец гарнитуры, а не утверждённый рекламный слоган.</p>
      </section>
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
            <span v-else-if="article.approval">Согласовано для digital · {{ article.approval.date }} · не юридический мастер</span>
            <span v-else>Исходник или согласование пока отсутствует</span>
            <span v-if="article.checked" class="brandbook-date">Проверено {{ article.checked }}</span>
          </div>
          <UiButton v-if="article.status==='source' || article.status==='approved'" variant="secondary" type="button" @click="copyArticle(article)">{{ article.status==='source' ? 'Копировать с указанием источника' : 'Копировать со статусом согласования' }}</UiButton>
        </UiPanel>
      </div>
      <div v-else class="brandbook-empty" role="status">
        По запросу ничего не найдено. Измените поисковую фразу.
      </div>
      <div v-if="notice" class="brandbook-notice" role="status"><span>{{ notice }}</span><UiButton variant="ghost" type="button" aria-label="Закрыть уведомление" @click="notice=''">×</UiButton></div>
      <footer class="brandbook-footer">
        Источники сверены 24.10.2026 · Digital-основа согласована на уровне проекта; публикация мастер-файлов и production остаются под отдельной проверкой ·
        <a href="https://irlix.com/" target="_blank" rel="noopener noreferrer">Официальный сайт IRLIX ↗</a>
      </footer>
    </main>
  </UiAppShell>
</template>
