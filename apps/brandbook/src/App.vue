<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { UiAppShell, UiBadge, UiPanel, UiButton } from '@irlix/ui';
import { articles, canonicalPath, filterArticles, initialSection, sectionForPath, sections, statusInfo } from './catalog.js';
import siteLogo from './assets/irlix-site-wordmark.svg';
import lightLogo from './assets/irlix-site-wordmark-light-preview.svg';
import { logoDownloads } from './downloads.js';
import { logoGuidelines, digitalPrinciples, communicationDirections, applicationPrinciples } from './guidelines.js';

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
async function copyText(value,status='source'){
  try{
    await navigator.clipboard.writeText(value);
    notice.value=status==='approved' ? 'Скопировано со статусом согласования' : 'Скопировано с источником и пометкой о статусе';
  }
  catch(_){notice.value='Копирование недоступно в этом браузере';}
}
function copyArticle(article) {
  if (!['source','approved'].includes(article.status)) return;
  const provenance = article.status === 'source'
    ? ['Источник: ' + article.source.label + ' — ' + article.source.url,
       'Проверено: ' + article.checked,
       'Статус: опубликовано в источнике; не утверждённый бренд-стандарт IRLIX']
    : ['Согласование: digital-основа v1.0, ' + article.approval.date + ' (уровень проекта)',
       'Разрешение на SVG-логотип для публичного скачивания подтверждено инициатором 24.10.2026; иные материалы и production release требуют отдельных решений'];
  const text = [article.description,...provenance].join('\n');
  return copyText(text,article.status);
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
        Скачивание трёх цифровых SVG разрешено владельцем. Клиентские материалы, фотографии, рекламные утверждения, печатные варианты и production release рассматриваются отдельно.
      </div>
      <section v-if="current === 'identity' && !search" class="brandbook-overview" aria-labelledby="brandbook-overview-title">
        <div class="brandbook-overview__copy">
          <p class="brandbook-overview__eyebrow">IRLIX / BRAND FOUNDATION · DIGITAL V1.0</p>
          <h2 id="brandbook-overview-title">Смысловая основа. Инженерная ясность.</h2>
          <p>Три элемента согласованного позиционирования: погружение в задачу, инженерная глубина и понятные форматы сотрудничества. Это описание подхода к бренду, а не готовый рекламный слоган.</p>
          <nav class="brandbook-overview__links" aria-label="Быстрый переход к стандартам">
            <a :href="canonicalPath('logo')">Логотип и правила ↗</a>
            <a :href="canonicalPath('colors')">Цвета ↗</a>
            <a :href="canonicalPath('typography')">Типографика ↗</a>
            <a :href="canonicalPath('assets')">Скачать SVG ↗</a>
          </nav>
        </div>
        <div class="brandbook-overview__visual">
          <img :src="lightLogo" alt="IRLIX — согласованный цифровой логотип для светлого фона" width="265" height="115" />
          <span>Основа v1.0 · 24.10.2026</span>
        </div>
      </section>
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
          <p>ООО «ИРЛИКС» разрешает свободно скачивать и применять эти фирменные SVG по правилам брендбука. Печатные версии и произвольный редизайн не утверждены.</p>
          <a :href="canonicalPath('assets')">Открыть файлы для скачивания ↗</a>
          <a href="https://irlix.com/" target="_blank" rel="noopener noreferrer">Исходный образец: irlix.com ↗</a>
        </div>
      </section>
      <section v-if="current === 'logo' && !search" class="brandbook-guide" aria-labelledby="brandbook-logo-usage-title">
        <div class="brandbook-guide__heading">
          <h2 id="brandbook-logo-usage-title">Как использовать знак</h2>
          <p>Согласованные digital-правила · исходная геометрия неизменна</p>
        </div>
        <div class="brandbook-guide__split">
          <div class="brandbook-guide__panel">
            <h3>Допустимо</h3>
            <ul><li v-for="rule in logoGuidelines.correct" :key="rule">{{ rule }}</li></ul>
          </div>
          <div class="brandbook-guide__panel brandbook-guide__panel--avoid">
            <h3>Недопустимо</h3>
            <ul><li v-for="rule in logoGuidelines.incorrect" :key="rule">{{ rule }}</li></ul>
          </div>
        </div>
        <p class="brandbook-guide__caveat">Охранное поле в миллиметрах, монохромные и печатные версии пока не нормированы. Для исходных файлов используйте библиотеку SVG.</p>
      </section>
      <section v-if="current === 'graphics' && !search" class="brandbook-guide" aria-labelledby="brandbook-graphics-title">
        <div class="brandbook-guide__heading">
          <h2 id="brandbook-graphics-title">Принципы цифрового оформления</h2>
          <p>Согласованное направление V1+V2 · без вымышленных фотографий и кейсов</p>
        </div>
        <div class="brandbook-guide__tiles">
          <article v-for="principle in digitalPrinciples" :key="principle.id" class="brandbook-guide__tile">
            <h3>{{ principle.title }}</h3>
            <p>{{ principle.description }}</p>
          </article>
        </div>
        <p class="brandbook-guide__caveat">Стиль фотографий, иллюстраций, иконок и шаблоны для сторонних площадок ещё требуют отдельной предметной проверки и утверждения.</p>
      </section>
      <section v-if="current === 'voice' && !search" class="brandbook-guide" aria-labelledby="brandbook-positioning-title">
        <div class="brandbook-guide__heading">
          <h2 id="brandbook-positioning-title">Смысловая платформа A+B+C</h2>
          <p>Направление принято владельцем; конкретные слоганы и коммерческие обещания отдельно</p>
        </div>
        <div class="brandbook-guide__tiles brandbook-guide__tiles--three">
          <article v-for="direction in communicationDirections" :key="direction.code" class="brandbook-guide__tile">
            <span class="brandbook-guide__letter">{{ direction.code }}</span>
            <h3>{{ direction.title }}</h3>
            <p>{{ direction.description }}</p>
          </article>
        </div>
        <p class="brandbook-guide__caveat">Не использовать утверждённое позиционирование как разрешение публиковать непроверенные заявления о клиентах, гарантиях, цене, скорости подключения или численности команды.</p>
      </section>
      <section v-if="current === 'applications' && !search" class="brandbook-guide" aria-labelledby="brandbook-application-title">
        <div class="brandbook-guide__heading">
          <h2 id="brandbook-application-title">Применение фирменной системы</h2>
          <p>Переносимые правила · материалы с внешними данными проходят отдельное согласование</p>
        </div>
        <div class="brandbook-guide__tiles">
          <article v-for="application in applicationPrinciples" :key="application.id" class="brandbook-guide__tile">
            <h3>{{ application.title }}</h3>
            <p>{{ application.description }}</p>
          </article>
        </div>
        <p class="brandbook-guide__caveat">Редактируемые шаблоны и новые изображения будут размещаться в библиотеке по мере проверки исходников и прав на публикацию. Неподтверждённые шаблоны не выдаём за официальные.</p>
      </section>
      <section v-if="current === 'assets' && !search" class="brandbook-downloads" aria-label="Утверждённые SVG-файлы логотипа">
        <div class="brandbook-downloads__intro">
          <h2>Логотип IRLIX · SVG для скачивания</h2>
          <p>Разрешено ООО «ИРЛИКС» для фирменного использования. Сохраняйте пропорции, исходные контуры и оба цвета X. Файлы не предоставляют право на перепродажу знака или регистрацию производного товарного знака.</p>
        </div>
        <div class="brandbook-downloads__grid">
          <article v-for="asset in logoDownloads" :key="asset.id" class="brandbook-downloads__item">
            <div class="brandbook-downloads__preview" :style="{background:asset.previewBackground}">
              <img :class="{'brandbook-downloads__symbol': asset.id === 'symbol'}" :src="asset.href" :alt="asset.alt" width="212" height="92" />
            </div>
            <div class="brandbook-downloads__info">
              <h3>{{ asset.title }}</h3>
              <p>{{ asset.context }}</p>
              <a :href="asset.href" :download="asset.filename" class="brandbook-downloads__link">Скачать SVG</a>
              <small>SVG · вектор · исходные контуры сохранены</small>
            </div>
          </article>
        </div>
        <p class="brandbook-downloads__scope">Разрешение инициатора от 24.10.2026 относится только к этим трём файлам. Кейсы, фотографии, мерч и печатные цветопробы согласовываются отдельно.</p>
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
        <p class="brandbook-license-note">Onest © 2021 The Onest Project Authors. Лицензия SIL OFL 1.1 — <a href="/brandbook/licenses/Onest-OFL-1.1.txt" target="_blank" rel="noopener noreferrer">прочитать и скачать полный текст лицензии</a>.</p>
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
        Источники сверены 24.10.2026 · Digital-основа согласована, три фирменных SVG разрешены для публичного скачивания · production требует отдельного разрешения ·
        <a href="https://irlix.com/" target="_blank" rel="noopener noreferrer">Официальный сайт IRLIX ↗</a>
      </footer>
    </main>
  </UiAppShell>
</template>
