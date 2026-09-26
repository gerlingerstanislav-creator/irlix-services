export const api = async (url, options = {}) => {
  const response = await fetch(url, {
    ...options,
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    body: options.body && typeof options.body !== 'string' ? JSON.stringify(options.body) : options.body,
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const firstValidation = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
    throw new Error(firstValidation || payload?.message || `HTTP ${response.status}`);
  }
  return payload;
};
