export const api = async (url, options = {}) => {
  const response = await fetch(url, {
    ...options,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) },
    body: options.body && typeof options.body !== 'string' ? JSON.stringify(options.body) : options.body,
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const firstValidation = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
    const fallback = response.status === 423
      ? 'Изменение таймшита заблокировано. Проверьте подтверждение и статус отчётного периода в Clients.'
      : `HTTP ${response.status}`;
    throw new Error(firstValidation || payload?.message || fallback);
  }
  return payload;
};
