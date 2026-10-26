import { api } from './apiClient';

// Proyectos, equipo y horas por semana. Cada operación se vuelve a
// autorizar en la API: aquí no se decide quién puede hacer qué.
export const projectsService = {
  list: (params, options) => api.get('/api/v1/projects', { ...options, query: params }),
  get: (id, options) => api.get(`/api/v1/projects/${id}`, options).then((r) => r.data),
  create: (payload) => api.post('/api/v1/projects', payload),
  update: (id, payload) => api.put(`/api/v1/projects/${id}`, payload),
  setStatus: (id, status, note) => api.patch(`/api/v1/projects/${id}/status`, { status, note: note || null }),
  setLeader: (id, leaderId) => api.patch(`/api/v1/projects/${id}/leader`, { leader_id: leaderId }),
  remove: (id) => api.delete(`/api/v1/projects/${id}`),
  leaders: (options) => api.get('/api/v1/projects/leaders', options).then((r) => r.data),
  hours: (id, weeks, options) => api.get(`/api/v1/projects/${id}/hours`, { ...options, query: { weeks } }).then((r) => r.data),

  allocations: (id, options) => api.get(`/api/v1/projects/${id}/allocations`, options).then((r) => r.data),
  addAllocation: (id, payload) => api.post(`/api/v1/projects/${id}/allocations`, payload),
  updateAllocation: (id, allocationId, payload) => api.patch(`/api/v1/projects/${id}/allocations/${allocationId}`, payload),
  endAllocation: (id, allocationId, endDate) => api.post(`/api/v1/projects/${id}/allocations/${allocationId}/end`, { end_date: endDate || null }),
  removeAllocation: (id, allocationId) => api.delete(`/api/v1/projects/${id}/allocations/${allocationId}`),
};
