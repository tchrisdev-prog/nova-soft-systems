import { api } from './apiClient';

export const clientsService = {
  list: (params, options) => api.get('/api/v1/clients', { ...options, query: params }),
  get: (id, options) => api.get(`/api/v1/clients/${id}`, options).then((r) => r.data),
  create: (payload) => api.post('/api/v1/clients', payload),
  update: (id, payload) => api.put(`/api/v1/clients/${id}`, payload),
  remove: (id) => api.delete(`/api/v1/clients/${id}`),
  addContact: (clientId, payload) => api.post(`/api/v1/clients/${clientId}/contacts`, payload),
  updateContact: (clientId, contactId, payload) => api.put(`/api/v1/clients/${clientId}/contacts/${contactId}`, payload),
  removeContact: (clientId, contactId) => api.delete(`/api/v1/clients/${clientId}/contacts/${contactId}`),
};
