import { api } from './apiClient';

export const capacityService = {
  weekly: (params, options) => api.get('/api/v1/capacity', { ...options, query: params }).then((r) => r.data),
  available: (params, options) => api.get('/api/v1/capacity/available', { ...options, query: params }).then((r) => r.data),
};

export const staffService = {
  list: (params, options) => api.get('/api/v1/staff', { ...options, query: params }),
  save: (userId, payload) => api.put(`/api/v1/staff/${userId}`, payload),
};
