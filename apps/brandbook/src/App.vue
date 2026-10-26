<script setup>
const props = defineProps({
  serviceAccess: { type: Object, required: true },
  currentUser: { type: Object, required: true },
});
const emit = defineEmits(['logout']);
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { UiAppShell, UiBadge, UiPanel, UiButton, UiErrorState, isPlatformAdministratorAccess } from '@irlix/ui';
import { articles, canonicalPath, filterArticles, initialSection, sections, statusInfo } from './catalog.js';
import siteLogo from './assets/irlix-site-wordmark.svg';
import lightLogo from './assets/irlix-site-wordmark-light-preview.svg';
import { logoDownloads, tokenDownloads, templateDownloads, emailDownloads } from './downloads.js';
import { carrierRules } from './carrierRules.js';
import { layoutRecipes, mediaRules, productionChecklist, digitalGridNote } from './visualSystem.js';
import { logoGuidelines, digitalPrinciples, communicationDirections, applicationPrinciples } from './guidelines.js';
import { brandVoice, aiEngineeringPillars, aiTrustPrinciples } from './marketing.js';
import { toneExamples, carrierExamples } from './brandSamples.js';
import { brandPlatform, brandAttributes, audiences } from './brandPlatform.js';
import { typographyLevels, brandSurfaceExamples, brandGraphicExamples, contrastRatio } from './visualExamples.js';

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

const route = useRoute();
const router = useRouter();
const current = computed(() => route.meta.section || 'not-found');
const search = ref('');
const notice = ref('');
const nav = sections.map(({id,label,icon})=>({id,label,icon,href:canonicalPath(id)}));
const selected = computed(()=>sections.find(item=>item.id===current.value) || sections[0]);
const matches = computed(()=>filterArticles(articles,search.value,search.value.trim()?'all':current.value));
const crumbs = computed(()=>[{label:selected.value.label}]);

function navigate(id, replace=false) {
  const next = sections.some(item => item.id === id) ? id : initialSection;
  router[replace ? 'replace' : 'push']({ name: next });
  search.value = '';
}
watch(() => route.fullPath, () => { search.value = ''; });

async function copyText(value,status='source'){
  try{
    await navigator.clipboard.writeText(value);
    notice.value=status==='approved' ? 'Скопировано со статусом согласования' : 'Скопировано с источником и пометкой о статусе';
  }
  catch(_){notice.value='Копирование недоступно в этом браузере';}
}
function printCurrentSection(){ window.print(); }
function copyArticle(article) {
  if (!['source','approved'].includes(article.status)) return;
  const provenance = article.status === 'source'
    ? ['Источник: ' + article.source.label + ' — ' + article.source.url,
       'Проверено: ' + article.checked,
       'Статус: опубликовано в источнике; не утверждённый бренд-стандарт IRLIX']
    : article.approval.scope === 'marketing'
      ? ['Статус: маркетинговая стратегия AI-augmented, ' + article.approval.date + ' (уровень проекта)',
         'Не подтверждает внедрение ИИ у всех специалистов, измеримое ускорение или согласие клиента на передачу данных']
      : ['Согласование: digital-основа v1.0, ' + article.approval.date + ' (уровень проекта)',
         'Разрешение на цифровые SVG подтверждено инициатором 24.10.2026; клиентские материалы, полиграфия и поисковая индексация требуют отдельных решений'];
  const text = [article.description,...provenance].join('\n');
  return copyText(text,article.status);
}
</script>

<template>
  <a class="brandbook-skip-link" href="#brandbook-main">К основному содержимому</a>
  <UiAppShell service="brandbook" service-name="Брендбук IRLIX"
    :section="current" :items="nav" :breadcrumbs="crumbs"
    :account-enabled="true" :current-user="currentUser"
    :platform-admin="isPlatformAdministratorAccess(serviceAccess)" :service-access="serviceAccess"
    @update:section="navigate" @logout="emit('logout')">
    <main id="brandbook-main" class="brandbook-page" tabindex="-1">
      <UiErrorState v-if="current==='not-found'" :status="404" back-href="/brandbook/identity/" back-label="К брендбуку" />
      <template v-else>
      <div class="brandbook-heading">
        <div>
          <p class="brandbook-eyebrow">IRLIX / BRAND REFERENCE</p>
          <h1>{{ selected.label }}</h1>
          <p class="brandbook-subtitle">{{ selected.subtitle }}</p>
        </div>
        <div class="brandbook-heading__actions">
          <UiBadge tone="info">Digital v1.0 · согласовано в проекте</UiBadge>
          <UiButton variant="secondary" type="button" aria-label="Распечатать текущий раздел брендбука" @click="printCurrentSection">Печать раздела</UiButton>
        </div>
      </div>
      <div class="brandbook-disclaimer" role="note">
        <strong>Цифровая основа IRLIX v1.0 согласована внутри проекта.</strong>
        <span class="brandbook-disclaimer__summary">Полный корпоративный стандарт и новые материалы ещё требуют проверки.</span>
        <details class="brandbook-disclaimer__details">
          <summary>Подробнее о статусе и ограничениях</summary>
          <p>Логотип для экрана, палитра и Onest утверждены как рабочие правила проекта. Публичные факты сопровождаются источниками. Скачивание трёх цифровых SVG подтверждено инициатором проекта, а доступ к библиотеке защищён SSO. Клиентские материалы, фотографии, новые рекламные утверждения, печатные варианты и поисковая индексация согласуются отдельно.</p>
        </details>
      </div>
      <section v-if="current === 'identity' && !search" class="brandbook-overview" aria-labelledby="brandbook-overview-title">
        <div class="brandbook-overview__copy">
          <p class="brandbook-overview__eyebrow">IRLIX / AI-AUGMENTED ENGINEERING · BRAND DIRECTION</p>
          <h2 id="brandbook-overview-title">{{ brandVoice.headline }}</h2>
          <p>{{ brandVoice.description }}</p>
          <p class="brandbook-overview__principle">Сначала задача. Потом решение. ИИ — инструмент усиления, а не замена инженерного мышления.</p>
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
      <section v-if="current === 'identity' && !search" class="brandbook-ai" aria-labelledby="brandbook-ai-title">
        <div class="brandbook-guide__heading">
          <h2 id="brandbook-ai-title">Три опоры инженерной работы</h2>
          <p>ИИ усиливает специалистов там, где его применение обосновано. Человек сохраняет ответственность за технические решения.</p>
        </div>
        <div class="brandbook-guide__tiles brandbook-guide__tiles--three">
          <article v-for="pillar in aiEngineeringPillars" :key="pillar.id" class="brandbook-guide__tile">
            <h3>{{ pillar.title }}</h3>
            <p>{{ pillar.description }}</p>
          </article>
        </div>
        <p class="brandbook-guide__caveat">Это выбранный стандарт коммуникации и цель развития практики. Мы не заявляем, что каждый действующий специалист или проект уже соответствует единой AI-методике.</p>
      </section>
      <section v-if="current === 'identity' && !search" class="brandbook-platform" aria-labelledby="brandbook-platform-title">
        <div class="brandbook-guide__heading">
          <h2 id="brandbook-platform-title">Смысловая основа IRLIX</h2>
          <p>Редакционное маркетинговое направление брендбука. Не является отдельной HR-политикой или гарантией одинаковой работы всех команд.</p>
        </div>
        <div class="brandbook-platform__statements">
          <article class="brandbook-platform__statement"><small>МИССИЯ БРЕНДА</small><h3>{{ brandPlatform.mission }}</h3></article>
          <article class="brandbook-platform__statement"><small>НАШ ВЗГЛЯД</small><h3>{{ brandPlatform.vision }}</h3></article>
        </div>
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection"><h3>Характер бренда</h3><p>Свойства коммуникации, не утверждённые кадровые компетенции всех сотрудников.</p></div>
        <div class="brandbook-platform__attributes">
          <article v-for="attribute in brandAttributes" :key="attribute.id"><h4>{{ attribute.name }}</h4><p>{{ attribute.meaning }}</p></article>
        </div>
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection"><h3>Для кого мы говорим</h3><p>Примеры обращения к типовым ролям заказчика, без выдуманных отраслевых кейсов.</p></div>
        <div class="brandbook-platform__audiences">
          <article v-for="audience in audiences" :key="audience.id">
            <h4>{{ audience.role }}</h4><p>{{ audience.need }}</p><blockquote>{{ audience.message }}</blockquote>
          </article>
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
          <p>По подтверждению инициатора от 24.10.2026 эти три цифровых SVG разрешены для фирменного использования по правилам брендбука. Печатные версии и произвольный редизайн не утверждены.</p>
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
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection">
          <h3 id="brandbook-graphic-examples-title">Наглядный графический язык</h3>
          <p>Образцы построены на типографике, цветах и структуре. Без неподтверждённых фото и чужих знаков.</p>
        </div>
        <div class="brandbook-graphic-examples" aria-labelledby="brandbook-graphic-examples-title">
          <article v-for="sample in brandGraphicExamples" :key="sample.id" class="brandbook-graphic-example">
            <div class="brandbook-graphic-example__preview" :class="'brandbook-graphic-example__preview--'+sample.id" aria-hidden="true">
              <template v-if="sample.id === 'hierarchy'">
                <span>01 / Фокус</span><strong>Сначала смысл</strong><i></i><i></i><i></i>
              </template>
              <template v-else-if="sample.id === 'restraint'">
                <span>02 / Акцент</span><div class="brandbook-graphic-example__line"></div><strong>Ясная структура</strong><small>Один смысловой акцент</small>
              </template>
              <template v-else>
                <span>03 / Источники</span><strong>Проверяемые факты</strong><small>Вместо случайных фотографий</small><div class="brandbook-graphic-example__source"></div>
              </template>
            </div>
            <h4>{{ sample.title }}</h4><p>{{ sample.description }}</p>
          </article>
        </div>
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection">
          <h3 id="brandbook-layout-system-title">Три воспроизводимые композиции</h3>
          <p>Одинаковая иерархия смыслов на web, в презентации, HR и КП. Это примеры проектного digital-оформления, а не новые обязательные юридические размеры знака.</p>
        </div>
        <div class="brandbook-layout-grid" aria-labelledby="brandbook-layout-system-title">
          <article v-for="recipe in layoutRecipes" :key="recipe.id" class="brandbook-layout-card">
            <div class="brandbook-layout-card__visual" :class="'brandbook-layout-card__visual--'+recipe.visual" role="img" :aria-label="'Схема композиции: '+recipe.structure">
              <span class="brandbook-layout-card__micro">IRLIX / {{ recipe.number }}</span>
              <div class="brandbook-layout-card__hero"></div>
              <div class="brandbook-layout-card__heading"></div>
              <div class="brandbook-layout-card__line"></div>
              <div class="brandbook-layout-card__line brandbook-layout-card__line--short"></div>
              <div class="brandbook-layout-card__accent"></div>
            </div>
            <div class="brandbook-layout-card__body">
              <span class="brandbook-layout-card__format">{{ recipe.format }}</span>
              <h4>{{ recipe.title }}</h4>
              <p>{{ recipe.structure }}</p>
              <dl>
                <div><dt>Делаем</dt><dd>{{ recipe.do }}</dd></div>
                <div><dt>Не делаем</dt><dd>{{ recipe.avoid }}</dd></div>
                <div><dt>Проверяем</dt><dd>{{ recipe.check }}</dd></div>
              </dl>
            </div>
          </article>
        </div>
        <p class="brandbook-guide__caveat">{{ digitalGridNote }}</p>
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection">
          <h3 id="brandbook-media-rules-title">Фото, иллюстрации и иконки</h3>
          <p>Редакторские правила обращения с изображениями. Необоснованное изображение не обязательнее честного текстового макета.</p>
        </div>
        <div class="brandbook-media-grid" aria-labelledby="brandbook-media-rules-title">
          <article v-for="medium in mediaRules" :key="medium.id" class="brandbook-media-card">
            <h4>{{ medium.name }}</h4>
            <p><strong>Можно:</strong> {{ medium.usable }}</p>
            <p><strong>Если исходника нет:</strong> {{ medium.fallback }}</p>
            <p><strong>Нельзя:</strong> {{ medium.prohibited }}</p>
          </article>
        </div>
        <p class="brandbook-guide__caveat">Утверждённый фотобанк, единый коммерческий icon pack и лицензии на сторонние изображения пока отсутствуют. Добавление реальных изображений и прав на них — отдельный этап.</p>
      </section>
      <section v-if="current === 'voice' && !search" class="brandbook-guide" aria-labelledby="brandbook-positioning-title">
        <div class="brandbook-guide__heading">
          <h2 id="brandbook-positioning-title">ИИ + инженерная экспертиза + ответственность</h2>
          <p>Выбранное направление бренд-коммуникаций · не доказательство внедрения ИИ на всех проектах</p>
        </div>
        <div class="brandbook-guide__tiles brandbook-guide__tiles--three">
          <article v-for="principle in aiTrustPrinciples" :key="principle.id" class="brandbook-guide__tile">
            <h3>{{ principle.title }}</h3>
            <p>{{ principle.description }}</p>
          </article>
        </div>
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection">
          <h3>Наш подход к задаче и форматам</h3>
          <p>Прежняя A+B+C — часть инженерной логики, а не конкурент ИИ.</p>
        </div>
        <div class="brandbook-guide__tiles brandbook-guide__tiles--three">
          <article v-for="direction in communicationDirections" :key="direction.code" class="brandbook-guide__tile">
            <span class="brandbook-guide__letter">{{ direction.code }}</span>
            <h3>{{ direction.title }}</h3>
            <p>{{ direction.description }}</p>
          </article>
        </div>
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection">
          <h3>Основа A+B+C остаётся</h3>
          <p>Понимание задачи, инженерная глубина, варианты сотрудничества.</p>
        </div>
        <p class="brandbook-guide__caveat">Использование ИИ не заменяет инженерные компетенции и не означает обещания ускорения, экономии или отсутствия ошибок. Новые AI-продукты и корпоративный стандарт инструментов требуют отдельной проверки.</p>
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection">
          <h3 id="brandbook-voice-examples-title">Как звучит IRLIX</h3>
          <p>Конкретные примеры фирменной речи. Вариант «Избегаем» — антипример, а не реальное рекламное обещание компании.</p>
        </div>
        <div class="brandbook-voice-examples" aria-labelledby="brandbook-voice-examples-title">
          <article v-for="sample in toneExamples" :key="sample.topic" class="brandbook-voice-sample">
            <h4>{{ sample.topic }}</h4>
            <div class="brandbook-voice-sample__pair">
              <div class="brandbook-voice-sample__good"><strong>Говорим</strong><p>{{ sample.preferred }}</p></div>
              <div class="brandbook-voice-sample__bad"><strong>Избегаем</strong><p>{{ sample.avoid }}</p></div>
            </div>
            <p class="brandbook-voice-sample__why">{{ sample.reason }}</p>
          </article>
        </div>
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
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection">
          <h3 id="brandbook-composition-title">Правила компоновки по носителям</h3>
          <p>Рабочие цифровые рекомендации, а не утверждённые миллиметровые поля, вылеты или CMYK-мастера.</p>
        </div>
        <div class="brandbook-composition-grid" aria-labelledby="brandbook-composition-title">
          <article v-for="rule in carrierRules" :key="rule.id" class="brandbook-composition-card">
            <span class="brandbook-composition-card__format">{{ rule.format }}</span>
            <h4>{{ rule.name }}</h4>
            <p class="brandbook-composition-card__focus">{{ rule.focus }}</p>
            <dl>
              <div><dt>Делаем</dt><dd>{{ rule.do }}</dd></div>
              <div><dt>Избегаем</dt><dd>{{ rule.avoid }}</dd></div>
              <div><dt>Проверяем</dt><dd>{{ rule.verify }}</dd></div>
            </dl>
          </article>
        </div>
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection">
          <h3 id="brandbook-carrier-review-title">Пять проверок перед использованием</h3>
          <p>Контрольный список нужен каждому, кто адаптирует фирменный носитель. Он не заменяет юридическое, коммерческое или кадровое согласование.</p>
        </div>
        <ol class="brandbook-production-list" aria-labelledby="brandbook-carrier-review-title">
          <li v-for="item in productionChecklist" :key="item.id">
            <strong>{{ item.label }}</strong>
            <span>{{ item.description }}</span>
          </li>
        </ol>
        <div class="brandbook-guide__heading brandbook-guide__heading--subsection">
          <h3 id="brandbook-carrier-examples-title">Как выглядит фирменный стиль на носителях</h3>
          <p>Адаптивные примеры оформления: только утверждённые SVG, цвета, Onest и выбранная брендовая речь. Это образцы композиции, не готовые клиентские материалы.</p>
        </div>
        <div class="brandbook-carriers" aria-labelledby="brandbook-carrier-examples-title">
          <article v-for="sample in carrierExamples" :key="sample.id" class="brandbook-carrier">
            <div class="brandbook-carrier__frame" :class="'brandbook-carrier__frame--'+sample.id">
              <div class="brandbook-carrier__page">
                <div class="brandbook-carrier__masthead">
                  <img :src="lightLogo" alt="Логотип IRLIX — цифровая версия для светлого фона" width="106" height="46" />
                  <span>IRLIX / {{ sample.label }}</span>
                </div>
                <template v-if="sample.id === 'website'">
                  <div class="brandbook-carrier__website">
                    <span class="brandbook-carrier__eyebrow">Инженерная экспертиза × технологии × ИИ</span>
                    <h4>{{ sample.heading }}</h4>
                    <p>Опыт инженеров и возможности ИИ — для задач бизнеса. За решения отвечают специалисты.</p>
                    <span class="brandbook-carrier__cta">Обсудить задачу <span aria-hidden="true">↗</span></span>
                  </div>
                </template>
                <template v-else-if="sample.id === 'presentation'">
                  <div class="brandbook-carrier__slide">
                    <span class="brandbook-carrier__eyebrow">ENGINEER-LED · AI-AUGMENTED</span>
                    <h4>{{ sample.heading }}</h4>
                    <p>Инженерная разработка, усиленная ИИ.</p>
                  </div>
                </template>
                <template v-else>
                  <div class="brandbook-carrier__proposal">
                    <span class="brandbook-carrier__eyebrow">IRLIX / ПРИМЕР ОФОРМЛЕНИЯ</span>
                    <h4>{{ sample.heading }}</h4>
                    <p>Сначала задача. Потом решение.</p>
                    <dl>
                      <div><dt>Задача</dt><dd>Формулировка согласованного контекста</dd></div>
                      <div><dt>Формат</dt><dd>Специалист / команда / под ключ</dd></div>
                      <div><dt>Условия</dt><dd>Определяются отдельным предложением</dd></div>
                    </dl>
                  </div>
                </template>
              </div>
            </div>
            <div class="brandbook-carrier__caption">
              <strong>{{ sample.kind }}</strong>
              <p>{{ sample.description }}</p>
              <small>Демонстрационный макет · не готовый файл для клиента</small>
            </div>
          </article>
        </div>
        <p class="brandbook-guide__caveat">Примеры выше показывают композицию. Редактируемые шаблоны, изображения, кейсы и реальные КП публикуются отдельно после проверки контента и прав.</p>
      </section>
      <section v-if="current === 'assets' && !search" class="brandbook-downloads" aria-label="Утверждённые SVG-файлы логотипа">
        <div class="brandbook-downloads__intro">
          <h2>Логотип IRLIX · SVG для скачивания</h2>
          <p>Цифровое использование трёх SVG подтверждено инициатором проекта. Сохраняйте пропорции, исходные контуры и оба цвета X. Разрешение не распространяется на перепродажу знака или регистрацию производного товарного знака.</p>
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
        <section class="brandbook-downloads__tokens" aria-labelledby="brandbook-token-downloads-title">
          <h3 id="brandbook-token-downloads-title">Digital-токены для разработчиков</h3>
          <p>Согласованные цвета и начертания шрифта в форматах CSS и JSON. Эти файлы не заменяют общую дизайн-систему платформы и не содержат печатных спецификаций.</p>
          <div class="brandbook-downloads__token-links">
            <a v-for="asset in tokenDownloads" :key="asset.id" :href="asset.href" :download="asset.filename">{{ asset.label }} <span aria-hidden="true">↓</span></a>
          </div>
        </section>
        <section class="brandbook-downloads__templates" aria-labelledby="brandbook-template-downloads-title">
          <h3 id="brandbook-template-downloads-title">Редактируемые векторные образцы</h3>
          <p>Девять редактируемых SVG-макетов: титульный слайд и кейс 16:9, обложка КП и бланк A4, квадратный и вертикальный посты, HR-вакансия, горизонтальный баннер и цифровая визитка. Это примеры построения фирменного оформления, а не утверждённые договоры, печатные мастера или подтверждённые клиентские кейсы.</p>
          <div class="brandbook-downloads__template-grid">
            <article v-for="asset in templateDownloads" :key="asset.id" class="brandbook-downloads__template">
              <div class="brandbook-downloads__template-preview">
                <img :src="asset.href" :alt="'Предпросмотр: '+asset.label" loading="lazy" decoding="async" />
              </div>
              <h4>{{ asset.label }}</h4><p>{{ asset.details }}</p>
              <a :href="asset.href" :download="asset.filename">Скачать редактируемый SVG <span aria-hidden="true">↓</span></a>
            </article>
          </div>
          <p class="brandbook-downloads__template-note">Тексты и слои можно редактировать в векторном редакторе. Персональные поля являются заполнителями; для точного воспроизведения установите Onest. Исходная геометрия логотипа должна сохраняться. Формат SVG не заменяет PowerPoint, DOCX или полиграфический PDF.</p>
        </section>
        <section class="brandbook-downloads__templates" aria-labelledby="brandbook-email-downloads-title">
          <h3 id="brandbook-email-downloads-title">Шаблон email-подписи</h3>
          <p>Самостоятельный HTML без скриптов и персональных данных. Текстовое название компании не подменяет графический логотип; для почты предусмотрен системный шрифт с предсказуемым отображением.</p>
          <div class="brandbook-downloads__template-grid">
            <article v-for="asset in emailDownloads" :key="asset.id" class="brandbook-downloads__template">
              <h4>{{ asset.label }}</h4>
              <p>{{ asset.details }}</p>
              <a :href="asset.href" :download="asset.filename">Скачать редактируемый HTML <span aria-hidden="true">↓</span></a>
            </article>
          </div>
        </section>
        <p class="brandbook-downloads__scope">Право фирменного использования трёх оригинальных SVG логотипа подтверждено инициатором 24.10.2026. Редактируемые компоновки выше — новые проектные образцы на их основе, а не отдельные согласованные печатные мастера. Клиентские кейсы, фотографии, мерч и печатные цветопробы требуют проверки прав и согласования.</p>
      </section>
      <section v-if="current === 'colors' && !search" class="brandbook-swatches" aria-label="Согласованная палитра для цифровых носителей">
        <div v-for="color in brandColors" :key="color.value" class="brandbook-swatch">
          <span class="brandbook-swatch__sample" :style="{backgroundColor:color.value}" aria-hidden="true"></span>
          <div class="brandbook-swatch__meta"><strong>{{ color.name }}</strong><code>{{ color.value }}</code><span>{{ color.description }}</span></div>
        </div>
      </section>
      <section v-if="current === 'colors' && !search" class="brandbook-usage-demo" aria-labelledby="brandbook-color-pair-title">
        <div class="brandbook-guide__heading">
          <h2 id="brandbook-color-pair-title">Проверенные цветовые сочетания</h2>
          <p>Рекомендованные digital-примеры с рассчитанным контрастом текста. Двухцветная X остаётся знаком, а не цветом мелкого текста.</p>
        </div>
        <div class="brandbook-color-pairs">
          <article v-for="pair in brandSurfaceExamples" :key="pair.id" class="brandbook-color-pair">
            <div class="brandbook-color-pair__preview" :style="{backgroundColor:pair.background,color:pair.foreground}">
              <span>{{ pair.sample }}</span>
              <small>{{ pair.foreground }} / {{ pair.background }}</small>
            </div>
            <h3>{{ pair.role }}</h3>
            <p>{{ pair.context }}</p>
            <small>Контраст текста {{ contrastRatio(pair.foreground,pair.background).toFixed(2) }}:1</small>
          </article>
        </div>
      </section>
      <section v-if="current === 'typography' && !search" class="brandbook-type-preview" aria-label="Образец основного шрифта">
        <small>ONEST · 700 / 400 · DIGITAL V1.0</small>
        <h2>{{ brandVoice.headline }}</h2>
        <p>Образец типографики Onest: кириллица, цифры 0123456789 и межстрочный интервал. Основной заголовок соответствует текущему маркетинговому направлению IRLIX; не является доказательством измеримых преимуществ ИИ.</p>
        <div class="brandbook-type-scale" aria-label="Примеры уровней типографики">
          <div v-for="level in typographyLevels" :key="level.id" class="brandbook-type-scale__item">
            <span class="brandbook-type-scale__label">{{ level.role }} · {{ level.detail }}</span>
            <p :style="{fontSize:level.size,fontWeight:level.weight}">{{ level.sample }}</p>
          </div>
        </div>
        <p class="brandbook-type-scale__note">Размеры в таблице — образец иерархии для экрана, не правило для печати или неизменяемый масштаб на любых устройствах.</p>
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
            <span v-else-if="article.approval">{{ article.approval.scope === "marketing" ? "Маркетинговое направление" : "Согласовано для digital" }} · {{ article.approval.date }} · уровень проекта</span>
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
        Источники сверены 24.10.2026 · Digital-основа согласована, три фирменных SVG разрешены для публичного скачивания · сервис доступен по SSO назначенным ролям; поисковая индексация не утверждена ·
        <a href="https://irlix.com/" target="_blank" rel="noopener noreferrer">Официальный сайт IRLIX ↗</a>
      </footer>
      </template>
    </main>
  </UiAppShell>
</template>
