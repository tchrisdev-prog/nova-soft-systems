import { api, downloadFile } from './apiClient';

export const contractsService = {
  list: (params, options) => api.get('/api/v1/contracts', { ...options, query: params }),
  get: (id, options) => api.get(`/api/v1/contracts/${id}`, options).then((r) => r.data),
  create: (payload) => api.post('/api/v1/contracts', payload),
  update: (id, payload) => api.put(`/api/v1/contracts/${id}`, payload),
  setStatus: (id, status, note) => api.patch(`/api/v1/contracts/${id}/status`, { status, note: note || null }),
  remove: (id) => api.delete(`/api/v1/contracts/${id}`),
  history: (id, params, options) => api.get(`/api/v1/contracts/${id}/history`, { ...options, query: params }),
  movements: (id, options) => api.get(`/api/v1/contracts/${id}/hour-movements`, options),
  addMovement: (id, payload) => api.post(`/api/v1/contracts/${id}/hour-movements`, payload),
  consumption: (id, params, options) => api.get(`/api/v1/contracts/${id}/consumption`, { ...options, query: params }),
  exportCsv: (params) => downloadFile('/api/v1/contracts/export', { query: params, fallbackName: 'contratos.csv' }),
  projects: (contractId, options) => api.get('/api/v1/projects', { ...options, query: { contract_id: contractId, per_page: 50 } }),
};
