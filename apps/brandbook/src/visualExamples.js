// IRLIX digital brandbook: visual demonstrations derived from the approved light-first baseline.
// Example sizes/patterns are editorial web guidance, not a legal/print logo master.
export const typographyLevels = Object.freeze([
 Object.freeze({id:'hero',role:'Ключевой заголовок',sample:'Инженерная разработка, усиленная ИИ.',detail:'Onest 700 · 40 px в данном web-примере',size:'40px',weight:700}),
 Object.freeze({id:'heading',role:'Заголовок раздела',sample:'Опыт, технологии и ответственность',detail:'Onest 700 · 28 px в данном web-примере',size:'28px',weight:700}),
 Object.freeze({id:'text',role:'Основной текст',sample:'Мы обсуждаем задачу, предлагаем подходящий формат работы и проверяем инженерный результат.',detail:'Onest 400 · 16 px в данном web-примере',size:'16px',weight:400}),
 Object.freeze({id:'caption',role:'Подпись и метаданные',sample:'IRLIX · пример цифрового фирменного стиля',detail:'Onest 400 · 13 px в данном web-примере',size:'13px',weight:400}),
]);
export const brandSurfaceExamples = Object.freeze([
 Object.freeze({id:'body',role:'Основной контент',background:'#FFFFFF',foreground:'#142820',sample:'Основной текст',context:'Контрастный основной текст на белом фоне.'}),
 Object.freeze({id:'muted',role:'Вторичная поверхность',background:'#F7FAF8',foreground:'#52685D',sample:'Вспомогательные подписи',context:'Мягкая подложка, без потери читаемости.'}),
 Object.freeze({id:'action',role:'Акцентное действие',background:'#087C5A',foreground:'#FFFFFF',sample:'Обсудить задачу',context:'Акцент для ясного действия, а не для заливки всего макета.'}),
]);
export const brandGraphicExamples = Object.freeze([
 Object.freeze({id:'hierarchy',title:'Текстовая иерархия',description:'Начинать с ясного тезиса, поддерживать его цифрами или проверенной схемой, оставлять пространство.'}),
 Object.freeze({id:'restraint',title:'Сдержанный акцент',description:'Использовать фирменный зелёный как ориентир и структуру, не как агрессивный декоративный эффект.'}),
 Object.freeze({id:'source',title:'Подтверждённый материал',description:'Если нет лицензии на фотографию или прав на кейс — использовать простую типографическую композицию.'}),
]);
export const colorPairAllowed = Object.freeze([
 Object.freeze({text:'#142820',background:'#FFFFFF',role:'Основной текст'}),
 Object.freeze({text:'#52685D',background:'#F7FAF8',role:'Вторичная информация'}),
 Object.freeze({text:'#FFFFFF',background:'#087C5A',role:'Текст на акценте'}),
]);
export function contrastRatio(foreground,background){
 function luminance(hex){
  if(!/^#[0-9A-Fa-f]{6}$/.test(hex))throw new TypeError('Expected a 6-digit hex color');
  const channels=[1,3,5].map(pos=>parseInt(hex.slice(pos,pos+2),16)/255);
  const linear=channels.map(v=>v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4);
  return linear[0]*0.2126+linear[1]*0.7152+linear[2]*0.0722;
 }
 const a=luminance(foreground),b=luminance(background);
 return (Math.max(a,b)+0.05)/(Math.min(a,b)+0.05);
}
