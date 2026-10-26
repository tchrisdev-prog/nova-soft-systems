import { api } from './apiClient';

export const authService = {
  config: () => api.get('/api/v1/auth/config').then((r) => r.data),
  me: (options) => api.get('/api/v1/me', options).then((r) => r.data),
  logout: () => api.post('/api/v1/auth/logout'),
};
