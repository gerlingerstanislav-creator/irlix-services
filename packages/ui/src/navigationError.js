/**
 * Render a navigation error without redirecting users to a different service.
 * This is a client-side view, not an HTTP status replacement or an auth check.
 */
export function renderNavigationError(root, status, description = '') {
  if (!root) return;
  const messages = {
    403: ['Доступ запрещён', 'У вас нет прав для просмотра этого раздела.'],
    404: ['Страница не найдена', 'Проверьте адрес страницы.'],
  };
  const [title, fallback] = messages[status] || ['Ошибка', 'Не удалось открыть страницу.'];
  const page = document.createElement('main');
  page.setAttribute('role', 'alert');
  page.dataset.errorStatus = String(status);
  page.style.cssText = 'max-width:640px;margin:12vh auto;padding:32px;font-family:inherit';
  const heading = document.createElement('h1');
  heading.textContent = `${status} — ${title}`;
  const paragraph = document.createElement('p');
  paragraph.textContent = description || fallback;
  const back = document.createElement('a');
  back.href = '/';
  back.textContent = 'На дашборд';
  page.append(heading, paragraph, back);
  root.replaceChildren(page);
}
