import { api } from './apiClient';

export const usersService = {
  list: (params, options) => api.get('/api/v1/users', { ...options, query: params }),
  authorize: (payload) => api.post('/api/v1/users', payload),
  setStatus: (id, isActive) => api.patch(`/api/v1/users/${id}/status`, { is_active: isActive }),
  syncRoles: (id, roleIds) => api.put(`/api/v1/users/${id}/roles`, { role_ids: roleIds }),
  remove: (id) => api.delete(`/api/v1/users/${id}`),
};
