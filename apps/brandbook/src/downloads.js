// Owner-authorized public SVG files only. No client/HR data or unpublished masters.
// Authorization: ideas/.../company/irlix/LOGO_DISTRIBUTION_AUTHORIZATION_2026_10_24.md
// The Vite public/ directory is served at the /brandbook/ base path.
export const logoDownloads = Object.freeze([
  Object.freeze({
    id: 'dark',
    title: 'Логотип на тёмном',
    context: 'Белые буквы IRLI и исходная двухцветная X. Для тёмной поверхности.',
    filename: 'irlix-logo-dark-background.svg',
    href: '/brandbook/downloads/irlix-logo-dark-background.svg',
    previewBackground: '#142820',
    alt: 'IRLIX белыми буквами, бирюзовая и светло-зелёная X',
  }),
  Object.freeze({
    id: 'light',
    title: 'Логотип на светлом',
    context: 'Графитовые буквы IRLI и исходная двухцветная X. Для белого и светлого фона.',
    filename: 'irlix-logo-light-background.svg',
    href: '/brandbook/downloads/irlix-logo-light-background.svg',
    previewBackground: '#F7FAF8',
    alt: 'IRLIX тёмными буквами, бирюзовая и светло-зелёная X',
  }),
  Object.freeze({
    id: 'symbol',
    title: 'Символ X',
    context: 'Дополнительный знак для favicon, аватаров и маленьких иконок. Не заменяет полный логотип.',
    filename: 'irlix-symbol-x.svg',
    href: '/brandbook/downloads/irlix-symbol-x.svg',
    previewBackground: '#F7FAF8',
    alt: 'Изолированная двухцветная X из фирменного логотипа IRLIX',
  }),
]);

export const tokenDownloads = Object.freeze([
  Object.freeze({id:'css',label:'CSS variables',filename:'irlix-digital-v1.css',href:'/brandbook/downloads/irlix-digital-v1.css'}),
  Object.freeze({id:'json',label:'JSON tokens',filename:'irlix-digital-v1.json',href:'/brandbook/downloads/irlix-digital-v1.json'}),
]);


export const templateDownloads = Object.freeze([
  Object.freeze({id:'slide',label:'Титульный слайд · 16:9',filename:'irlix-template-presentation-cover.svg',href:'/brandbook/downloads/irlix-template-presentation-cover.svg',details:'Редактируемый вектор, 1600 × 900 · цвета и логотип IRLIX.'}),
  Object.freeze({id:'proposal',label:'Обложка предложения · A4',filename:'irlix-template-proposal-cover-a4.svg',href:'/brandbook/downloads/irlix-template-proposal-cover-a4.svg',details:'Редактируемый вектор 794 × 1123 · демонстрационные поля вместо данных заказчика.'}),
  Object.freeze({id:'social',label:'Квадратный digital-макет · 1:1',filename:'irlix-template-social-square.svg',href:'/brandbook/downloads/irlix-template-social-square.svg',details:'Редактируемый вектор, 1080 × 1080 · тёмная фирменная композиция.'}),
  Object.freeze({id:'letterhead',label:'Фирменный бланк · A4',filename:'irlix-template-letterhead-a4.svg',href:'/brandbook/downloads/irlix-template-letterhead-a4.svg',details:'Редактируемый цифровой бланк 794 × 1123, с пустыми полями для документа и реквизитов. Не печатный мастер.'}),
  Object.freeze({id:'card',label:'Визитка · цифровой макет',filename:'irlix-template-business-card.svg',href:'/brandbook/downloads/irlix-template-business-card.svg',details:'Редактируемая композиция 1080 × 600, с заполнителями личных контактов. Не полиграфический макет.'}),
]);
export const emailDownloads = Object.freeze([
  Object.freeze({id:'signature',label:'Email-подпись · HTML',filename:'irlix-template-email-signature.html',href:'/brandbook/downloads/irlix-template-email-signature.html',details:'Адаптивный табличный шаблон без скриптов и личных данных. Замените все поля в квадратных скобках; текст IRLIX не заменяет официальный SVG-логотип.'}),
]);

// Vector layouts are project-level examples, not licensed customer case studies or print masters.
