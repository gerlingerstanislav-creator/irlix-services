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
