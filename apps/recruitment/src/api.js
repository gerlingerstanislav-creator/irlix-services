const base = '/api/recruitment';

async function request(path, options = {}) {
  const response = await fetch(`${base}${path}`, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    ...options,
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`);
  return payload.data ?? payload;
}

export const recruitmentApi = {
  workspace: () => request('/workspace'),
  candidate: (id) => request(`/candidates/${id}`),
  createCandidate: (data) => request('/candidates', { method: 'POST', body: JSON.stringify(data) }),
  createRequest: (data) => request('/requests', { method: 'POST', body: JSON.stringify(data) }),
  addActivity: (candidateId, data) => request(`/candidates/${candidateId}/activities`, { method: 'POST', body: JSON.stringify(data) }),
  moveStage: (processId, data) => request(`/hiring-processes/${processId}/stage`, { method: 'POST', body: JSON.stringify(data) }),
  createInterview: (data) => request('/interviews', { method: 'POST', body: JSON.stringify(data) }),
  addEvaluation: (interviewId, data) => request(`/interviews/${interviewId}/evaluations`, { method: 'POST', body: JSON.stringify(data) }),
  createOffer: (data) => request('/offers', { method: 'POST', body: JSON.stringify(data) }),
  updateOffer: (id, data) => request(`/offers/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  createPool: (data) => request('/talent-pools', { method: 'POST', body: JSON.stringify(data) }),
  addToPool: (poolId, candidateId) => request(`/talent-pools/${poolId}/candidates`, { method: 'POST', body: JSON.stringify({ candidate_id: candidateId }) }),
  createEmployment: (data) => request('/employment-requests', { method: 'POST', body: JSON.stringify(data) }),
  updateEmployment: (id, data) => request(`/employment-requests/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  updateOnboarding: (id, data) => request(`/onboarding/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  createTask: (data) => request('/tasks', { method: 'POST', body: JSON.stringify(data) }),
  completeTask: (id) => request(`/tasks/${id}/complete`, { method: 'POST' }),
};
