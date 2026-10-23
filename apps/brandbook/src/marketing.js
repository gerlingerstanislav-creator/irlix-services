// IRLIX verbal identity selected by the delegated project marketing role, 24 Oct 2026.
// Project-level editorial standard; not proof of market exclusivity or an operational guarantee.
// Active reference: private ideas brandbook/company/irlix/AI_AUGMENTED_POSITIONING_V17_2026_10_24.md.
// AI-augmented / engineer-led; no universal claims about active staff adoption, speed or security.
export const brandVoice = Object.freeze({
  category:'Инженерная разработка ПО с осмысленным использованием ИИ',
  headline:'Инженерная разработка, усиленная ИИ.',
  description:'Объединяем инженерный опыт, знание технологий и возможности искусственного интеллекта. ИИ помогает решать задачи, а за архитектуру, качество и технические решения отвечают специалисты.',
  action:'Обсудить задачу',
  secondaryAction:'Принципы работы с ИИ',
  approval:'AI-augmented направление выбрано инициатором, формулировка — маркетинг · 24.10.2026',
});
export const brandDifference = Object.freeze([
  Object.freeze({id:'problem',title:'Уточнить задачу',description:'Начать диалог с бизнес-контекста, ограничений и того, что заказчик считает полезным результатом.'}),
  Object.freeze({id:'format',title:'Объяснить формат',description:'Рассмотреть подходящий способ работы: специалист, выделенная команда или согласованный объём под ключ — без навязывания универсального варианта.'}),
  Object.freeze({id:'responsibility',title:'Прояснить ответственность',description:'Обсудить роли, зоны ответственности, риски и критерии результата. Фиксировать конкретные обязательства только в согласованном предложении и договоре.'}),
]);
export const audienceNeeds = Object.freeze([
  Object.freeze({id:'engineering',title:'Не хватает компетенции или ресурсов',description:'Начать с конкретной задачи и рассмотреть подключение специалиста или команды.'}),
  Object.freeze({id:'systems',title:'Текущая система мешает развитию',description:'Начать с понимания неисправности или ограничений; рассмотреть обследование и план доработки.'}),
  Object.freeze({id:'delivery',title:'Нужен цифровой продукт или крупный модуль',description:'Обсудить требования, состав работ, ограничения и ответственность за согласованный объём под ключ.'}),
]);
export const voiceGuidelines = Object.freeze([
  Object.freeze({preferred:'Обсудим задачу и ограничения',avoid:'Решим любую задачу на 100%'}),
  Object.freeze({preferred:'Предложим подходящий формат работы',avoid:'Найдём лучших разработчиков за один день'}),
  Object.freeze({preferred:'Согласуем роли и критерии результата',avoid:'Гарантируем успех без рисков'}),
  Object.freeze({preferred:'Покажем подтверждённый кейс с разрешением клиента',avoid:'Придумаем громкие цифры ради рекламы'}),
]);

export const aiEngineeringPillars = Object.freeze([
  Object.freeze({
    id:'expertise',title:'Опыт и технологии',
    description:'Знание архитектуры, стека, интеграций и задач бизнеса остаётся основой инженерной работы. ИИ не заменяет понимание системы.',
  }),
  Object.freeze({
    id:'augment',title:'ИИ там, где полезен',
    description:'Рассматриваем ИИ как инструмент поддержки анализа, кода, тестирования и рутины. Конкретное применение зависит от проекта, пользы и согласованных ограничений.',
  }),
  Object.freeze({
    id:'accountability',title:'Ответственность специалистов',
    description:'Технические решения, проверка качества, безопасность и приёмка требуют участия ответственных инженеров. Сгенерированный результат не принимается автоматически.',
  }),
]);
export const aiTrustPrinciples = Object.freeze([
  Object.freeze({id:'verify',title:'Проверять, а не верить',description:'Проверять требования, код и тесты человеками и автоматизированными средствами, не выдавать генерацию за готовый продукт.'}),
  Object.freeze({id:'privacy',title:'Уважать контур заказчика',description:'Не передавать исходники, персональные данные и коммерческие сведения внешним моделям без разрешённого проектом режима.'}),
  Object.freeze({id:'choice',title:'Работать и без ИИ',description:'Если инструмент неуместен или запрещён, использовать обычный инженерный процесс, а не навязывать модель.'}),
]);
