import { api } from './apiClient';

export const rolesService = {
  list: (params, options) => api.get('/api/v1/roles', { ...options, query: params }),
  get: (id, options) => api.get(`/api/v1/roles/${id}`, options).then((r) => r.data),
  create: (payload) => api.post('/api/v1/roles', payload),
  update: (id, payload) => api.put(`/api/v1/roles/${id}`, payload),
  setStatus: (id, isActive) => api.patch(`/api/v1/roles/${id}/status`, { is_active: isActive }),
  syncPermissions: (id, permissionIds) => api.put(`/api/v1/roles/${id}/permissions`, { permission_ids: permissionIds }),
};
