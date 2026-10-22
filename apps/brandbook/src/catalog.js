import { irlixArticles } from './content/irlix.js';
// Publicly sourced research only. No private notes, assets, or unapproved brand constants.
// Status 'source' means "published at source", NOT an approved brand rule.
export const sections = Object.freeze([
  {id:'identity',label:'Компания',icon:'briefcase',subtitle:'Публичный профиль и позиционирование'},
  {id:'logo',label:'Логотип',icon:'palette',subtitle:'Мастер-исходники и правила использования'},
  {id:'colors',label:'Цвета',icon:'palette',subtitle:'Фирменная палитра и коды'},
  {id:'typography',label:'Типографика',icon:'document',subtitle:'Шрифты и иерархия'},
  {id:'graphics',label:'Графический стиль',icon:'palette',subtitle:'Фотографии, иконки и иллюстрации'},
  {id:'voice',label:'Коммуникации',icon:'contact',subtitle:'Тон общения и вербальная идентичность'},
  {id:'applications',label:'Применение',icon:'document',subtitle:'Презентации, документы, digital'},
  {id:'assets',label:'Библиотека',icon:'list',subtitle:'Утверждённые ресурсы для скачивания'},
  {id:'changelog',label:'Версии',icon:'audit',subtitle:'История утверждений и изменений'},
]);
export const articles = irlixArticles;

export const statusInfo = Object.freeze({
 source:{label:'Опубликовано в источнике',tone:'info'},
 pending:{label:'Ожидает утверждения',tone:'warning'},
 research:{label:'Исследование',tone:'neutral'},
 approved:{label:'Утверждено',tone:'success'},
});

export const initialSection = 'identity';
export function sectionForPath(pathname) {
  const match = /^\/brandbook\/(?:([^/?#]+)\/?)?$/.exec(String(pathname ?? ''));
  const id = match?.[1] || initialSection;
  return sections.some(section=>section.id===id) ? id : initialSection;
}
export function canonicalPath(id) {
  return '/brandbook/' + (sections.some(section=>section.id===id) ? id : initialSection) + '/';
}
export function filterArticles(items, search, section) {
  const query = String(search || '').trim().toLocaleLowerCase('ru-RU');
  return items.filter(article=>(
    (section === 'all' || article.section === section) &&
    (!query || [article.title,article.description,article.source?.label].some(value=>String(value||'').toLocaleLowerCase('ru-RU').includes(query)))
  ));
}
