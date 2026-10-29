<script setup>
import { ref } from 'vue';
import { UiButton } from '@irlix/ui';
import lightLogo from './assets/irlix-site-wordmark-light-preview.svg';
import darkLogo from './assets/irlix-site-wordmark.svg';
import { brandVoice } from './marketing.js';

const previewMode = ref('desktop');
const menuOpen = ref(false);
const formats = Object.freeze([
  { number: '01', title: 'Специалист', kicker: 'Усиление команды', description: 'Подключение нужной инженерной компетенции к задачам и процессам заказчика.' },
  { number: '02', title: 'Команда', kicker: 'Совместная разработка', description: 'Выделенная команда под согласованный объём работ, роли и взаимодействие.' },
  { number: '03', title: 'Под ключ', kicker: 'Ответственность за результат', description: 'Разработка продукта или модуля с определёнными требованиями и критериями приёмки.' },
]);
const steps = Object.freeze([
  { number: '01', title: 'Понимаем задачу', description: 'Обсуждаем цели, контекст бизнеса и ограничения — до выбора технологий.' },
  { number: '02', title: 'Выбираем подход', description: 'Определяем формат работы, инженерные решения и допустимые инструменты.' },
  { number: '03', title: 'Проверяем результат', description: 'Согласуем качество, ответственность и критерии приёмки.' },
]);
function switchPreview(mode) {
  previewMode.value = mode;
  menuOpen.value = false;
}
function closeMenu() { menuOpen.value = false; }
</script>

<template>
  <section class="website-concept" aria-labelledby="website-concept-title">
    <div class="website-concept__toolbar">
      <div>
        <p class="website-concept__eyebrow">IRLIX / DIGITAL EXPERIENCE / CONCEPT 01</p>
        <h1 id="website-concept-title">Концепция корпоративного сайта</h1>
        <p>Полная главная страница для обсуждения дизайна, структуры и содержания.</p>
      </div>
      <div class="website-concept__switch" role="group" aria-label="Ширина предпросмотра">
        <UiButton type="button" :variant="previewMode === 'desktop' ? 'primary' : 'secondary'" :aria-pressed="previewMode === 'desktop'" @click="switchPreview('desktop')">Компьютер</UiButton>
        <UiButton type="button" :variant="previewMode === 'mobile' ? 'primary' : 'secondary'" :aria-pressed="previewMode === 'mobile'" @click="switchPreview('mobile')">Телефон</UiButton>
      </div>
    </div>
    <p class="website-concept__note" role="note">Внутренний интерактивный прототип, не опубликованный корпоративный сайт. Тексты — редакционная концепция; здесь нет отправки заявок, неподтверждённых кейсов или числовых обещаний.</p>

    <div class="site-stage" :class="{ 'site-stage--mobile': previewMode === 'mobile' }">
      <div class="site-canvas">
        <header class="site-header">
          <a class="site-header__brand" href="#site-concept-home" aria-label="IRLIX — в начало концепции" @click="closeMenu">
            <img :src="lightLogo" alt="IRLIX" width="106" height="46" />
          </a>
          <button class="site-header__menu" type="button" :aria-expanded="menuOpen" aria-controls="site-concept-nav" @click="menuOpen = !menuOpen">
            <span aria-hidden="true">{{ menuOpen ? '×' : '☰' }}</span> Меню
          </button>
          <nav id="site-concept-nav" class="site-nav" :class="{ 'site-nav--open': menuOpen }" aria-label="Разделы демонстрационного сайта">
            <a href="#site-concept-benefits" @click="closeMenu">Подход</a>
            <a href="#site-concept-formats" @click="closeMenu">Форматы работы</a>
            <a href="#site-concept-method" @click="closeMenu">Процесс</a>
            <a class="site-nav__contact" href="#site-concept-contact" @click="closeMenu">Связаться <span aria-hidden="true">↗</span></a>
          </nav>
        </header>

        <main id="site-concept-home">
          <section class="site-hero" aria-labelledby="site-hero-title">
            <div class="site-hero__copy">
              <p class="site-kicker"><span class="site-kicker__line"></span> ENGINEERING × AI × PEOPLE</p>
              <h2 id="site-hero-title">Инженерная разработка, <em>усиленная ИИ.</em></h2>
              <p class="site-hero__lead">{{ brandVoice.description }}</p>
              <div class="site-hero__actions">
                <a class="site-link-button site-link-button--solid" href="#site-concept-contact">Обсудить задачу <span aria-hidden="true">↗</span></a>
                <a class="site-text-link" href="#site-concept-method">Как мы работаем <span aria-hidden="true">↗</span></a>
              </div>
              <div class="site-hero__foot"><span class="site-hero__foot-dot"></span> Сначала задача. Потом решение.</div>
            </div>
            <div class="site-hero__visual" aria-label="Абстрактная схема взаимодействия инженерного опыта, инструментов ИИ и проверки результата" role="img">
              <div class="site-visual__top"><span>IRLIX / ENGINEERING SYSTEM</span><span>01—03</span></div>
              <div class="site-visual__geometry" aria-hidden="true">
                <span class="site-visual__orbit site-visual__orbit--one"></span>
                <span class="site-visual__orbit site-visual__orbit--two"></span>
                <span class="site-visual__axis site-visual__axis--horizontal"></span>
                <span class="site-visual__axis site-visual__axis--vertical"></span>
                <span class="site-visual__core"><span>IQ<span class="site-visual__core-accent">×</span></span><small>HUMAN-LED</small></span>
                <span class="site-visual__point site-visual__point--a"></span>
                <span class="site-visual__point site-visual__point--b"></span>
                <span class="site-visual__point site-visual__point--c"></span>
              </div>
              <div class="site-visual__bottom">
                <span>01 / ОПЫТ</span><span>02 / ИНСТРУМЕНТЫ</span><span>03 / КОНТРОЛЬ</span>
              </div>
            </div>
          </section>

          <section id="site-concept-benefits" class="site-pillars" aria-label="Основа подхода">
            <div class="site-pillars__label"><span class="site-index">/ 01</span><p>Наш подход</p></div>
            <article class="site-pillars__item">
              <span class="site-pillars__symbol" aria-hidden="true">↗</span>
              <h3>Опыт и технологии</h3>
              <p>Архитектура, технологии и понимание задачи — основа решений.</p>
            </article>
            <article class="site-pillars__item">
              <span class="site-pillars__symbol" aria-hidden="true">✳</span>
              <h3>ИИ там, где полезен</h3>
              <p>Инструмент рассматриваем под конкретную задачу и ограничения.</p>
            </article>
            <article class="site-pillars__item">
              <span class="site-pillars__symbol" aria-hidden="true">✓</span>
              <h3>Ответственность людей</h3>
              <p>Качество и технические решения остаются за инженерами.</p>
            </article>
          </section>

          <section id="site-concept-formats" class="site-formats" aria-labelledby="site-formats-title">
            <div class="site-section-intro">
              <p class="site-section-eyebrow"><span class="site-index">/ 02</span> ФОРМАТЫ РАБОТЫ</p>
              <div class="site-section-intro__row">
                <h2 id="site-formats-title">Под задачу.<br /><em>Не под шаблон.</em></h2>
                <p>Разным проектам нужны разные способы взаимодействия. Обсудим, какой формат подходит вашим целям, команде и ограничениям.</p>
              </div>
            </div>
            <div class="site-format-grid">
              <article v-for="format in formats" :key="format.number" class="site-format-card">
                <div class="site-format-card__top"><span>{{ format.number }} / 03</span><span aria-hidden="true">↗</span></div>
                <div>
                  <p class="site-format-card__kicker">{{ format.kicker }}</p>
                  <h3>{{ format.title }}</h3>
                  <p>{{ format.description }}</p>
                </div>
              </article>
            </div>
          </section>

          <section class="site-statement" aria-labelledby="site-statement-title">
            <div class="site-statement__meta"><span>ENGINEERING FIRST</span><span class="site-statement__asterisk" aria-hidden="true">✳</span></div>
            <div class="site-statement__content">
              <p>НАШ ПРИНЦИП</p>
              <h2 id="site-statement-title">Технологии меняются.<br /><span>Ответственность остаётся.</span></h2>
              <p class="site-statement__description">ИИ может помогать в анализе, разработке и проверках — только там, где это уместно и согласовано. Решения принимают и проверяют специалисты.</p>
            </div>
            <div class="site-statement__bottom"><span>HUMAN-LED</span><span>AI-AUGMENTED</span><span>ACCOUNTABLE</span></div>
          </section>

          <section id="site-concept-method" class="site-method" aria-labelledby="site-method-title">
            <div class="site-method__intro">
              <p class="site-section-eyebrow"><span class="site-index">/ 03</span> НАШ ПОДХОД К РАБОТЕ</p>
              <h2 id="site-method-title">От вопроса —<br /><em>к решению.</em></h2>
              <p>Начинаем с понимания задачи, а не с продажи команды, стека или инструмента.</p>
            </div>
            <ol class="site-method__steps">
              <li v-for="step in steps" :key="step.number">
                <span class="site-method__number">{{ step.number }}</span>
                <div><h3>{{ step.title }}</h3><p>{{ step.description }}</p></div>
                <span class="site-method__arrow" aria-hidden="true">↗</span>
              </li>
            </ol>
          </section>

          <section id="site-concept-contact" class="site-contact" aria-labelledby="site-contact-title">
            <p class="site-section-eyebrow"><span class="site-index">/ 04</span> НАЧНЁМ С ДИАЛОГА</p>
            <div class="site-contact__row">
              <div><h2 id="site-contact-title">Есть задача?<br /><em>Давайте обсудим.</em></h2><p>Расскажите, чего хотите добиться. Начнём с цели, ограничений и подходящего формата работы.</p></div>
              <a class="site-link-button site-link-button--light" href="https://irlix.com/" target="_blank" rel="noopener noreferrer">Обсудить задачу <span aria-hidden="true">↗</span></a>
            </div>
          </section>
        </main>

        <footer class="site-footer">
          <img :src="darkLogo" alt="IRLIX" width="106" height="46" />
          <p>Инженерная разработка, усиленная ИИ.</p>
          <a href="https://irlix.com/" target="_blank" rel="noopener noreferrer">Действующий сайт irlix.com <span aria-hidden="true">↗</span></a>
        </footer>
      </div>
    </div>
    <p class="website-concept__under">Навигация и переключение ширины работают внутри страницы. Визуальная концепция не меняет действующий irlix.com и не публикует контактную форму.</p>
  </section>
</template>

<style scoped src="./websiteConcept.css"></style>
