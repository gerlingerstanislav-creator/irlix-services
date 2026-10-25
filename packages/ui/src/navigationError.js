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
  page.style.cssText = 'max-width:640px;margin:12vh auto;padding:32px;font-family:inherit;line-height:1.5';
  const heading = document.createElement('h1');
  heading.textContent = `${code} — ${title}`;
  const paragraph = document.createElement('p');
  paragraph.textContent = description || fallback;
  const controls = document.createElement('div');
  controls.style.cssText = 'display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:24px';
  const back = document.createElement('a');
  back.href = '/';
  back.textContent = 'На дашборд';
  controls.appendChild(back);
  if (![401, 403, 404].includes(code)) {
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.textContent = 'Повторить';
    retry.addEventListener('click', () => window.location.reload());
    controls.appendChild(retry);
  }
  page.append(heading, paragraph, controls);
  root.replaceChildren(page);
}
