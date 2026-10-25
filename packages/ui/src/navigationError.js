/**
 * Client-side error state. HTTP status codes are still enforced by the server.
 * Use this for navigation/loading failures; mutation errors belong beside the form.
 */
export const navigationErrorMessages = Object.freeze({
  400: ['Некорректный запрос', 'Проверьте данные запроса.'],
  401: ['Требуется вход', 'Войдите в систему и повторите действие.'],
  403: ['Доступ запрещён', 'У вас нет прав для просмотра этого раздела.'],
  404: ['Страница не найдена', 'Проверьте адрес страницы.'],
  409: ['Конфликт изменений', 'Данные изменились. Обновите страницу перед повтором.'],
  422: ['Ошибка проверки данных', 'Проверьте заполненные поля.'],
  429: ['Слишком много запросов', 'Повторите попытку позднее.'],
  500: ['Ошибка сервера', 'Не удалось выполнить запрос.'],
  502: ['Сервис недоступен', 'Не удалось получить ответ сервиса.'],
  503: ['Сервис временно недоступен', 'Повторите попытку позднее.'],
  504: ['Превышено время ожидания', 'Сервис не ответил вовремя.'],
});
export function renderNavigationError(root, status, description = '') {
  if (!root) return;
  const code = Number(status);
  const [title, fallback] = navigationErrorMessages[code] || ['Ошибка', 'Не удалось открыть страницу.'];
  const page = document.createElement('main');
  page.setAttribute('role', 'alert');
  page.dataset.errorStatus = String(code);
  page.style.cssText = 'box-sizing:border-box;display:grid;place-items:center;width:100%;min-height:100dvh;margin:0 auto;padding:24px;font-family:var(--irlix-font-sans,inherit);color:var(--irlix-color-text,#172b22);text-align:center';
  const content = document.createElement('div');
  content.style.cssText = 'box-sizing:border-box;width:min(100%,500px);padding:clamp(24px,4vw,44px);border:1px solid var(--irlix-color-border,#dce5df);border-radius:var(--irlix-radius-lg,16px);background:var(--irlix-color-surface,#fff);box-shadow:var(--irlix-shadow-sm,0 8px 28px rgba(16,45,30,.05))';
  const eyebrow = document.createElement('div');
  eyebrow.textContent = 'IRLIX SERVICES · ОШИБКА';
  eyebrow.style.cssText = 'color:var(--irlix-color-primary-text,#087b59);font-size:11px;font-weight:700;letter-spacing:.13em';
  const number = document.createElement('div');
  number.textContent = String(code);
  number.style.cssText = 'margin-top:12px;color:var(--irlix-color-primary-text,#087b59);font-size:clamp(64px,9vw,100px);font-weight:750;line-height:1;letter-spacing:-.065em';
  const heading = document.createElement('h1');
  heading.textContent = title;
  heading.style.cssText = 'margin:18px 0 10px;font-size:clamp(21px,3vw,26px);line-height:1.25';
  const paragraph = document.createElement('p');
  paragraph.textContent = description || fallback;
  paragraph.style.cssText = 'max-width:36ch;margin:0 auto;color:var(--irlix-color-text-muted,#66776e);font-size:14px;line-height:1.65';
  const controls = document.createElement('div');
  controls.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:28px';
  const back = document.createElement('a');
  back.href = '/';
  back.textContent = 'На дашборд';
  back.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 18px;border:1px solid var(--irlix-color-primary,#159c70);border-radius:var(--irlix-control-radius,9px);background:var(--irlix-color-primary,#159c70);color:var(--irlix-color-on-primary,#fff);font-size:13px;font-weight:650;text-decoration:none';
  controls.appendChild(back);
  if (![401, 403, 404].includes(code)) {
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.textContent = 'Повторить';
    retry.style.cssText = 'min-height:40px;padding:9px 18px;border:1px solid var(--irlix-color-border-strong,#c8d6ce);border-radius:var(--irlix-control-radius,9px);background:var(--irlix-color-surface,#fff);color:var(--irlix-color-primary-text,#087b59);font:inherit;font-size:13px;font-weight:650;cursor:pointer';
    retry.addEventListener('click', () => window.location.reload());
    controls.appendChild(retry);
  }
  content.append(eyebrow, number, heading, paragraph, controls);
  page.append(content);
  root.replaceChildren(page);
}
