import { auth } from './auth';

export async function api(url, options = {}) {
  const body = options.body;
  const headers = { Accept: 'application/json', ...(options.headers || {}) };
  let payloadBody = body;

  if (body && !(body instanceof FormData) && typeof body !== 'string') {
    headers['Content-Type'] = 'application/json';
    payloadBody = JSON.stringify(body);
  } else if (typeof body === 'string' && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await auth.fetch(url, { ...options, body: payloadBody, headers });
  if (options.raw) return response;

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const validation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
    throw new Error(validation || payload.message || `HTTP ${response.status}`);
  }
  return payload;
}

export async function downloadFile(url, suggestedName = 'document') {
  const response = await auth.fetch(url, { headers: { Accept: '*/*' } });
  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throw new Error(payload.message || `HTTP ${response.status}`);
  }
  const blob = await response.blob();
  const objectUrl = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = objectUrl;
  link.download = suggestedName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(objectUrl);
}
